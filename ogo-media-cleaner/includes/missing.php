<?php
require_once __DIR__.'/legacy.php';
/** Preserve the article HTML while replacing or unwrapping old links. */
final class OGO_MC_Missing {
    public static function analyze($content) {
        $nodes=array();
        $stack=array();
        $targets=array();
        $malformed=false;
        $void=array('area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr');
        foreach (OGO_MC_Engine::html_tokens($content) as list($tag,$offset)) {
            if (preg_match('~^<!--|^<(?:script|style|textarea|pre|code)(?=[\s/>])~i',$tag)) continue;
            if (!preg_match('~^<(/?)([a-z][a-z0-9:-]*)(?=[\s/>])~i',$tag,$match)) { $malformed=true; continue; }
            $name=strtolower($match[2]);
            if ($match[1]==='/') {
                if (!$stack || $nodes[end($stack)]['name']!==$name) { $malformed=true; continue; }
                $index=array_pop($stack);
                $nodes[$index]['close']=$offset;
                $nodes[$index]['end']=$offset+strlen($tag);
                continue;
            }
            if (in_array($name,$void,true)) continue;
            $index=count($nodes);
            $nodes[]=array('name'=>$name,'start'=>$offset,'open_end'=>$offset+strlen($tag));
            if ($name==='a') {
                $info=OGO_MC_Legacy::read_info($tag);
                list($action,$detail)=OGO_MC_Legacy::cleanup_decision($info);
                if ($action!=='ignore') $targets[$index]=array($action,$detail,$info);
                if (OGO_MC_Engine::self_closing_anchor($tag)) $malformed=true;
                foreach ($stack as $ancestor) if ($nodes[$ancestor]['name']==='a') $malformed=true;
            } elseif (preg_match('~/\s*>$~',$tag)) $malformed=true;
            $stack[]=$index;
        }
        if ($stack) $malformed=true;

        $edits=array();
        $review=0;
        $unwrapped=0;
        $replaced=0;
        $reasons=array();
        $missing_ids=array();
        foreach ($targets as $index=>$target) {
            list($action,$detail,$info)=$target;
            $node=$nodes[$index];
            if ($malformed) { $action='review'; $detail='пошкоджений або складний HTML — перевірте вручну'; }
            if ($action==='review') { ++$review; $reasons[$detail]=($reasons[$detail]??0)+1; continue; }
            if ($action==='replace') {
                $edits[]=array($node['start']+$info['offset'],$info['length'],$detail);
                ++$replaced;
                continue;
            }
            if ($action==='missing') $missing_ids[$detail]=true;
            $edits[]=array($node['start'],$node['open_end']-$node['start'],'');
            $edits[]=array($node['close'],$node['end']-$node['close'],'');
            ++$unwrapped;
        }
        usort($edits,function($a,$b){return $b[0]<=>$a[0];});
        $after=$content;
        $boundary=strlen($content);
        foreach ($edits as $edit) {
            if ($edit[0]+$edit[1]>$boundary) throw new RuntimeException('Перетин правок; очищення зупинено.');
            $after=substr_replace($after,$edit[2],$edit[0],$edit[1]);
            $boundary=$edit[0];
        }
        $summary='Замінено URL: '.$replaced.'. Знято посилань зі збереженням тексту: '.$unwrapped.'.';
        if ($missing_ids) $summary.=' Відсутні цілі: '.implode('; ',array_keys($missing_ids)).'.';
        foreach ($reasons as $reason=>$count) $summary.=' Перевірити: '.$reason.' — '.$count.'.';
        return array('after'=>$after,'total'=>count($targets),'removed'=>$replaced+$unwrapped,'review'=>$review,'fragments'=>array(),'summary'=>$summary);
    }
}
