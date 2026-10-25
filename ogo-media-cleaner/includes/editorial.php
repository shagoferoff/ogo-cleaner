<?php
/** Editorially approved retired rubric mappings; change href bytes only. */
final class OGO_MC_Editorial {
    private const TAG = '<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private const MAP = array(
        '/gazeta/rubrics/view/gazeta-recipes'=>'reczepty',
        '/gazeta/rubrics/view/goroskop'=>'goroskop',
        '/vybir2010/rubrics/view/vybory-news'=>'vybory',
        '/vybir2010/rubrics/view/vybory-newsregion'=>'vybory',
        '/rubrics/view/pidsymku_2011'=>'pidsumky-roku',
        '/rubrics/view/ogo20'=>'rejtyngy',
        '/rubrics/view/people-top20-tvorzinezalezhnosti'=>'rejtyngy',
        '/rubrics/view/defense'=>'oborona',
        '/rubrics/view/volyn'=>'volyn',
        '/rubrics/view/euro'=>'yevropa',
        '/rubrics/view/evro2012'=>'chempionat-yevropy-z-futbolu',
        '/rubrics/view/dovidka'=>'dovidka',
        '/rubrics/view/oneday'=>'odyn-den',
        '/rubrics/view/policy'=>'polityka',
        '/rubrics/view/new-face'=>'novi-oblychchya-rivnogo',
        '/rubrics/view/elect2012'=>'vybory',
        '/rubrics/view/biznesova_kyhnya'=>'biznes',
        '/rubrics/view/agro'=>'agrovyrobnyky',
        '/rubrics/view/vlada'=>'vlada',
        '/rubrics/view/people-handmade'=>'rukodillya'
    );
    private static function tag($base) {
        if (isset(self::MAP[$base])) return self::MAP[$base];
        if (preg_match('~^/rubrics/view/([a-z0-9_-]+)$~D', $base, $m)) {
            // /top is active. Match a top token (including top20), not desktop,
            // topology or other unrelated words; influence is an editorial alias.
            if ($m[1] !== 'top' && preg_match('~(?:^|[-_])top(?:[-_0-9]|$)|influence~', $m[1])) return 'rejtyngy';
        }
        return null;
    }
    public static function destination_for_path($path) {
        if (!preg_match('~^((?:/(?:gazeta|vybir2010))?/rubrics/view/[a-z0-9_-]+)(?:/page:[0-9]+)?/?$~D', $path, $m)) return null;
        $tag = self::tag($m[1]);
        return $tag === null ? null : 'https://ogo.ua/tag/'.$tag.'/';
    }
    private static function candidate($info) {
        if (!$info || !is_array($info['parts'])) return false;
        $p = $info['parts'];
        if (!preg_match('~^((?:/(?:gazeta|vybir2010))?/rubrics/view/[a-z0-9_-]+)(?:/|$)~D', $p['path'] ?? '', $m) || self::tag($m[1]) === null) return false;
        if (isset($p['user']) || isset($p['pass'])) return false;
        if (isset($p['host'])) {
            $scheme = strtolower($p['scheme'] ?? '');
            if (!in_array(strtolower($p['host']), array('ogo.ua','www.ogo.ua'), true) || !in_array($scheme,array('http','https'),true)) return false;
            if (isset($p['port']) && !(($scheme === 'http' && $p['port'] === 80) || ($scheme === 'https' && $p['port'] === 443))) return false;
        } elseif (isset($p['scheme']) || substr(trim($info['raw']),0,1) !== '/') return false;
        return true;
    }
    private static function replacement($info) {
        $p = $info['parts']; $target = self::destination_for_path($p['path']);
        if ($target === null) return null;
        $url = preg_replace('~^(\s*)(?:(?i:https?://|//)(?i:www\.)?(?i:ogo\.ua)(?::(?:80|443))?)?'.preg_quote($p['path'],'~').'(?=[?#\s]|$)~', '$1'.$target, $info['raw'],1,$count);
        if (!$count) return null;
        if (($p['fragment'] ?? '') === 'page-top') {
            $url = preg_replace('~#page-top(?=\s*$)~','',$url,1,$count);
            if (!$count) return null;
        }
        return $url;
    }
    public static function analyze($content) {
        $raw = '<(script|style|textarea|pre|code)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\1\s*>|$)';
        preg_match_all('~<!--[\s\S]*?(?:-->|$)|'.$raw.'|'.self::TAG.'~i',$content,$tokens,PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $stack=array(); $edits=array(); $total=0;
        foreach ($tokens as $token) {
            $tag = $token[0][0]; $offset = $token[0][1];
            if (preg_match('~^<a(?=[\s/>])~i',$tag)) {
                $info = OGO_MC_Engine::href_info($tag);
                if ($info && $info['parts'] === false) {
                    $relative = trim(html_entity_decode($info['raw'],ENT_QUOTES | ENT_HTML5,'UTF-8'));
                    if (substr($relative,0,1)==='/' && substr($relative,0,2)!=='//') {
                        $parts=parse_url('https://ogo.ua'.$relative);
                        if (is_array($parts)) { unset($parts['scheme'],$parts['host']); $info['parts']=$parts; }
                    }
                }
                $target = self::candidate($info); if ($target) ++$total;
                $invalid = !empty($stack) || preg_match('~/\s*>$~',$tag);
                if ($stack) foreach ($stack as &$open) $open['invalid']=true;
                unset($open);
                $stack[]=array('offset'=>$offset,'info'=>$info,'target'=>$target,'invalid'=>$invalid);
            } elseif (preg_match('~^</a\s*>$~i',$tag) && $stack) {
                $open=array_pop($stack);
                if (!$open['target'] || $open['invalid']) continue;
                $url=self::replacement($open['info']);
                if ($url !== null) $edits[]=array($open['offset']+$open['info']['offset'],$open['info']['length'],$url);
            }
        }
        usort($edits,function($a,$b){return $b[0]<=>$a[0];}); $after=$content;
        foreach ($edits as $edit) $after=substr_replace($after,$edit[2],$edit[0],$edit[1]);
        $changed=count($edits); $review=$total-$changed;
        return array('after'=>$after,'total'=>$total,'removed'=>$changed,'review'=>$review,'fragments'=>array(),
            'summary'=>'Замінено погоджених рубричних URL: '.$changed.'.'.($review ? ' Неоднозначні або пошкоджені посилання залишено для ручної перевірки: '.$review.'.' : ''));
    }
}
