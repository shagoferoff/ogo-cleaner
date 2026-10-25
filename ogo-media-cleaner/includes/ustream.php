<?php
/** Remove only identified Ustream players and their standard credit links. */
final class OGO_MC_Ustream {
    private const TAG='<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private static function attr($tag,$name) {
        if(!preg_match('~^<(?:iframe|a)(?=[\s/>])~i',$tag,$m))return null;
        preg_match_all('~([^\s=<>/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?~',substr($tag,strlen($m[0]),-1),$attrs,PREG_SET_ORDER|PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL);
        $values=array();
        foreach($attrs as $a)if(strtolower($a[1][0])===$name){
            $v=null;foreach(array(2,3,4) as $i)if(isset($a[$i][0])){$v=$a[$i][0];break;}
            if($v===null)return null;$values[]=trim(html_entity_decode($v,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        }
        if(count($values)!==1)return null;
        $url=$values[0];if(substr($url,0,2)==='//')$url='https:'.$url;
        return parse_url($url);
    }
    private static function target($parts) {
        return is_array($parts) && in_array(strtolower($parts['scheme']??''),array('http','https'),true) && in_array(strtolower($parts['host']??''),array('ustream.tv','www.ustream.tv'),true) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']);
    }
    private static function blank($s) {return preg_match('/^[\s\x{00a0}]*$/u',html_entity_decode($s,ENT_QUOTES|ENT_HTML5,'UTF-8'))===1;}
    private static function credit($inside) {
        preg_match_all('~'.self::TAG.'~',$inside,$tags);$stack=array();
        foreach($tags[0] as $tag){
            if(!preg_match('~^<(/?)(b|strong|span|em|i|small|u)(?=[\s/>])~i',$tag,$m))return false;
            $name=strtolower($m[2]);
            if($m[1]){if(array_pop($stack)!==$name)return false;}else{if(preg_match('~/\s*>$~',$tag))return false;$stack[]=$name;}
        }
        if($stack || strpos($inside,'<!--')!==false)return false;
        $label=trim(preg_replace('/[\s\x{00a0}]+/u',' ',html_entity_decode(preg_replace('~'.self::TAG.'~','',$inside),ENT_QUOTES|ENT_HTML5,'UTF-8')));
        return in_array(strtolower($label),array('live streaming video by ustream','video streaming by ustream'),true);
    }
    private static function empty_markup($s) {
        if(strpos($s,'<!--')!==false)return false;
        $stack=array();preg_match_all('~'.self::TAG.'~',$s,$tags);
        foreach($tags[0] as $tag){
            if(!preg_match('~^<(/?)(p|b|strong|span|em|i|small|u|br)(?=[\s/>])~i',$tag,$m))return false;
            $name=strtolower($m[2]);if($name==='br'&&!$m[1])continue;
            if($m[1]){if(array_pop($stack)!==$name)return false;}else{if(preg_match('~/\s*>$~',$tag))return false;$stack[]=$name;}
        }
        return !$stack && self::blank(preg_replace('~'.self::TAG.'~','',$s));
    }
    private static function remaining($content,$start,$end,$ranges) {
        $pieces=array();$found=false;
        foreach($ranges as $r){if($r[0]>=$start && $r[1]<=$end){$pieces[]=$r;$found=true;}elseif($r[0]<$end && $r[1]>$start)return null;}
        if(!$found)return null;$s=substr($content,$start,$end-$start);
        usort($pieces,function($a,$b){return $b[0]<=>$a[0];});
        foreach($pieces as $p)$s=substr_replace($s,'',$p[0]-$start,$p[1]-$p[0]);
        return $s;
    }
    public static function analyze($content,$unused) {
        $masked=preg_replace_callback('~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?(?:</\1\s*>|$)~i',function($m){return str_repeat(' ',strlen($m[0]));},$content);
        preg_match_all('~'.self::TAG.'~',$masked,$tokens,PREG_OFFSET_CAPTURE);
        $stack=array();$ranges=array();$total=0;$iframes=0;$credits=0;$reasons=array();
        for($i=0;$i<count($tokens[0]);++$i){
            $t=$tokens[0][$i];$tag=substr($content,$t[1],strlen($t[0]));
            if(preg_match('~^<iframe(?=[\s/>])~i',$tag)){
                $parts=self::attr($tag,'src');$target=self::target($parts);if($target)++$total;
                $closing=null;
                for($j=$i+1;$j<count($tokens[0]);++$j)if(preg_match('~^</iframe\s*>$~i',$tokens[0][$j][0])){$closing=$j;break;}
                if($closing===null){if($target)$reasons['незакритий iframe']=($reasons['незакритий iframe']??0)+1;break;}
                if($target){
                    $end=$tokens[0][$closing][1]+strlen($tokens[0][$closing][0]);
                    $inside=substr($content,$t[1]+strlen($tag),$tokens[0][$closing][1]-$t[1]-strlen($tag));
                    if(!preg_match('~/\s*>$~',$tag) && preg_match('~^/embed/(?:recorded/)?[1-9]\d*/?$~i',$parts['path']??'') && self::blank($inside)){$ranges[]=array($t[1],$end,'iframe');++$iframes;}
                    else $reasons['невпізнаний iframe або непорожній вміст']=($reasons['невпізнаний iframe або непорожній вміст']??0)+1;
                }
                // Iframe fallback content is raw text, not article links.
                $i=$closing;continue;
            }
            if(preg_match('~^<a(?=[\s/>])~i',$tag)){
                $parts=self::attr($tag,'href');$target=self::target($parts);if($target)++$total;
                $invalid=!empty($stack)||preg_match('~/\s*>$~',$tag);
                if($stack)foreach($stack as &$open)$open['invalid']=true;
                unset($open);
                $stack[]=array('start'=>$t[1],'length'=>strlen($tag),'parts'=>$parts,'target'=>$target,'invalid'=>$invalid);
            }elseif(preg_match('~^</a\s*>$~i',$tag)&&$stack){
                $open=array_pop($stack);if(!$open['target'])continue;
                $inside=substr($content,$open['start']+$open['length'],$t[1]-$open['start']-$open['length']);
                if(!$open['invalid'] && in_array($open['parts']['path']??'',array('','/'),true) && !isset($open['parts']['query']) && !isset($open['parts']['fragment']) && self::credit($inside)){$ranges[]=array($open['start'],$t[1]+strlen($tag),'credit');++$credits;}
                else $reasons['нестандартне посилання Ustream']=($reasons['нестандартне посилання Ustream']??0)+1;
            }
        }
        foreach($stack as $open)if($open['target'])$reasons['незакрите посилання']=($reasons['незакрите посилання']??0)+1;
        // Remove the line breaks that connected a deleted player to its deleted credit.
        usort($ranges,function($a,$b){return $a[0]<=>$b[0];});$bridges=array();
        for($i=1;$i<count($ranges);++$i){$left=$ranges[$i-1];$right=$ranges[$i];if($left[2]!=='iframe'||$right[2]!=='credit')continue;
            $gap=substr($content,$left[1],$right[0]-$left[1]);
            if(preg_match('~^(?:\s|&nbsp;|<br\s*/?>)*$~i',$gap))$bridges[]=array($left[1],$right[0],'bridge');
        }
        $ranges=array_merge($ranges,$bridges);$containers=0;$wrappers=array();
        // Only containers made empty by this operation; existing empty blocks stay.
        preg_match_all('~<!-- wp:(html|paragraph)(?:\s+\{[^\r\n]*\})?\s*-->([\s\S]*?)<!-- /wp:\1\s*-->~i',$content,$blocks,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        foreach($blocks as $b){$s=self::remaining($content,$b[2][1],$b[2][1]+strlen($b[2][0]),$ranges);if($s!==null&&self::empty_markup($s)){$wrappers[]=array($b[0][1],$b[0][1]+strlen($b[0][0]),'block');++$containers;}}
        preg_match_all('~<p\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>([\s\S]*?)</p\s*>~i',$content,$paragraphs,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        foreach($paragraphs as $p){$covered=false;foreach($wrappers as $w)if($p[0][1]>=$w[0]&&$p[0][1]+strlen($p[0][0])<=$w[1])$covered=true;if($covered)continue;
            $s=self::remaining($content,$p[1][1],$p[1][1]+strlen($p[1][0]),$ranges);if($s!==null&&self::empty_markup($s)){$wrappers[]=array($p[0][1],$p[0][1]+strlen($p[0][0]),'paragraph');++$containers;}}
        $ranges=array_merge($ranges,$wrappers);usort($ranges,function($a,$b){return $a[0]<=>$b[0];});$merged=array();
        foreach($ranges as $r){if($r[1]<=$r[0])continue;$last=count($merged)-1;if($last>=0&&$r[0]<=$merged[$last][1])$merged[$last][1]=max($merged[$last][1],$r[1]);else$merged[]=array($r[0],$r[1]);}
        $after=$content;foreach(array_reverse($merged) as $r)$after=substr_replace($after,'',$r[0],$r[1]-$r[0]);
        $summary='Видалено плеєрів Ustream: '.$iframes.'; службових підписів: '.$credits.'; порожніх абзаців/блоків: '.$containers.'.';
        foreach($reasons as $reason=>$count)$summary.=' Перевірити: '.$reason.' — '.$count.'.';
        return array('after'=>$after,'total'=>$total,'removed'=>$iframes+$credits,'review'=>$total-$iframes-$credits,'fragments'=>array(),'summary'=>$summary);
    }
}
