<?php
/** Remove confirmed Flash elements without reserializing article HTML. */
final class OGO_MC_Flash {
    private static function attributes($tag,$name) {
        $values=array();
        foreach (OGO_MC_Engine::tag_attributes($tag,$name) as $attribute) {
            $key=strtolower($attribute[1][0]);
            // Follow HTML's first-attribute rule.
            if (array_key_exists($key,$values)) continue;
            $value=$attribute[2][0]??$attribute[3][0]??$attribute[4][0]??'';
            $values[$key]=trim(html_entity_decode($value,ENT_QUOTES | ENT_HTML5,'UTF-8'));
        }
        return $values;
    }

    private static function file_is($url,$extension) {
        $parts=parse_url($url);
        return is_array($parts) && in_array(strtolower($parts['scheme']??''),array('','http','https'),true)
            && preg_match('~'.preg_quote($extension,'~').'$~iD',rawurldecode($parts['path']??''))===1;
    }

    private static function flash_type($attrs) {
        return in_array(strtolower(trim(explode(';',$attrs['type']??'')[0])),array('application/x-shockwave-flash','application/futuresplash'),true);
    }

    private static function blank($text) {
        return preg_match('/^[\s\p{Z}]*$/u',html_entity_decode($text,ENT_QUOTES | ENT_HTML5,'UTF-8'))===1;
    }

    /** Player metadata may be discarded with the player. Any fallback stays for review. */
    private static function player_only($content,$offset,$end,$children) {
        foreach ($children as list($start,$stop)) {
            if (!self::blank(substr($content,$offset,$start-$offset))) return false;
            $offset=$stop;
        }
        return self::blank(substr($content,$offset,$end-$offset));
    }

    private static function finish($start,$end,$confirmed,$safe,&$objects,&$ranges,&$review) {
        if (!$confirmed) return;
        if ($objects) {
            $parent=array_key_last($objects);
            $objects[$parent]['flash']=true;
            if ($safe) $objects[$parent]['children'][]=array($start,$end);
        } elseif ($safe) {
            $ranges[]=array($start,$end);
        } else {
            ++$review;
        }
    }

    public static function analyze($content) {
        $ranges=array();
        $objects=array();
        $iframe=null;
        $review=0;
        $tokens=preg_match('~<(?:object|embed|iframe)(?=[\s/>])~i',$content)?OGO_MC_Engine::html_tokens($content):array();
        foreach ($tokens as list($tag,$start)) {
            if (!preg_match('~^<(/?)(object|param|embed|iframe)(?=[\s/>])~i',$tag,$match)) continue;
            $name=strtolower($match[2]);
            $end=$start+strlen($tag);

            if ($iframe!==null) {
                if ($name==='iframe' && $match[1]==='/') {
                    $safe=self::blank(substr($content,$iframe['end'],$start-$iframe['end']));
                    self::finish($iframe['start'],$end,$iframe['flash'],$safe,$objects,$ranges,$review);
                    $iframe=null;
                }
                continue;
            }

            if ($match[1]==='/') {
                if ($name!=='object' || !$objects) continue;
                $object=array_pop($objects);
                $safe=!$object['other_data'] && self::player_only($content,$object['end'],$start,$object['children']);
                self::finish($object['start'],$end,$object['flash'],$safe,$objects,$ranges,$review);
                continue;
            }

            $attrs=self::attributes($tag,$name);
            if ($name==='object') {
                $data=$attrs['data']??'';
                $swf=self::file_is($data,'.swf');
                $identified_type=self::flash_type($attrs);
                $flash=$swf || $identified_type
                    || strcasecmp($attrs['classid']??'','clsid:D27CDB6E-AE6D-11cf-96B8-444553540000')===0
                    || self::file_is($attrs['codebase']??'','swflash.cab');
                $objects[]=array('start'=>$start,'end'=>$end,'flash'=>$flash,
                    'other_data'=>($data!=='' && !$swf && !$identified_type),'children'=>array());
            } elseif ($name==='param') {
                if (!$objects) continue;
                $parent=array_key_last($objects);
                $objects[$parent]['children'][]=array($start,$end);
                if (in_array(strtolower($attrs['name']??''),array('movie','src'),true) && self::file_is($attrs['value']??'','.swf')) {
                    $objects[$parent]['flash']=true;
                }
            } elseif ($name==='iframe') {
                $iframe=array('start'=>$start,'end'=>$end,
                    'flash'=>!isset($attrs['srcdoc']) && self::file_is($attrs['src']??'','.swf'));
            } else {
                $flash=self::flash_type($attrs) || self::file_is($attrs['src']??'','.swf');
                if (preg_match('~\G[ \t\r\n]*</embed\s*>~i',$content,$closing,0,$end)) $end+=strlen($closing[0]);
                self::finish($start,$end,$flash,true,$objects,$ranges,$review);
            }
        }
        if ($iframe!==null) self::finish($iframe['start'],strlen($content),$iframe['flash'],false,$objects,$ranges,$review);
        foreach ($objects as $object) {
            if ($object['flash']) { ++$review; break; }
        }

        $after=$content;
        foreach (array_reverse($ranges) as list($start,$end)) $after=substr_replace($after,'',$start,$end-$start);
        $removed=count($ranges);
        $summary='Знайдено Flash-вставок для видалення: '.$removed.'.';
        if ($review) $summary.=' Складні або незакриті вставки потребують ручної перевірки: '.$review.'.';
        return array('after'=>$after,'total'=>$removed+$review,'removed'=>$removed,'review'=>$review,'fragments'=>array(),'summary'=>$summary);
    }
}
