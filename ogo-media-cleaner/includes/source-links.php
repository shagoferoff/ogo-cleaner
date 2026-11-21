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
    private const SECOND_BATCH=array(
        'diloverivne.com'=>false, 'dramteatr.com.ua'=>false, 'erve.ua'=>false,
        'opora.rv.ua'=>false, 'suspilne.media'=>false, 'dt.ua'=>false,
        'rivne-cerkva.rv.ua'=>false, 'zdolbunivcity.net'=>false, 'hh.ua'=>false,
        'rcwa.com.ua'=>false, 'delo.ua'=>false, 'volynnews.com'=>false,
        'automoto.ua'=>false, 'goo.gl'=>false, 'kommersant.ua'=>false,
        'uk.wikipedia.org'=>false, 'send.monobank.ua'=>false, 'cgm.rv.ua'=>false,
        'nbnews.com.ua'=>false, 'vesti.ua'=>false, 'zdolbuniv.com'=>false,
        'podrobnosti.ua'=>false, 'censor.net.ua'=>false, 'prosport.tsn.ua'=>true,
        'uz.gov.ua'=>false, 'volynpost.com'=>false, 'city-adm.rv.ua'=>false,
        'visnyk.lutsk.ua'=>false, 'kasa.in.ua'=>false, 'khomko.rv.ua'=>false,
        'kmu.gov.ua'=>false, 'meteo.gov.ua'=>false, 'roe.vsei.ua'=>false,
        'takeinfo.net'=>false, 'vse.rv.ua'=>false, 'forms.gle'=>true,
    );
    private const THIRD_BATCH=array(
        'telekritika.ua','20minut.ua','biz.liga.net','press-centr.com','fakty.ictv.ua','lb.ua','ukr.net','expres.ua',
        'news.finance.ua','ekvator.ua','ipress.ua','mvs.gov.ua','rvosvita.org.ua','hromadske.ua','news.ub.ua','osvita.ua',
        'ubr.ua','glavcom.ua','fchoscha.inf.ua','obozrevatel.com','champion.com.ua','galinfo.com.ua','opogode.ua','prostobank.ua',
        'rbc.ua','zn.ua','bbc.co.uk','meteoprog.ua','moz.gov.ua','ua.for-ua.com','nashigroshi.org','dpsu.gov.ua',
    );
    private const FOURTH_BATCH=array(
        'my.rv.ua','sites.google.com','ukr.lb.ua','1tv.com.ua','mon.gov.ua','privatbank.ua','risu.org.ua','tvi.ua',
        'cnaprv.gov.ua','forms.gle','futsal.sport.ua','ukranews.com','vidomosti-ua.com','eurointegration.com.ua','focus.ua',
        'oblrada.rv.ua','rivne.karabas.com','rv.npu.gov.ua','ve.com.ua','hromadske.tv','rp5.ua','te.20minut.ua','wz.lviv.ua',
        'dubno.com.ua','lvtest.org.ua','petition.president.gov.ua','rada.gov.ua','vgolos.com.ua','depo.ua','news.bigmir.net',
        'rivnerada.gov.ua','slide.com','testportal.gov.ua',
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

    public static function batch($content,$batch) {
        require_once __DIR__.'/external-engine.php';
        require_once __DIR__.'/site-links-engine.php';
        $review_embeds=$batch>=3;
        if ((!$review_embeds && stripos($content,'<a')===false) || OGO_MC_Site_Links_Engine::ad_marker($content,true)) {
            return array('after'=>$content,'total'=>0,'removed'=>0,'review'=>0,'fragments'=>array(),'summary'=>'');
        }
        $domains=array(1=>self::FIRST_BATCH,2=>self::SECOND_BATCH,3=>array_fill_keys(self::THIRD_BATCH,false),
            4=>array_fill_keys(self::FOURTH_BATCH,false))[$batch];
        $tokens=null;
        $embeds=0;
        if ($review_embeds) {
            require_once __DIR__.'/source-embeds.php';
            list($tokens,$embeds)=OGO_MC_Source_Embeds::inspect($content,array_keys($domains));
        }
        $result=self::edit_links($content,function($tag) use ($domains) {
            $href=OGO_MC_Engine::href_info($tag);
            $link=$href?OGO_MC_External_Engine::classify($href['raw']):null;
            if (!$link || $link['kind']==='review') return false;
            $domain=OGO_MC_Site_Links_Engine::domain($link['domain']);
            if (!array_key_exists($domain,$domains)) return false;
            $attributes=OGO_MC_External_Engine::link_attributes($tag);
            $rel=explode(' ',$attributes['rel']);
            return !in_array('sponsored',$rel,true) && OGO_MC_Site_Links_Engine::is_closed($rel)===$domains[$domain];
        },true,true,null,$tokens);
        return $review_embeds?self::add_embed_review($result,$embeds,'Вставки для ручної перевірки'):$result;
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

    public static function social($content,$provider,$domain) {
        $markers=$provider==='facebook'?'facebook|fb\.watch|fb[-:]':'t\.me|telegram|tgme_';
        if (!preg_match('~'.$markers.'|&#~i',$content)) {
            return array('after'=>$content,'total'=>0,'removed'=>0,'review'=>0,'fragments'=>array(),'summary'=>'');
        }
        require_once __DIR__.'/external-engine.php';
        require_once __DIR__.'/site-links-engine.php';
        require_once __DIR__.'/social-links.php';
        list($tokens,$embeds)=OGO_MC_Social_Links::inspect($content,$provider);
        $paid=OGO_MC_Site_Links_Engine::ad_marker($content);
        $result=self::edit_links($content,function($tag) use ($paid,$domain) {
            if ($paid) return false;
            $href=OGO_MC_Engine::href_info($tag);
            $link=$href?OGO_MC_External_Engine::classify($href['raw']):null;
            if (!$link || $link['kind']==='review' || OGO_MC_Site_Links_Engine::domain($link['domain'])!==$domain) return false;
            $attributes=OGO_MC_External_Engine::link_attributes($tag);
            return !OGO_MC_Site_Links_Engine::is_closed(explode(' ',$attributes['rel']));
        },true,true,null,$tokens);
        return self::add_embed_review($result,$embeds,'Вставки '.ucfirst($provider).' для ручної перевірки');
    }

    private static function add_embed_review($result,$embeds,$label) {
        $embeds+=$result['wrapped_embeds']??0;
        unset($result['wrapped_embeds']);
        $result['blocking_review']=$result['review'];
        $result['total']+=$embeds;
        $result['review']+=$embeds;
        if ($embeds) $result['summary'].=' '.$label.': '.$embeds.'.';
        return $result;
    }

    private static function edit_links($content,$matches,$update_attributes,$protect_embeds=false,$verify=null,$tokens=null) {
        $stack=array();
        $edits=array();
        $changed=0;
        $review=0;
        $wrapped_embeds=0;
        foreach ($tokens??OGO_MC_Engine::html_tokens($content,$protect_embeds) as $token) {
            list($tag,$offset)=$token;
            if (!empty($token[2])) {
                foreach ($stack as &$open) {
                    if ($open['target'] && $token[2]==='unmatched_embed') ++$wrapped_embeds;
                    $open['target']=false;
                }
                unset($open);
                continue;
            }
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
        $result=array(
            'after'=>self::apply_edits($content,$edits),
            'total'=>$changed+$review,
            'removed'=>$changed,
            'review'=>$review,
            'fragments'=>array(),
            'summary'=>$review?'Вкладені або незакриті посилання потребують ручної перевірки.':'',
        );
        if ($wrapped_embeds) $result['wrapped_embeds']=$wrapped_embeds;
        return $result;
    }
}
