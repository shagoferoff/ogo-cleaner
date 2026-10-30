<?php
/** Newspaper links in prose and standalone editorial callouts. Source offsets only. */
final class OGO_MC_Gazeta_Links {
    private const TAG='<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private static function normalized($html) {
        $s=html_entity_decode(preg_replace('~'.self::TAG.'~s','',$html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $s=strtr($s,array('«'=>'"','»'=>'"','“'=>'"','”'=>'"','„'=>'"','’'=>"'",'—'=>'-','–'=>'-'));
        return trim(preg_replace('/[\s\p{Z}]+/u',' ',$s));
    }
    private static function simple($html,$blocks=false) {
        if(strpos($html,'<!--')!==false || strpos(self::normalized($html),'[')!==false)return false;
        preg_match_all('~'.self::TAG.'~s',$html,$tags);$stack=array();
        foreach($tags[0] as $tag) {
            if(!preg_match('~^<(/?)([a-z][a-z0-9]*)\b~i',$tag,$m))return false;
            $name=strtolower($m[2]);
            if(!in_array($name,array('a','strong','b','em','i','span','font','u','s','small','br'),true) && !($blocks && in_array($name,array('p','div'),true)))return false;
            if(preg_match('~\s(?:id|name)\s*=~i',$tag))return false;
            if($name==='br' && !$m[1])continue;
            if($m[1]){if(array_pop($stack)!==$name)return false;}else $stack[]=$name;
        }
        return !$stack;
    }
    private static function callout($html) {
        if(!self::simple($html))return false;
        preg_match_all('~<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i',$html,$anchors);
        if(!$anchors[0])return false;
        foreach($anchors[0] as $a)if(!OGO_MC_Engine::target($a,'gazeta'))return false;
        $s=self::normalized($html);
        // Source attributions always retain their visible wording.
        if(preg_match('/^(?:За матеріалами|Джерело\s*:)/iu',$s))return false;
        static $approved=null;
        if($approved===null)$approved=require __DIR__.'/gazeta-callouts.php';
        foreach($approved as $phrase)if(preg_match('~^'.preg_quote(self::normalized($phrase),'~').'[.!]?\s*$~iu',$s))return true;
        // Repeated editorial prompts, independent of URL, ID, quotes and capitalization.
        if(preg_match('/^(?:Продовження читайте|Читайте (?:далі|продовження)|Читати більше)\s+(?:на\s+)?(?:сайті\s+)?"?Рівненської газети"?[.!]?$/iu',$s))return true;
        if(preg_match('/^(?:Більше|Детальніше) про [^.!?]+ читайте (?:на сайті|у матеріалі) [^.!?]+[.!]?$/iu',$s))return true;
        // Retain the previous rule for a standalone paragraph consisting only of links.
        $outside=preg_replace('~<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?</a\s*>~i','',$html);
        return self::normalized($outside)==='';
    }
    private static function segment($content,$nodes,$node) {
        $parent=$node['parent'];
        if($parent!==null && !in_array($nodes[$parent]['name'],array('p','div'),true))return null;
        $left=$parent===null?0:$nodes[$parent]['open_end'];
        $right=$parent===null?strlen($content):$nodes[$parent]['close'];
        if($right===null)return null;
        $before=substr($content,$left,$node['start']-$left);
        $after=substr($content,$node['end'],$right-$node['end']);
        $boundary='~<br\s*/?>|</?(?:p|div|h[1-6]|ul|ol|li|table|blockquote)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>|(?:\r\n|\r|\n)[\t ]*(?:\r\n|\r|\n)+~i';
        preg_match_all($boundary,$before,$parts,PREG_OFFSET_CAPTURE);
        if($parts[0]){$last=end($parts[0]);$left+=$last[1]+strlen($last[0]);}
        $end=$right;
        if(preg_match($boundary,$after,$m,PREG_OFFSET_CAPTURE))$end=$node['end']+$m[0][1];
        $html=substr($content,$left,$end-$left);
        if(!self::callout($html))return null;
        // A trailing BR belongs to a removed standalone recommendation.
        if(preg_match('~^<br\s*/?>~i',substr($content,$end),$m))$end+=strlen($m[0]);
        return array($left,$end);
    }
    private static function deletion($content,$nodes,$index) {
        $link=$nodes[$index];$range=null;
        for($i=$link['parent'];$i!==null;$i=$nodes[$i]['parent']) {
            $n=$nodes[$i];if($n['close']===null)break;
            if(!in_array($n['name'],array('p','div','span','font','strong','b','em','i','u','s','small'),true))break;
            $inner=substr($content,$n['open_end'],$n['close']-$n['open_end']);
            // Permit outer formatting wrappers, but not a container holding other paragraphs.
            if(self::simple($inner,true) && !preg_match('~<(?:p|div)\b~i',$inner) && self::callout($inner)) {
                if(in_array($n['name'],array('p','div'),true))$range=array($n['start'],$n['end']);
            }
        }
        if($range===null) {
            $best=$index;
            while($nodes[$best]['parent']!==null && in_array($nodes[$nodes[$best]['parent']]['name'],array('span','font','strong','b','em','i','u','s','small'),true))$best=$nodes[$best]['parent'];
            $range=self::segment($content,$nodes,$nodes[$best]);
        }
        if($range!==null) {
            // Include outer formatting/block wrappers only when they contain
            // nothing except this recommendation and whitespace.
            for($i=$link['parent'];$i!==null;$i=$nodes[$i]['parent']) {
                $n=$nodes[$i];
                if($n['end']===null || $n['start']>$range[0] || $n['end']<$range[1])continue;
                $whole=substr($content,$n['start'],$n['end']-$n['start']);
                $outside=substr($content,$n['open_end'],max(0,$range[0]-$n['open_end'])).substr($content,$range[1],max(0,$n['close']-$range[1]));
                if(self::simple($whole,true) && self::normalized($outside)==='')$range=array($n['start'],$n['end']);
            }
            // Remove a complete Gutenberg paragraph wrapper together with its paragraph.
            $before=substr($content,0,$range[0]);$after=substr($content,$range[1]);
            if(preg_match('~<!-- wp:paragraph(?:\s+\{[^\r\n]*\})?\s*-->\s*$~i',$before,$m) && preg_match('~^\s*<!-- /wp:paragraph -->~i',$after,$n))$range=array($range[0]-strlen($m[0]),$range[1]+strlen($n[0]));
        }
        return $range;
    }
    public static function analyze($content,$protected=array()) {
        $opaque='<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|pre|code)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\1\s*>|$)';
        preg_match_all('~'.$opaque.'|'.self::TAG.'~i',$content,$tokens,PREG_OFFSET_CAPTURE);
        $nodes=array();$stack=array();$targets=array();$malformed=false;
        $void=array('area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr');
        foreach($tokens[0] as $token) {
            $tag=$token[0];$offset=$token[1];
            if(preg_match('~^<!--|^<(?:script|style|textarea|pre|code)\b~i',$tag))continue;
            if(!preg_match('~^<(/?)([a-z][a-z0-9]*)\b~i',$tag,$m)){$malformed=true;continue;}
            $name=strtolower($m[2]);
            if($m[1]) {
                if(!$stack || $nodes[end($stack)]['name']!==$name){$malformed=true;continue;}
                $i=array_pop($stack);$nodes[$i]['close']=$offset;$nodes[$i]['end']=$offset+strlen($tag);continue;
            }
            $i=count($nodes);$nodes[]=array('name'=>$name,'start'=>$offset,'open_end'=>$offset+strlen($tag),'close'=>null,'end'=>null,'parent'=>$stack?end($stack):null);
            if($name==='a' && OGO_MC_Engine::target($tag,'gazeta'))$targets[]=$i;
            if(in_array($name,$void,true)){$nodes[$i]['close']=$offset;$nodes[$i]['end']=$offset+strlen($tag);}
            else {
                $info=$name==='a'?OGO_MC_Engine::href_info($tag):null;
                if(preg_match('~/\s*>$~',$tag,$ending,PREG_OFFSET_CAPTURE) && (!$info || $ending[0][1]<$info['offset'] || $ending[0][1]>=$info['offset']+$info['length']))$malformed=true;
                if($name==='a')foreach($stack as $ancestor)if($nodes[$ancestor]['name']==='a')$malformed=true;
                $stack[]=$i;
            }
        }
        if($stack)$malformed=true;
        $protected_ranges=array();
        foreach($protected as $fragment) {
            if($fragment==='')continue;$cursor=0;
            while(($at=strpos($content,$fragment,$cursor))!==false){$protected_ranges[]=array($at,$at+strlen($fragment));$cursor=$at+strlen($fragment);}
        }
        $ranges=array();$unwrap=array();$review=0;$blocks=0;$unlinked=0;
        foreach($targets as $index) {
            $n=$nodes[$index];$blocked=false;
            foreach($protected_ranges as $r)if($n['start']>=$r[0] && $n['start']<$r[1])$blocked=true;
            if($blocked || $malformed || preg_match('~\s(?:id|name)\s*=~i',substr($content,$n['start'],$n['open_end']-$n['start'])) || !self::simple(substr($content,$n['open_end'],$n['close']-$n['open_end']))) {++$review;continue;}
            $range=self::deletion($content,$nodes,$index);
            if($range!==null)$ranges[$range[0].':'.$range[1]]=$range;
            else $unwrap[]=$index;
        }
        // Coalesce nested candidates, then suppress anchor edits inside removed paragraphs.
        usort($ranges,function($a,$b){return $a[0]<=>$b[0] ?: $b[1]<=>$a[1];});$edits=array();$last_end=-1;
        foreach($ranges as $r){if($r[0]<$last_end)continue;$edits[]=array($r[0],$r[1]-$r[0]);$last_end=$r[1];++$blocks;}
        foreach($unwrap as $index) {
            $n=$nodes[$index];$inside=false;
            foreach($ranges as $r)if($n['start']>=$r[0] && $n['end']<=$r[1])$inside=true;
            if($inside)continue;
            $edits[]=array($n['start'],$n['open_end']-$n['start']);$edits[]=array($n['close'],$n['end']-$n['close']);++$unlinked;
        }
        usort($edits,function($a,$b){return $b[0]<=>$a[0];});$after=$content;$boundary=strlen($content);
        foreach($edits as $e){if($e[0]+$e[1]>$boundary)throw new RuntimeException('Перетин правок газети.');$after=substr_replace($after,'',$e[0],$e[1]);$boundary=$e[0];}
        return array('after'=>$after,'total'=>count($targets),'removed'=>count($targets)-$review,'review'=>$review,'fragments'=>array(),
            'summary'=>'Знято посилань зі збереженням тексту: '.$unlinked.'. Видалено окремих абзаців: '.$blocks.'.'.($review?' Пошкоджені посилання або складна розмітка: '.$review.'.':''));
    }
}
