<?php
require_once __DIR__.'/../ogo-miralinks-audit/includes/helpers.php';
/** Unwrap external HTTP(S) anchors, preserving every byte outside the a tags. */
final class OGO_Miralinks_Unwrap {
    private const TAG='<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private static function external($raw) {
        $url=trim(html_entity_decode($raw,ENT_QUOTES | ENT_HTML5,'UTF-8'));
        if(substr($url,0,2)==='//')$url='https:'.$url;
        if(!preg_match('~^https?://~i',$url))return false;
        $parts=parse_url($url);if(!$parts || empty($parts['host']))throw new RuntimeException('Не вдалося визначити домен HTTP(S) адреси');
        $host=strtolower(rtrim($parts['host'],'.'));
        foreach(array('ogo.ua','ogo.rv.ua') as $root)if($host===$root || substr($host,-strlen('.'.$root))==='.' . $root)return false;
        // Whitespace in a path need not be repaired to remove an advertising anchor.
        return true;
    }
    public static function analyze($content) {
        $raw='<(script|style|textarea|pre|code)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\1\s*>|$)';
        preg_match_all('~<!--[\s\S]*?(?:-->|$)|'.$raw.'|'.self::TAG.'~i',$content,$tokens,PREG_OFFSET_CAPTURE);
        $stack=array();$edits=array();$count=0;
        foreach($tokens[0] as $token) {
            $tag=$token[0];$offset=$token[1];
            if(preg_match('~^<a(?=[\s/>])~i',$tag)) {
                $info=OGO_MLA_HTML::href_info($tag);$external=$info && self::external($info['raw']);
                if($stack)foreach($stack as &$open)$open['bad']=true;
                unset($open);
                $stack[]=array('start'=>$offset,'length'=>strlen($tag),'external'=>$external,'bad'=>!empty($stack)||preg_match('~/\s*>$~',$tag));
            } elseif(preg_match('~^</a\s*>$~i',$tag) && $stack) {
                $open=array_pop($stack);if(!$open['external'])continue;
                if($open['bad'])throw new RuntimeException('Вкладене або пошкоджене посилання');
                $edits[]=array($open['start'],$open['length']);$edits[]=array($offset,strlen($tag));++$count;
            }
        }
        foreach($stack as $open)if($open['external'])throw new RuntimeException('Незакрите зовнішнє посилання');
        usort($edits,function($a,$b){return $b[0]<=>$a[0];});$after=$content;$limit=strlen($content);
        foreach($edits as $edit) {
            if($edit[0]+$edit[1]>$limit)throw new RuntimeException('Перетин правок');
            $after=substr_replace($after,'',$edit[0],$edit[1]);$limit=$edit[0];
        }
        return array('after'=>$after,'edits'=>$edits,'links'=>$count);
    }
}
