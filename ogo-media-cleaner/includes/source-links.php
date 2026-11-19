<?php
/** Edit anchor tags by byte offset, preserving the surrounding article HTML. */
final class OGO_MC_Source_Links {
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
        $stack=array();
        $edits=array();
        $changed=0;
        $review=0;
        foreach (OGO_MC_Engine::html_tokens($content) as list($tag,$offset)) {
            if (preg_match('~^<a(?=[\s/>])~i',$tag)) {
                $target=self::matches_host($tag,$domain);
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
                if ($update_attributes) {
                    $replacement=self::update_attributes($open['tag']);
                    if ($replacement===$open['tag']) continue;
                    $edits[]=array($open['offset'],strlen($open['tag']),$replacement);
                } else {
                    $edits[]=array($open['offset'],strlen($open['tag']),'');
                    $edits[]=array($offset,strlen($tag),'');
                }
                ++$changed;
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
