<?php
/** Resolve preserved migration IDs locally, never follow HTTP redirects. */
final class OGO_MC_Legacy {
    private const TAG='<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private static $cache=array();
    public static function clear_cache() { self::$cache=array(); }
    public static function candidate($info) {
        if(!$info || !is_array($info['parts']))return false;
        $p=$info['parts'];$path=$p['path']??'';
        if(!preg_match('~^/articles/view(?:/|$)~i',$path))return false;
        if(isset($p['user'])||isset($p['pass']))return false;
        if(isset($p['host'])) {
            if(!in_array(strtolower($p['host']),array('ogo.ua','www.ogo.ua'),true)||!in_array(strtolower($p['scheme']??''),array('http','https'),true))return false;
            if(isset($p['port']) && !((strtolower($p['scheme'])==='http'&&$p['port']===80)||(strtolower($p['scheme'])==='https'&&$p['port']===443)))return false;
        } elseif(isset($p['scheme']) || substr(trim($info['raw']),0,1)!=='/')return false;
        return true;
    }
    private static function destination($id) {
        if(isset(self::$cache[$id]))return self::$cache[$id];
        $p=get_post($id);
        if(!$p || $p->post_type!=='post' || $p->post_status!=='publish' || !empty($p->post_password) || !is_post_publicly_viewable($p))$r=array(null,'немає доступної опублікованої статті');
        else {
            $url=get_permalink($p);$parts=is_string($url)?parse_url($url):false;
            $home=parse_url(home_url('/'));
            if(!$parts || !$home || !in_array(strtolower($parts['scheme']??''),array('http','https'),true) || !in_array(strtolower($parts['host']??''),array('ogo.ua','www.ogo.ua'),true) || strtolower($parts['host'])!==strtolower($home['host']??'') || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port']) || empty($parts['path']) || $parts['path']==='/' || preg_match('~^/articles/view(?:/|$)~i',$parts['path']) || preg_match('/[\s<>"\x{0027}]/u',$url))$r=array(null,'WordPress не повернув звичайний внутрішній permalink');
            else $r=array(preg_replace('~^http://~i','https://',$url),null);
        }
        if(count(self::$cache)>1000)self::$cache=array();
        self::$cache[$id]=$r;return $r;
    }
    /** Shared publication, permalink and ID checks for retired editorial projects. */
    public static function nashregion_replacement($info) {
        return self::project_replacement($info, 'nashregion');
    }
    public static function woman_replacement($info) {
        return self::project_replacement($info, 'woman');
    }
    private static function project_replacement($info, $project) {
        if (!$info || !is_array($info['parts']) || !preg_match('~^/'.preg_quote($project, '~').'/(\d{4}-\d{2}-\d{2}/[1-9]\d*\.html)$~', $info['parts']['path'] ?? '', $m)) return array(null, 'невпізнаний формат статті старого проєкту');
        $info['parts']['path'] = '/articles/view/'.$m[1];
        if (!self::candidate($info)) return array(null, 'неприпустима адреса статті');
        list($url, $reason) = self::replacement($info);
        if ($url !== null && preg_match('~^https://(?:www\.)?ogo\.ua/(?:nashregion|woman)(?:/|[?#]|$)~i', trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')))) return array(null, 'стаття досі має адресу старого проєкту');
        return array($url, $reason);
    }
    private static function replacement($info) {
        $p=$info['parts'];
        if(!preg_match('~^/articles/view/(\d{4})-(\d{2})-(\d{2})/([1-9]\d*)\.html$~i',$p['path'],$m) || !checkdate((int)($m[2]??0),(int)($m[3]??0),(int)($m[1]??0)))return array(null,'невпізнаний формат старої адреси');
        if(strlen($m[4])>strlen((string)PHP_INT_MAX) || (strlen($m[4])===strlen((string)PHP_INT_MAX)&&strcmp($m[4],(string)PHP_INT_MAX)>0))return array(null,'ID за межами допустимого діапазону');
        if(isset($p['query']) && $p['query']!=='')return array(null,'адреса має параметри — потрібна перевірка');
        list($url,$reason)=self::destination((int)$m[4]);
        if($url===null)return array(null,$reason);
        if(isset($p['fragment']))$url.='#'.$p['fragment'];
        if(preg_match('/[\x00-\x20<>"\x{0027}]/u',$url))return array(null,'незвичний фрагмент адреси');
        // Quoted and unquoted attributes both remain valid; original whitespace stays.
        $url=htmlspecialchars($url,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $url=str_replace('`','&#96;',$url);
        $url=str_replace('=','&#61;',$url);
        preg_match('/^\s*/',$info['raw'],$leading);preg_match('/\s*$/',$info['raw'],$trailing);
        return array($leading[0].$url.$trailing[0],null);
    }
    public static function analyze($content,$masked) {
        // Also protect unfinished raw-text elements and comments in legacy HTML.
        $masked=preg_replace_callback('~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?(?:</\1\s*>|$)~i',function($m){return str_repeat(' ',strlen($m[0]));},$content);
        preg_match_all('~'.self::TAG.'~',$masked,$tokens,PREG_OFFSET_CAPTURE);
        $stack=array();$links=array();$total=0;$changed=0;$edits=array();$reasons=array();
        foreach($tokens[0] as $token) {
            if(preg_match('~^<a(?=[\s/>])~i',$token[0])) {
                $tag=substr($content,$token[1],strlen($token[0]));$info=OGO_MC_Engine::href_info($tag);
                $target=self::candidate($info);if($target)++$total;
                $invalid=!empty($stack)||preg_match('~/\s*>$~',$tag);
                if($stack)foreach($stack as &$open)$open['invalid']=true;
                unset($open);
                $stack[]=array('offset'=>$token[1],'info'=>$info,'target'=>$target,'invalid'=>$invalid);
            } elseif(preg_match('~^</a\s*>$~i',$token[0]) && $stack) {
                $open=array_pop($stack);
                if(!$open['target'])continue;
                if($open['invalid']){$reasons['пошкоджене або вкладене посилання']=($reasons['пошкоджене або вкладене посилання']??0)+1;continue;}
                $links[]=$open;
            }
        }
        foreach($stack as $open)if($open['target'])$reasons['незакрите посилання']=($reasons['незакрите посилання']??0)+1;
        foreach($links as $link){
            list($url,$reason)=self::replacement($link['info']);
            if($url===null){$reasons[$reason]=($reasons[$reason]??0)+1;continue;}
            if($url===$link['info']['raw']){$reasons['адреса не змінилася']=($reasons['адреса не змінилася']??0)+1;continue;}
            $edits[]=array($link['offset']+$link['info']['offset'],$link['info']['length'],$url);++$changed;
        }
        usort($edits,function($a,$b){return $b[0]<=>$a[0];});$after=$content;
        foreach($edits as $e)$after=substr_replace($after,$e[2],$e[0],$e[1]);
        $summary='Замінено старих URL за ID: '.$changed.'.';
        foreach($reasons as $reason=>$count)$summary.=' Перевірити: '.$reason.' — '.$count.'.';
        return array('after'=>$after,'total'=>$total,'removed'=>$changed,'review'=>$total-$changed,'fragments'=>array(),'summary'=>$summary);
    }
}
