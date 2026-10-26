<?php
require_once __DIR__.'/legacy.php';
/** Conservative source-offset edits. No HTML reserialization or network checks. */
final class OGO_MC_Missing {
    private const TAG = '<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private const WRAPPERS = array('a','p','div','span','font','strong','b','em','i','u','s','small','br');
    private static function blank($text) {
        return preg_match('/^[\s\p{Z}]*$/u',html_entity_decode($text,ENT_QUOTES | ENT_HTML5,'UTF-8'))===1;
    }
    /** Is everything outside this one anchor only disposable formatting? */
    private static function pure($content,$start,$end,$link) {
        $fragment=substr($content,$start,$link['start']-$start).substr($content,$link['end'],$end-$link['end']);
        $bad=false;
        $text=preg_replace_callback('~'.self::TAG.'~s',function($m) use (&$bad) {
            if (!preg_match('~^</?([a-z][a-z0-9]*)\b~i',$m[0],$tag) || !in_array(strtolower($tag[1]),self::WRAPPERS,true) || strtolower($tag[1])==='a' || preg_match('~\s(?:id|name)\s*=~i',$m[0])) $bad=true;
            return '';
        },$fragment);
        return !$bad && self::blank($text);
    }
    private static function simple_label($content,$node) {
        $body=substr($content,$node['open_end'],$node['close']-$node['open_end']);
        $bad=false;
        preg_replace_callback('~'.self::TAG.'~s',function($m) use (&$bad) {
            if (!preg_match('~^</?(span|font|strong|b|em|i|u|s|small|br)\b~i',$m[0])) $bad=true;
            return '';
        },$body);
        return !$bad && strpos($body,'<!--')===false;
    }
    /** Whole blocks, or standalone root/BR/blank-line-delimited recommendations. */
    private static function removal_range($content,$nodes,$index) {
        $link=$nodes[$index]; $best=$index; $block=null;
        for ($i=$index;$i!==null;$i=$nodes[$i]['parent']) {
            $node=$nodes[$i];
            if (!in_array($node['name'],self::WRAPPERS,true) || !self::pure($content,$node['start'],$node['end'],$link)) break;
            $best=$i;
            if (in_array($node['name'],array('p','div'),true)) $block=$i;
        }
        if ($block!==null) return array($nodes[$best]['start'],$nodes[$best]['end']);
        $node=$nodes[$best];
        $parent=$node['parent'];
        $left=$parent===null?0:$nodes[$parent]['open_end'];
        $right=$parent===null?strlen($content):$nodes[$parent]['close'];
        // Block parents establish boundaries; inline/complex parents do not.
        if ($parent!==null && !in_array($nodes[$parent]['name'],array('p','div'),true)) return null;
        $before=substr($content,$left,$node['start']-$left);
        $after=substr($content,$node['end'],$right-$node['end']);
        $left_ok=self::blank($before) || preg_match('~(?:<br\s*/?>|</(?:p|div)>|\n[\t \r]*\n)[\s\p{Z}]*$~iu',$before);
        $right_ok=self::blank($after) || preg_match('~^[\s\p{Z}]*(?:<br\s*/?>|<(?:p|div)\b|\n[\t \r]*\n)~iu',$after);
        if (!$left_ok || !$right_ok) return null;
        $end=$node['end'];
        if (preg_match('~^[\t \r\n\p{Z}]*<br\s*/?>~iu',$after,$m)) $end+=strlen($m[0]);
        return array($node['start'],$end);
    }
    public static function analyze($content) {
        $opaque='<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|pre|code)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\1\s*>|$)';
        preg_match_all('~'.$opaque.'|'.self::TAG.'~i',$content,$tokens,PREG_OFFSET_CAPTURE);
        $nodes=array(); $stack=array(); $targets=array(); $malformed=false;
        $void=array('area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr');
        foreach ($tokens[0] as $token) {
            $tag=$token[0]; $offset=$token[1];
            if (preg_match('~^<!--|^<(?:script|style|textarea|pre|code)\b~i',$tag)) continue;
            if (!preg_match('~^<(/?)([a-z][a-z0-9]*)\b~i',$tag,$m)) { $malformed=true; continue; }
            $name=strtolower($m[2]);
            if ($m[1]==='/') {
                if (!$stack || $nodes[end($stack)]['name']!==$name) { $malformed=true; continue; }
                $i=array_pop($stack); $nodes[$i]['close']=$offset; $nodes[$i]['end']=$offset+strlen($tag);
                continue;
            }
            $i=count($nodes);
            $nodes[]=array('name'=>$name,'start'=>$offset,'open_end'=>$offset+strlen($tag),'close'=>null,'end'=>null,'parent'=>$stack?end($stack):null);
            if ($name==='a') {
                $info=OGO_MC_Legacy::read_info($tag);
                list($action,$detail)=OGO_MC_Legacy::cleanup_decision($info);
                if ($action!=='ignore') $targets[$i]=array($action,$detail,$info);
            }
            if (in_array($name,$void,true)) { $nodes[$i]['close']=$offset; $nodes[$i]['end']=$offset+strlen($tag); }
            else {
                if (preg_match('~/\s*>$~',$tag)) $malformed=true;
                if ($name==='a') foreach ($stack as $ancestor) if ($nodes[$ancestor]['name']==='a') $malformed=true;
                $stack[]=$i;
            }
        }
        if ($stack) $malformed=true;
        $edits=array(); $review=0; $unwrapped=0; $deleted=0; $replaced=0; $reasons=array(); $missing_ids=array();
        foreach ($targets as $index=>$target) {
            list($action,$detail,$info)=$target; $node=$nodes[$index];
            if ($malformed) { $action='review'; $detail='пошкоджений або складний HTML — перевірте вручну'; }
            if ($action==='review') { ++$review; $reasons[$detail]=($reasons[$detail]??0)+1; continue; }
            if ($action==='replace') {
                $edits[]=array($node['start']+$info['offset'],$info['length'],$detail); ++$replaced; continue;
            }
            if (preg_match('~\s(?:id|name)\s*=~i',substr($content,$node['start'],$node['open_end']-$node['start'])) || !self::simple_label($content,$node)) {
                ++$review; $reasons['посилання містить медіа або складну розмітку']=($reasons['посилання містить медіа або складну розмітку']??0)+1; continue;
            }
            $missing_ids[$detail]=true;
            $range=self::removal_range($content,$nodes,$index);
            if ($range!==null) { $edits[]=array($range[0],$range[1]-$range[0],''); ++$deleted; }
            else {
                $edits[]=array($node['start'],$node['open_end']-$node['start'],'');
                $edits[]=array($node['close'],$node['end']-$node['close'],''); ++$unwrapped;
            }
        }
        usort($edits,function($a,$b){return $b[0]<=>$a[0];});
        $after=$content; $boundary=strlen($content);
        foreach ($edits as $edit) {
            if ($edit[0]+$edit[1]>$boundary) throw new RuntimeException('Перетин правок; очищення зупинено.');
            $after=substr_replace($after,$edit[2],$edit[0],$edit[1]); $boundary=$edit[0];
        }
        $summary='Замінено URL: '.$replaced.'. Знято посилань зі збереженням тексту: '.$unwrapped.'. Видалено окремих блоків: '.$deleted.'.';
        if ($missing_ids) $summary.=' Відсутні цілі: '.implode('; ',array_keys($missing_ids)).'.';
        foreach ($reasons as $reason=>$count) $summary.=' Перевірити: '.$reason.' — '.$count.'.';
        return array('after'=>$after,'total'=>count($targets),'removed'=>$replaced+$unwrapped+$deleted,'review'=>$review,'fragments'=>array(),'summary'=>$summary);
    }
}
