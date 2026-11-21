<?php
/** Edit anchor tags by byte offset, preserving the surrounding article HTML. */
final class OGO_MC_Source_Links {
    // The audit groups by exact host (including its www variant) and rel state.
    private const FIRST_BATCH=array(
        'pogoda.rovno.ua'=>false, 'pravda.com.ua'=>false, 'unn.com.ua'=>false,
        'moemisto.ua'=>false, 'rivne1.tv'=>false, 'ukrinform.ua'=>false,
        'radiosvoboda.org'=>false, 'rivnepost.rv.ua'=>false, 'bit.ly'=>false,
        '4vlada.com'=>false, 'gazeta.ua'=>false, 'news.liga.net'=>false,
        'zik.ua'=>false, 'epravda.com.ua'=>false, 'rv.gov.ua'=>false,
        'slovoidilo.ua'=>false, 'charivne.info'=>false, '24tv.com.ua'=>false,
        '24tv.ua'=>false, 'prm.ua'=>false, 'zaxid.net'=>false,
        'roe.vsei.ua'=>true, 'president.gov.ua'=>false, 'suspilne.media'=>true,
        'espreso.tv'=>false, 'life.pravda.com.ua'=>false, 'rtb.rv.ua'=>false,
        'zik.com.ua'=>false, 'docs.google.com'=>false, '5.ua'=>false, '7d.rv.ua'=>false,
    );
    private static function matches_host($tag,$domain) {
        $info=OGO_MC_Engine::href_info($tag);
        $parts=$info['parts']??null;
        if (!is_array($parts) || !in_array(strtolower($parts['scheme']??''),array('http','https'),true) || isset($parts['user']) || isset($parts['pass'])) return false;
        $host=strtolower($parts['host']??'');
        if (substr($host,-1)==='.') $host=substr($host,0,-1);
        $suffix='.'. $domain;
        return $host===$domain || (strlen($host)>strlen($suffix) && substr($host,-strlen($suffix))===$suffix);
    }

    private static function update_attributes($tag) {
        $attributes=array('target'=>array(),'rel'=>array());
        $rel=array('nofollow'=>'nofollow','noopener'=>'noopener');
        foreach (OGO_MC_Engine::anchor_attributes($tag) as $attribute) {
            $name=strtolower($attribute[1][0]);
            if (!isset($attributes[$name])) continue;
            $attributes[$name][]=$attribute[0];
            if ($name!=='rel') continue;
            $value=$attribute[2][0]??$attribute[3][0]??$attribute[4][0]??'';
            $decoded=html_entity_decode($value,ENT_QUOTES | ENT_HTML5,'UTF-8');
            foreach (preg_split('~[\x09\x0a\x0c\x0d\x20]+~',$decoded,-1,PREG_SPLIT_NO_EMPTY) as $token) {
                $key=strtolower($token);
                if (!isset($rel[$key])) $rel[$key]=$token;
            }
        }

        $desired=array(
            'target'=>'target="_blank"',
            'rel'=>'rel="'.htmlspecialchars(implode(' ',$rel),ENT_QUOTES | ENT_HTML5,'UTF-8').'"',
        );
        $edits=array();
        $insert='';
        foreach ($desired as $name=>$replacement) {
            if (!$attributes[$name]) { $insert.=' '.$replacement; continue; }
            foreach ($attributes[$name] as $index=>$attribute) {
                $start=2+$attribute[1];
                $length=strlen($attribute[0]);
                if ($index!==0) {
                    $leading=substr($tag,0,$start);
                    $whitespace=$start-strlen(rtrim($leading," \t\n\r\f"));
                    $start-=$whitespace;
                    $length+=$whitespace;
                }
                $edits[]=array($start,$length,$index===0?$replacement:'');
            }
        }
        if ($insert!=='') $edits[]=array(strlen($tag)-1,0,$insert);
        return self::apply_edits($tag,$edits);
    }

    private static function apply_edits($content,$edits) {
        usort($edits,function($a,$b){return $b[0]<=>$a[0];});
        foreach ($edits as $edit) $content=substr_replace($content,$edit[2],$edit[0],$edit[1]);
        return $content;
    }

    public static function analyze($content,$domain,$update_attributes) {
        return self::edit_links($content,function($tag) use ($domain){return self::matches_host($tag,$domain);},$update_attributes);
    }

