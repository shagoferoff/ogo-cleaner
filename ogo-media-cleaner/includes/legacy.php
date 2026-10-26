<?php
/** Resolve preserved migration IDs locally, never follow HTTP redirects. */
final class OGO_MC_Legacy {
    private const TAG='<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private static $cache=array();
    public static function clear_cache() { self::$cache=array(); }
    public static function path_candidate($path) {
        // /view may be missing; the date can contain historical typing errors.
        return preg_match('~^/articles/(?:view(?:/|$)|[0-9]{4}-[0-9-]+(?:/|$))~i', $path) === 1;
    }
    public static function candidate($info) {
        if (!$info || !is_array($info['parts'])) return false;
        $p = $info['parts'];
        if (!self::path_candidate($p['path'] ?? '')) return false;
        if (isset($p['user']) || isset($p['pass'])) return false;
        if (isset($p['host'])) {
            // These mistaken origins were explicitly identified by the editors.
            $host = strtolower($p['host']);
            if (substr($host,0,4)==='www.') $host=substr($host,4);
            if (!in_array($host,array('ogo.ua','gazeta.ogo.ua','mail.ogo.ua'),true) || !in_array(strtolower($p['scheme'] ?? ''),array('http','https'),true)) return false;
            if (isset($p['port']) && !((strtolower($p['scheme'])==='http' && $p['port']===80) || (strtolower($p['scheme'])==='https' && $p['port']===443))) return false;
        } elseif (isset($p['scheme']) || substr(trim($info['raw']),0,1)!=='/') return false;
        return true;
    }
    private static function review_candidate($info) {
        if (!$info || !is_array($info['parts'])) return false;
        $p=$info['parts']; $host=strtolower($p['host'] ?? ''); $scheme=strtolower($p['scheme'] ?? '');
        if (!self::path_candidate($p['path'] ?? '') || isset($p['user']) || isset($p['pass']) || !in_array($scheme,array('http','https'),true)) return false;
        if (isset($p['port']) && !(($scheme==='http' && $p['port']===80) || ($scheme==='https' && $p['port']===443))) return false;
        return $host==='ogo.rv.ua' || substr($host,-7)==='.ogo.ua' || substr($host,-10)==='.ogo.rv.ua';
    }
    private static function clean_tail($path) {
        // Remove only recognizable formatting garbage. Arbitrary suffixes are
        // never discarded to manufacture a valid-looking article ID.
        return preg_replace('~(?:[.\s\p{Z}]+|<br\s*/?>)+$~iu','',$path);
    }
    public static function extract_id($info) {
        if (!self::candidate($info)) return array(null,'неприпустимий домен або шлях старої адреси');
        $p=$info['parts'];
        if (isset($p['query']) && $p['query']!=='') return array(null,'адреса має параметри — потрібна перевірка');
        $path=self::clean_tail(rawurldecode($p['path']));
        if ($path===null || preg_match('/[\x00-\x1f\x7f]/',$path)) return array(null,'пошкоджене кодування адреси');
        // Split only known embedded OGO origins. Every remaining segment must
        // be either an article or a date-only prefix from a duplicated URL.
        $segments=preg_split('~(?:https?://|//)?(?:www\.)?(?:mail\.ogo\.ua|gazeta\.ogo\.ua|ogo\.ua)(?=/articles/)~i',$path);
        $ids=array(); $last_article=false;
        foreach ($segments as $segment) {
            $segment=self::clean_tail($segment); $last_article=false;
            if (preg_match('~^/articles/(?:view/)?(?:[0-9]{4}-[0-9-]+/)?([1-9][0-9]*)(?:\.html?|html)?/?$~iD',$segment,$m)) {
                $id=$m[1];
                if (strlen($id)>strlen((string)PHP_INT_MAX) || (strlen($id)===strlen((string)PHP_INT_MAX) && strcmp($id,(string)PHP_INT_MAX)>0)) return array(null,'ID за межами допустимого діапазону');
                $ids[$id]=true; $last_article=true;
            } elseif (!(count($segments)>1 && preg_match('~^/articles/(?:view/)?[0-9]{4}-[0-9-]+/$~iD',$segment))) {
                return array(null,'невпізнаний суфікс або структура адреси — потрібна перевірка');
            }
        }
        if (count($ids)!==1 || !$last_article) return array(null,count($ids)>1 ? 'в адресі різні ID: '.implode(', ',array_keys($ids)) : 'не вдалося однозначно визначити ID');
        return array((int)array_keys($ids)[0],null);
    }
    private static function destination($id) {
        if(isset(self::$cache[$id]))return self::$cache[$id];
        $p=get_post($id);
        if (!$p) $r=array(null,'ID '.$id.': статтю не знайдено');
        elseif ($p->post_type!=='post') $r=array(null,'ID '.$id.': запис не є статтею post');
        elseif ($p->post_status!=='publish') $r=array(null,'ID '.$id.': стаття не опублікована ('.$p->post_status.')');
        elseif (!empty($p->post_password)) $r=array(null,'ID '.$id.': стаття захищена паролем');
        elseif (!is_post_publicly_viewable($p)) $r=array(null,'ID '.$id.': стаття недоступна публічно');
        else {
            $url=get_permalink($p);$parts=is_string($url)?parse_url($url):false;
            $home=parse_url(home_url('/'));
            if(!$parts || !$home || !in_array(strtolower($parts['scheme']??''),array('http','https'),true) || !in_array(strtolower($parts['host']??''),array('ogo.ua','www.ogo.ua'),true) || strtolower($parts['host'])!==strtolower($home['host']??'') || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port']) || empty($parts['path']) || $parts['path']==='/' || preg_match('~^/(?:articles|nashregion|woman)(?:/|$)~i',$parts['path']) || preg_match('/[\s<>"\x{0027}]/u',$url))$r=array(null,'WordPress не повернув звичайний внутрішній permalink');
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
    private static function legacy_replacement($info) {
        if (!self::candidate($info)) return array(null,'Підпроєкт '.($info['parts']['host'] ?? '').': потрібно підтвердити відповідність ID основному сайту');
        list($id,$reason)=self::extract_id($info);
        if ($id===null) return array(null,$reason);
        list($url,$reason)=self::destination($id);
        if ($url===null) return array(null,$reason);
        if (isset($info['parts']['fragment']) && $info['parts']['fragment']!=='page-top') $url.='#'.$info['parts']['fragment'];
        if (preg_match('/[\x00-\x20<>"\x{0027}]/u',$url)) return array(null,'ID '.$id.': незвичний фрагмент адреси');
        $url=htmlspecialchars($url,ENT_QUOTES | ENT_HTML5,'UTF-8');
        $url=str_replace(array('`','='),array('&#96;','&#61;'),$url);
        preg_match('/^\s*/',$info['raw'],$leading); preg_match('/\s*$/',$info['raw'],$trailing);
        return array($leading[0].$url.$trailing[0],null);
    }
    public static function read_info($tag) {
        $info=OGO_MC_Engine::href_info($tag);
        if ($info && $info['parts']===false) {
            $relative=trim(html_entity_decode($info['raw'],ENT_QUOTES | ENT_HTML5,'UTF-8'));
            if (substr($relative,0,1)==='/' && substr($relative,0,2)!=='//') {
                $parts=parse_url('https://ogo.ua'.$relative);
                if (is_array($parts)) { unset($parts['scheme'],$parts['host']); $info['parts']=$parts; }
            }
        }
        return $info;
    }
    /** Local absence is the only automatic deletion criterion; HTTP 404 is not. */
    public static function cleanup_decision($info) {
        if (!$info || !is_array($info['parts'])) return array('ignore',null);
        $p=$info['parts'];
        if (preg_match('~^/archive/[^/]+/site/[^/]+/?$~i',$p['path'] ?? '')) {
            $probe=$info; $probe['parts']['path']='/articles/view/2000-01-01/1.html';
            if (self::candidate($probe)) return array('review','Архів /archive/…/site/…: відповідність ID не підтверджена');
        }
        if (!self::candidate($info) && !self::review_candidate($info)) return array('ignore',null);
        list($id,$reason)=self::extract_id($info);
        if ($id===null) return array('review',$reason);
        $post=get_post($id);
        global $wpdb;
        if (isset($wpdb) && !empty($wpdb->last_error)) throw new RuntimeException('Помилка читання бази; відсутність статті не підтверджена.');
        if (!$post) return array('missing','ID '.$id.': запису немає в базі');
        list($url,$reason)=self::legacy_replacement($info);
        return $url===null ? array('review',$reason) : array('replace',$url);
    }
    public static function analyze($content,$masked) {
        $raw='<(script|style|textarea|pre|code)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\1\s*>|$)';
        preg_match_all('~<!--[\s\S]*?(?:-->|$)|'.$raw.'|'.self::TAG.'~i',$content,$matches,PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $tokens=array_map(function($m){return $m[0];},$matches);
        $stack=array();$links=array();$total=0;$changed=0;$edits=array();$reasons=array();
        foreach($tokens as $token) {
            if(preg_match('~^<a(?=[\s/>])~i',$token[0])) {
                $tag=substr($content,$token[1],strlen($token[0]));$info=self::read_info($tag);
                $target=self::candidate($info) || self::review_candidate($info);if($target)++$total;
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
            list($url,$reason)=self::legacy_replacement($link['info']);
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
