<?php
/** Regional rubric aliases with/without Nash Region, plus local article ID lookup. */
final class OGO_MC_Nashregion {
    private const TAG = '<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private const RUBRICS = array(
        'berezne'=>'berezne', 'dubno'=>'dubno', 'dubrovytsia'=>'dubrovyczya',
        'hoshcha'=>'goshha', 'korets'=>'korecz', 'kostopil'=>'kostopil',
        'mlyniv'=>'mlyniv', 'ostroh'=>'ostrog', 'radyvyliv'=>'radyvyliv',
        'rivne'=>'rivne', 'sarny'=>'sarny', 'volodymyrets'=>'volodymyrecz',
        'zarichne'=>'zarichne', 'zdolbuniv'=>'zdolbuniv'
    );
    private static function candidate($info) {
        if (!$info || !is_array($info['parts'])) return false;
        $p = $info['parts'];
        $path = $p['path'] ?? '';
        $project = preg_match('~^/nashregion(?:/|$)~i', $path);
        $regional = preg_match('~^/rubrics/view/([^/]+)(?:/|$)~', $path, $m) && isset(self::RUBRICS[$m[1]]);
        if (!$project && !$regional) return false;
        if (isset($p['user']) || isset($p['pass'])) return false;
        if (isset($p['host'])) {
            $scheme = strtolower($p['scheme'] ?? '');
            if (!in_array(strtolower($p['host']), array('ogo.ua','www.ogo.ua'), true) || !in_array($scheme, array('http','https'), true)) return false;
            if (isset($p['port']) && !(($scheme === 'http' && $p['port'] === 80) || ($scheme === 'https' && $p['port'] === 443))) return false;
        } elseif (isset($p['scheme']) || substr(trim($info['raw']), 0, 1) !== '/') return false;
        return true;
    }
    private static function replacement($info) {
        $p = $info['parts']; $path = $p['path'];
        if (preg_match('~^/nashregion/\d{4}-\d{2}-\d{2}/[1-9]\d*\.html$~', $path)) {
            require_once __DIR__.'/legacy.php';
            if (($p['fragment'] ?? '') === 'page-top') unset($info['parts']['fragment']);
            return OGO_MC_Legacy::nashregion_replacement($info);
        }
        if ($path === '/nashregion' || $path === '/nashregion/') $destination = 'https://ogo.ua/rubrics/view/region/';
        elseif (preg_match('~^/(?:nashregion/)?rubrics/view/([^/]+)(?:/page:[0-9]+)?/?$~D', $path, $m) && isset(self::RUBRICS[$m[1]])) $destination = 'https://ogo.ua/tag/'.self::RUBRICS[$m[1]].'/';
        else return array(null, 'невідома рубрика або формат адреси «Наш регіон»');
        // Change the origin/path only: preserve query escaping, quotes and spacing.
        $url = preg_replace('~^(\s*)(?:(?i:https?://|//)(?i:www\.)?(?i:ogo\.ua)(?::(?:80|443))?)?'.preg_quote($path, '~').'(?=[?#\s]|$)~', '$1'.$destination, $info['raw'], 1, $count);
        if (!$count) return array(null, 'нестандартне кодування адреси');
        if (($p['fragment'] ?? '') === 'page-top') {
            $url = preg_replace('~#page-top(?=\s*$)~', '', $url, 1, $count);
            if (!$count) return array(null, 'нестандартне кодування якоря page-top');
        }
        return array($url, null);
    }
    public static function analyze($content) {
        $masked = preg_replace_callback('~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?(?:</\1\s*>|$)~i', function($m) { return str_repeat(' ', strlen($m[0])); }, $content);
        preg_match_all('~'.self::TAG.'~', $masked, $tokens, PREG_OFFSET_CAPTURE);
        $stack = array(); $edits = array(); $total = 0; $reasons = array();
        foreach ($tokens[0] as $token) {
            if (preg_match('~^<a(?=[\s/>])~i', $token[0])) {
                $tag = substr($content, $token[1], strlen($token[0]));
                $info = OGO_MC_Engine::href_info($tag);
                // PHP may reject root-relative paths containing a colon (page:N).
                if ($info && $info['parts'] === false) {
                    $relative = trim(html_entity_decode($info['raw'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if (substr($relative, 0, 1) === '/' && substr($relative, 0, 2) !== '//') {
                        $parts = parse_url('https://ogo.ua'.$relative);
                        if (is_array($parts)) { unset($parts['scheme'], $parts['host']); $info['parts'] = $parts; }
                    }
                }
                $target = self::candidate($info);
                if ($target) ++$total;
                $invalid = !empty($stack) || preg_match('~/\s*>$~', $tag);
                if ($stack) foreach ($stack as &$open) $open['invalid'] = true;
                unset($open);
                $stack[] = array('offset'=>$token[1], 'info'=>$info, 'target'=>$target, 'invalid'=>$invalid);
            } elseif (preg_match('~^</a\s*>$~i', $token[0]) && $stack) {
                $open = array_pop($stack);
                if (!$open['target']) continue;
                if ($open['invalid']) { $reason = 'вкладене або пошкоджене посилання'; $reasons[$reason] = ($reasons[$reason] ?? 0) + 1; continue; }
                list($url, $reason) = self::replacement($open['info']);
                if ($url === null) { $reasons[$reason] = ($reasons[$reason] ?? 0) + 1; continue; }
                $edits[] = array($open['offset'] + $open['info']['offset'], $open['info']['length'], $url);
            }
        }
        foreach ($stack as $open) if ($open['target']) { $reason = 'незакрите посилання'; $reasons[$reason] = ($reasons[$reason] ?? 0) + 1; }
        usort($edits, function($a, $b) { return $b[0] <=> $a[0]; });
        $after = $content;
        foreach ($edits as $edit) $after = substr_replace($after, $edit[2], $edit[0], $edit[1]);
        $changed = count($edits); $summary = 'Замінено регіональних URL: '.$changed.'.';
        foreach ($reasons as $reason=>$count) $summary .= ' Перевірити: '.$reason.' — '.$count.'.';
        return array('after'=>$after, 'total'=>$total, 'removed'=>$changed, 'review'=>$total-$changed, 'fragments'=>array(), 'summary'=>$summary);
    }
}