    public static function first_batch($content) {
        require_once __DIR__.'/external-engine.php';
        require_once __DIR__.'/site-links-engine.php';
        if (stripos($content,'<a')===false || OGO_MC_Site_Links_Engine::ad_marker($content)) {
            return array('after'=>$content,'total'=>0,'removed'=>0,'review'=>0,'fragments'=>array(),'summary'=>'');
        }
        return self::edit_links($content,function($tag) {
            $href=OGO_MC_Engine::href_info($tag);
            $link=$href?OGO_MC_External_Engine::classify($href['raw']):null;
            if (!$link || $link['kind']==='review') return false;
            $domain=OGO_MC_Site_Links_Engine::domain($link['domain']);
            if (!array_key_exists($domain,self::FIRST_BATCH)) return false;
            $attributes=OGO_MC_External_Engine::link_attributes($tag);
            $rel=explode(' ',$attributes['rel']);
            return !in_array('sponsored',$rel,true) && OGO_MC_Site_Links_Engine::is_closed($rel)===self::FIRST_BATCH[$domain];
        },true,true);
    }

    public static function miralinks($content,$targets) {
        require_once __DIR__.'/external-engine.php';
        $counts=array_column($targets,'link_count','variant_key');
        $hrefs=array_fill_keys(array_column($targets,'raw_url'),true);
        $review=0;
        foreach (OGO_MC_External_Engine::extract($content) as $link) {
            $key=$link['variant_key'];
            if (isset($counts[$key]) && $link['link_count']>(int)$counts[$key]) {
                unset($counts[$key]);
                ++$review;
            }
        }
        $result=self::edit_links($content,function($tag) use ($hrefs) {
            $href=OGO_MC_Engine::href_info($tag);
            return $href && isset($hrefs[$href['raw']]);
        },false,true,function($tag,$text) use ($counts) {
            $href=OGO_MC_Engine::href_info($tag);
            $key=OGO_MC_External_Engine::variant_key($href['raw'],OGO_MC_External_Engine::label($text));
            return isset($counts[$key]);
        });
        $result['total']+=$review;
        $result['review']+=$review;
        if ($review) $result['summary'].=' Кількість однакових посилань збільшилася після ревізії. Потрібна ручна перевірка.';
        return $result;
    }

    private static function edit_links($content,$matches,$update_attributes,$protect_embeds=false,$verify=null) {
        $stack=array();
        $edits=array();
        $changed=0;
        $review=0;
        foreach (OGO_MC_Engine::html_tokens($content,$protect_embeds) as list($tag,$offset)) {
            if (preg_match('~^<a(?=[\s/>])~i',$tag)) {
                $target=$matches($tag);
                $nested=!empty($stack);
                foreach ($stack as &$open) $open['nested']=true;
                unset($open);
                if (OGO_MC_Engine::self_closing_anchor($tag)) {
                    if ($target) ++$review;
                    continue;
                }
                $stack[]=array('tag'=>$tag,'offset'=>$offset,'target'=>$target,'nested'=>$nested);
            } elseif ($stack && preg_match('~^</a\s*>$~i',$tag)) {
                $open=array_pop($stack);
                if (!$open['target']) continue;
                if ($open['nested']) { ++$review; continue; }
                $text_start=$open['offset']+strlen($open['tag']);
                if ($verify && !$verify($open['tag'],substr($content,$text_start,$offset-$text_start))) continue;
                if ($update_attributes) {
                    $replacement=self::update_attributes($open['tag']);
                    if ($replacement===$open['tag']) continue;
                    $edits[]=array($open['offset'],strlen($open['tag']),$replacement);
                } else {
                    $edits[]=array($open['offset'],strlen($open['tag']),'');
                    $edits[]=array($offset,strlen($tag),'');
                }
                ++$changed;
            } elseif ($protect_embeds && $stack && preg_match('~^<(?:iframe|object|video|audio|template)(?=[\s/>])|^<!--\s*wp:(?:(?:core/)?embed|core-embed/)|^\[embed\b~i',$tag)) {
                foreach ($stack as &$open) $open['target']=false;
                unset($open);
            }
        }
        foreach ($stack as $open) if ($open['target']) ++$review;
        return array(
            'after'=>self::apply_edits($content,$edits),
            'total'=>$changed+$review,
            'removed'=>$changed,
            'review'=>$review,
            'fragments'=>array(),
            'summary'=>$review?'Вкладені або незакриті посилання потребують ручної перевірки.':'',
        );
    }
}
