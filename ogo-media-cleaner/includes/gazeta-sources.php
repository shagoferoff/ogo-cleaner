<?php
/** Only unlink the newspaper name following the source-credit phrase. */
final class OGO_MC_Gazeta_Sources {
    private const TAG='<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private static function normalized($text) {
        return preg_replace('/[\s\p{Z}]+/u',' ',html_entity_decode($text,ENT_QUOTES | ENT_HTML5,'UTF-8'));
    }
    private static function prefix($text) {
        $s=self::normalized($text);
        return $s!==null && preg_match('/(?<![\p{L}\p{N}_])За матеріалами\s*:?\s*(?:["«“„]\s*)?$/iu',$s)===1;
    }
    private static function name($html) {
        preg_match_all('~'.self::TAG.'~s',$html,$tags);$stack=array();
        foreach($tags[0] as $tag) {
            if(!preg_match('~^<(/?)(strong|b|em|i|span|font|u|s|small|br)(?=[\s/>])~i',$tag,$m))return false;
            $name=strtolower($m[2]);
            if($name==='br' && !$m[1])continue;
            if($m[1]){if(array_pop($stack)!==$name)return false;}
            else{if(preg_match('~/\s*>$~',$tag))return false;$stack[]=$name;}
        }
        if($stack || strpos($html,'<!--')!==false)return false;
        $s=self::normalized(preg_replace('~'.self::TAG.'~s',' ',$html));
        if($s===null)return false;
        return preg_match('/^\s*(?:"\s*Рівненської газети\s*"|«\s*Рівненської газети\s*»|“\s*Рівненської газети\s*”|„\s*Рівненської газети\s*“|Рівненської газети)\s*$/iu',$s)===1;
    }
    public static function analyze($content) {
        $raw='<(script|style|textarea|pre|code)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\1\s*>|$)';
        preg_match_all('~<!--[\s\S]*?(?:-->|$)|'.$raw.'|'.self::TAG.'~i',$content,$tokens,PREG_OFFSET_CAPTURE);
        $stack=array();$context='';$cursor=0;$total=0;$changed=0;$edits=array();$reasons=array();
        foreach($tokens[0] as $token) {
            $tag=$token[0];$offset=$token[1];
            if(!$stack) {
                $text=substr($content,$cursor,$offset-$cursor);
                $paragraphs=preg_split('/(?:\r\n|\r|\n)[\t ]*(?:\r\n|\r|\n)/',$text);
                $context=count($paragraphs)>1?end($paragraphs):$context.$text;
            }
            $cursor=$offset+strlen($tag);
            if(preg_match('~^<!--|^<(?:script|style|textarea|pre|code)\b~i',$tag)){$context='';continue;}
            if(preg_match('~^<a(?=[\s/>])~i',$tag)) {
                $target=OGO_MC_Engine::target($tag,'gazeta_sources') && self::prefix($context);
                if($target)++$total;
                $info=OGO_MC_Engine::href_info($tag);
                $self_closing=preg_match('~/\s*>$~',$tag,$ending,PREG_OFFSET_CAPTURE) && (!$info || $ending[0][1]<$info['offset'] || $ending[0][1]>=$info['offset']+$info['length']);
                $invalid=!empty($stack)||$self_closing;
                if($stack)foreach($stack as &$open)$open['invalid']=true;
                unset($open);
                $stack[]=array('start'=>$offset,'length'=>strlen($tag),'inner'=>$cursor,'target'=>$target,'invalid'=>$invalid);$context='';
            } elseif(preg_match('~^</a\s*>$~i',$tag)) {
                $context='';if(!$stack)continue;$open=array_pop($stack);if(!$open['target'])continue;
                $reason=$open['invalid']?'вкладене або пошкоджене посилання':(!self::name(substr($content,$open['inner'],$offset-$open['inner']))?'назва джерела або оформлення потребують перевірки':null);
                if($reason!==null){$reasons[$reason]=($reasons[$reason]??0)+1;continue;}
                $edits[]=array($open['start'],$open['length']);$edits[]=array($offset,strlen($tag));++$changed;
            } elseif(!preg_match('~^</?(?:strong|b|em|i|span|font|u|s|small|br)(?=[\s/>])~i',$tag))$context='';
        }
        foreach($stack as $open)if($open['target'])$reasons['незакрите посилання']=($reasons['незакрите посилання']??0)+1;
        usort($edits,function($a,$b){return $b[0]<=>$a[0];});$after=$content;
        foreach($edits as $e)$after=substr_replace($after,'',$e[0],$e[1]);
        $summary='Знято посилань із підписів джерела: '.$changed.'. Текст і абзаци збережені.';
        foreach($reasons as $reason=>$count)$summary.=' Перевірити: '.$reason.' — '.$count.'.';
        return array('after'=>$after,'total'=>$total,'removed'=>$changed,'review'=>$total-$changed,'fragments'=>array(),'summary'=>$summary);
    }
}
