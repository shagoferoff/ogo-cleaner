<?php
/** Narrow paragraph rules plus lossless unwrapping of historical VK sources. */
final class OGO_MC_VK {
    private const TAG='<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private const ANCHOR='~<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?</a\s*>~i';
    private static function mask($s) {return preg_replace_callback('~<!--[\s\S]*?-->|<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?</\1\s*>~i',function($m){return str_repeat(' ',strlen($m[0]));},$s);}
    private static function text($s) {return trim(preg_replace('/[\s\x{00a0}]+/u',' ',html_entity_decode(preg_replace('~'.self::TAG.'~','',$s),ENT_QUOTES|ENT_HTML5,'UTF-8')));}
    private static function safe($s) {
        if(strpos($s,'<!--')!==false || strpos(self::text($s),'[')!==false)return false;
        preg_match_all('~'.self::TAG.'~',$s,$tags);$stack=array();
        foreach($tags[0] as $tag){
            if(!preg_match('~^<(/?)(a|b|strong|em|i|u|s|span|small|br)(?=[\s/>])~i',$tag,$m))return false;
            $name=strtolower($m[2]);if($name==='br'&&!$m[1])continue;
            if($m[1]) {if(array_pop($stack)!==$name)return false;}
            else {if(preg_match('~/\s*>$~',$tag))return false;$stack[]=$name;}
        }
        return !$stack;
    }
    private static function facebook($tag) {
        $i=OGO_MC_Engine::href_info($tag);$p=$i['parts']??null;
        return is_array($p) && in_array(strtolower($p['scheme']??''),array('http','https'),true) && in_array(strtolower($p['host']??''),array('facebook.com','www.facebook.com'),true) && !isset($p['user']) && !isset($p['pass']);
    }
    private static function anchors($s) {preg_match_all(self::ANCHOR,$s,$a,PREG_OFFSET_CAPTURE);return $a[0];}
    private static function target_count($s) {
        preg_match_all('~<a(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i',self::mask($s),$tags);$n=0;
        foreach($tags[0] as $tag)if(OGO_MC_Engine::target($tag,'vk'))++$n;
        return $n;
    }
    private static function units($content) {
        $gutenberg=strpos($content,'<!-- wp:')!==false;$units=array();
        $pattern=$gutenberg?'~<!-- wp:paragraph(?:\s+\{[^\r\n]*\})?\s*-->\s*<p\b[^>]*>([\s\S]*?)</p\s*>\s*<!-- /wp:paragraph -->~i':'~<p\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>([\s\S]*?)</p\s*>~i';
        preg_match_all($pattern,$content,$paragraphs,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        preg_match_all('~<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?</\1\s*>|<!--(?!\s*/?wp:)[\s\S]*?-->~i',$content,$hidden,PREG_OFFSET_CAPTURE);
        $cursor=0;
        foreach($paragraphs as $p) {
            $overlap=false;foreach($hidden[0] as $h)if($p[0][1]<$h[1]+strlen($h[0]) && $p[0][1]+strlen($p[0][0])>$h[1])$overlap=true;
            if($overlap)continue;
            self::gap($units,$content,$cursor,$p[0][1],$gutenberg);
            $units[]=array('start'=>$p[0][1],'end'=>$p[0][1]+strlen($p[0][0]),'inner'=>$p[1][0],'offset'=>$p[1][1]);
            $cursor=$p[0][1]+strlen($p[0][0]);
        }
        self::gap($units,$content,$cursor,strlen($content),$gutenberg);return $units;
    }
    private static function gap(&$units,$content,$start,$end,$gutenberg) {
        if($gutenberg || $end<=$start)return;
        $gap=substr($content,$start,$end-$start);
        if(preg_match('~<(script|style|textarea|pre|code)\b|<!--~i',$gap))return;
        foreach(preg_split('/(?:\r\n|\n|\r)[ \t]*(?:\r\n|\n|\r)+/',$gap,-1,PREG_SPLIT_OFFSET_CAPTURE) as $p)if(trim($p[0])!=='')$units[]=array('start'=>$start+$p[1],'end'=>$start+$p[1]+strlen($p[0]),'inner'=>$p[0],'offset'=>$start+$p[1]);
    }
    private static function book_prefix($s) {
        return preg_match('/^P\.?\s*S\.?\s*Хочете поділитися з нами своєю любов[\x{0027}’ʼ]ю до книг\?/iu',self::text($s))===1;
    }
    private static function contest($s) {
        if(!self::book_prefix($s) || !self::safe($s))return false;
        $plain=self::text($s);
        if(strpos($plain,'info@ogo.ua')===false || strpos($plain,'uliana.tet@gmail.com')===false)return false;
        $links=self::anchors($s);if(count($links)!==2)return false;
        if(!OGO_MC_Engine::target($links[0][0],'vk') || !self::facebook($links[1][0]))return false;
        $between=substr($s,$links[0][1]+strlen($links[0][0]),$links[1][1]-$links[0][1]-strlen($links[0][0]));
        $tail=substr($s,$links[1][1]+strlen($links[1][0]));
        return self::text($between)==='та' && preg_match('/^[.!]?$/u',self::text($tail));
    }
    private static function group($s) {
        if(!self::safe($s))return false;$links=self::anchors($s);
        if(count($links)!==1 || !OGO_MC_Engine::target($links[0][0],'vk'))return false;
        $label=self::text($links[0][0]);
        if(!preg_match('~^(?:https?://)?(?:www\.)?vk\.com/[^\s]+$~iu',$label))return false;
        $rest=substr_replace($s,'',$links[0][1],strlen($links[0][0]));
        return preg_match('/^Група зустрічі\s*:\s*[.!]?$/iu',self::text($rest))===1;
    }
    private static function social_prefix($s) {return preg_match('/^(?:Або ж\s+)?пишіть(?: нам)?\s+(?:у|в) соцмережах\s*[-–—:]/iu',self::text($s))===1;}
    private static function social($s) {
        if(!self::safe($s)||!self::social_prefix($s))return null;
        $links=self::anchors($s);$keep=array();$vk=0;
        if(count($links)<2)return null;
        foreach($links as $link){if(OGO_MC_Engine::target($link[0],'vk'))++$vk;elseif(self::facebook($link[0]))$keep[]=$link[0];else return null;}
        if(!$vk || count($keep)!==1)return null;
        $first=$links[0];$last=end($links);
        $prefix=substr($s,0,$first[1]);$tail=substr($s,$last[1]+strlen($last[0]));
        if(!preg_match('/^(?:Або ж\s+)?пишіть(?: нам)?\s+(?:у|в) соцмережах\s*[-–—:]$/iu',self::text($prefix)) || !preg_match('/^[.!]?$/u',self::text($tail)))return null;
        for($i=1;$i<count($links);++$i){$gap=substr($s,$links[$i-1][1]+strlen($links[$i-1][0]),$links[$i][1]-$links[$i-1][1]-strlen($links[$i-1][0]));if(!preg_match('/^(?:[,;]|та|і)$/iu',self::text($gap)))return null;}
        $after=$prefix.$keep[0].$tail;
        return self::safe($after)?$after:null;
    }
    public static function analyze($content,$masked) {
        $total=self::target_count($content);$edits=array();$paragraphs=0;$social=0;
        foreach(self::units($content) as $u){
            if(!self::target_count($u['inner']))continue;
            if(self::contest($u['inner']) || self::group($u['inner'])){$edits[]=array($u['start'],$u['end'],'');++$paragraphs;continue;}
            $list=self::social($u['inner']);
            if($list!==null){$edits[]=array($u['offset'],$u['offset']+strlen($u['inner']),$list);++$social;}
        }
        usort($edits,function($a,$b){return $b[0]<=>$a[0];});$after=$content;
        foreach($edits as $e)$after=substr_replace($after,$e[2],$e[0],$e[1]-$e[0]);
        $removedBeforeUnwrap=$total-self::target_count($after);$excluded=array();
        foreach(self::units($after) as $u)if(self::social_prefix($u['inner'])||self::book_prefix($u['inner']))$excluded[]=array($u['start'],$u['end']);
        $unwrapped=OGO_MC_Engine::unwrap_links($after,self::mask($after),'vk',$excluded);
        $removed=$removedBeforeUnwrap+$unwrapped['removed'];
        return array('after'=>$unwrapped['after'],'total'=>$total,'removed'=>$removed,'review'=>$total-$removed,'fragments'=>array(),
            'summary'=>'Знято зі збереженням тексту: '.$unwrapped['removed'].'; списків соцмереж виправлено: '.$social.'; абзаців видалено: '.$paragraphs.'.');
    }
}
