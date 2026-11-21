<?php
/** Convert legacy YouTube players and remove other confirmed Flash elements. */
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

    private static function youtube($url) {
        $parts=parse_url(substr($url,0,2)==='//'?'https:'.$url:$url);
        $host=strtolower($parts['host']??'');
        if (!in_array($host,array('youtube.com','www.youtube.com','m.youtube.com','youtube-nocookie.com','www.youtube-nocookie.com','youtu.be'),true)
            || !in_array(strtolower($parts['scheme']??''),array('http','https'),true)
            || isset($parts['user']) || isset($parts['pass'])) return null;
        $path=$parts['path']??'';
        $id='';
        $pattern=$host==='youtu.be'?'~^/([A-Za-z0-9_-]{11})/?$~D':'~^/(?:v|e|embed)/([A-Za-z0-9_-]{11})(?:/?$|&)~D';
        if (preg_match($pattern,$path,$match)) $id=$match[1];
        if ($path==='/watch') {
            parse_str($parts['query']??'',$query);
            if (is_string($query['v']??null) && preg_match('~^[A-Za-z0-9_-]{11}$~D',$query['v'])) $id=$query['v'];
        }
        return array('id'=>$id,'legacy'=>preg_match('~^/(?:v|e)/~',$path)===1);
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

    private static function finish($player,$end,$safe,&$objects,&$result) {
        if (!$player['flash']) return;
        if ($objects) {
            $parent=array_key_last($objects);
            $objects[$parent]['flash']=true;
            $objects[$parent]['urls']=array_merge($objects[$parent]['urls'],$player['urls']);
            if ($safe) $objects[$parent]['children'][]=array($player['start'],$end);
            return;
        }
        $ids=array();
        $unknown_youtube=false;
        $sources=array();
        foreach (array_unique(array_filter($player['urls'])) as $url) {
            $youtube=self::youtube($url);
            if ($youtube!==null) {
                $id=$youtube['id'];
                if ($id==='') {
                    $unknown_youtube=true;
                    $sources[]='YouTube · ID не визначено';
                } else {
                    $ids[$id]=true;
                    $sources[]='YouTube · https://youtu.be/'.$id;
                }
            } else {
                $host=parse_url($url,PHP_URL_HOST);
                if (is_string($host) && $host!=='') $sources[]=strtolower($host);
                elseif (self::file_is($url,'.swf')) $sources[]='Локальний SWF';
            }
        }
        $result['sources']=array_merge($result['sources'],$sources?:array('Flash · джерело не визначено'));
        if (!$safe || $unknown_youtube || count($ids)>1) {
            ++$result['review'];
            return;
        }
        $replacement=$ids?'[embed]https://youtu.be/'.array_key_first($ids).'[/embed]':'';
        $result['edits'][]=array($player['start'],$end,$replacement);
        if ($ids) ++$result['replaced'];
    }

    public static function analyze($content) {
        $result=array('edits'=>array(),'sources'=>array(),'review'=>0,'replaced'=>0);
        $objects=array();
        $iframe=null;
        $tokens=preg_match('~<(?:object|embed|iframe)(?=[\s/>])~i',$content)?OGO_MC_Engine::html_tokens($content):array();
        foreach ($tokens as list($tag,$start)) {
            if (!preg_match('~^<(/?)(object|param|embed|iframe)(?=[\s/>])~i',$tag,$match)) continue;
            $name=strtolower($match[2]);
            $end=$start+strlen($tag);

            if ($iframe!==null) {
                if ($name==='iframe' && $match[1]==='/') {
                    $safe=self::blank(substr($content,$iframe['end'],$start-$iframe['end']));
                    self::finish($iframe,$end,$safe,$objects,$result);
                    $iframe=null;
                }
                continue;
            }

            if ($match[1]==='/') {
                if ($name!=='object' || !$objects) continue;
                $object=array_pop($objects);
                $safe=!$object['other_data'] && self::player_only($content,$object['end'],$start,$object['children']);
                self::finish($object,$end,$safe,$objects,$result);
                continue;
            }

            $attrs=self::attributes($tag,$name);
            if ($name==='object') {
                $data=$attrs['data']??'';
                $swf=self::file_is($data,'.swf');
                $identified_type=self::flash_type($attrs);
                $legacy_youtube=self::youtube($data)['legacy']??false;
                $flash=$swf || $identified_type || $legacy_youtube
                    || strcasecmp($attrs['classid']??'','clsid:D27CDB6E-AE6D-11cf-96B8-444553540000')===0
                    || self::file_is($attrs['codebase']??'','swflash.cab');
                $objects[]=array('start'=>$start,'end'=>$end,'flash'=>$flash,
                    'other_data'=>($data!=='' && !$swf && !$identified_type && !$legacy_youtube),'children'=>array(),'urls'=>array($data));
            } elseif ($name==='param') {
                if (!$objects) continue;
                $parent=array_key_last($objects);
                $objects[$parent]['children'][]=array($start,$end);
                if (in_array(strtolower($attrs['name']??''),array('movie','src'),true)) {
                    $value=$attrs['value']??'';
                    $objects[$parent]['urls'][]=$value;
                    if (self::file_is($value,'.swf') || (self::youtube($value)['legacy']??false)) $objects[$parent]['flash']=true;
                }
            } elseif ($name==='iframe') {
                $src=$attrs['src']??'';
                $iframe=array('start'=>$start,'end'=>$end,
                    'flash'=>!isset($attrs['srcdoc']) && (self::file_is($src,'.swf') || (self::youtube($src)['legacy']??false)),'urls'=>array($src));
            } else {
                $src=$attrs['src']??'';
                $flash=self::flash_type($attrs) || self::file_is($src,'.swf') || (self::youtube($src)['legacy']??false);
                if (preg_match('~\G[ \t\r\n]*</embed\s*>~i',$content,$closing,0,$end)) $end+=strlen($closing[0]);
                self::finish(array('start'=>$start,'flash'=>$flash,'urls'=>array($src)),$end,true,$objects,$result);
            }
        }
        if ($iframe!==null) self::finish($iframe,strlen($content),false,$objects,$result);
        while ($objects) {
            $object=array_pop($objects);
            self::finish($object,strlen($content),false,$objects,$result);
        }

        $after=$content;
        foreach (array_reverse($result['edits']) as list($start,$end,$replacement)) $after=substr_replace($after,$replacement,$start,$end-$start);
        $changed=count($result['edits']);
        $review=$result['review'];
        $summary='Відновлено YouTube: '.$result['replaced'].'. Інших Flash-вставок для видалення: '.($changed-$result['replaced']).'.';
        if ($review) $summary.=' Неоднозначні або пошкоджені вставки потребують ручної перевірки: '.$review.'.';
        return array('after'=>$after,'total'=>$changed+$review,'removed'=>$changed,'review'=>$review,'fragments'=>array(),
            'summary'=>$summary,'sources'=>array_values(array_unique($result['sources'])));
    }
}
