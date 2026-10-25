<?php
/** Exact retired rubric URLs; preserve all bytes outside href. */
final class OGO_MC_Rubric {
    private const RULES = array(
        'dim' => array('tag'=>'dim', 'label'=>'Дім', 'pagination'=>true),
        'goroskop' => array('tag'=>'goroskop', 'label'=>'Гороскоп'),
        'pets' => array('tag'=>'domashni-tvaryny', 'label'=>'Домашні тварини'),
        'garden' => array('tag'=>'dacha-sad-gorod', 'label'=>'Дача, сад, город')
    );
    private const TAG = '<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private static function path_pattern($rubric) {
        return '/rubrics/view/'.preg_quote($rubric, '~').(!empty(self::RULES[$rubric]['pagination']) ? '(?:/page:[0-9]+)?' : '').'/?';
    }
    private static function candidate($info, $rubric) {
        if (!$info || !is_array($info['parts'])) return false;
        $p = $info['parts'];
        if (!preg_match('~^'.self::path_pattern($rubric).'$~D', $p['path'] ?? '')) return false;
        if (isset($p['user']) || isset($p['pass'])) return false;
        if (isset($p['host'])) {
            $scheme = strtolower($p['scheme'] ?? '');
            if (!in_array(strtolower($p['host']), array('ogo.ua', 'www.ogo.ua'), true) || !in_array($scheme, array('http', 'https'), true)) return false;
            if (isset($p['port']) && !(($scheme === 'http' && $p['port'] === 80) || ($scheme === 'https' && $p['port'] === 443))) return false;
        } elseif (isset($p['scheme']) || substr(trim($info['raw']), 0, 1) !== '/') return false;
        return true;
    }
    public static function analyze($content, $rubric) {
        if (!isset(self::RULES[$rubric])) throw new InvalidArgumentException('Unknown rubric replacement');
        $rule = self::RULES[$rubric];
        $masked = preg_replace_callback('~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?(?:</\1\s*>|$)~i', function($m) { return str_repeat(' ', strlen($m[0])); }, $content);
        preg_match_all('~'.self::TAG.'~', $masked, $tokens, PREG_OFFSET_CAPTURE);
        $stack = array(); $edits = array(); $total = 0;
        foreach ($tokens[0] as $token) {
            if (preg_match('~^<a(?=[\s/>])~i', $token[0])) {
                $tag = substr($content, $token[1], strlen($token[0]));
                $info = OGO_MC_Engine::href_info($tag);
                // PHP parse_url can reject root-relative paths containing page:N.
                // Parse those against a fixed origin, then retain only relative parts.
                if (!empty($rule['pagination']) && $info && $info['parts'] === false) {
                    $relative = trim(html_entity_decode($info['raw'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if (substr($relative, 0, 1) === '/' && substr($relative, 0, 2) !== '//') {
                        $parts = parse_url('https://ogo.ua'.$relative);
                        if (is_array($parts)) { unset($parts['scheme'], $parts['host']); $info['parts'] = $parts; }
                    }
                }
                $target = self::candidate($info, $rubric);
                if ($target) ++$total;
                $invalid = !empty($stack) || preg_match('~/\s*>$~', $tag);
                if ($stack) foreach ($stack as &$open) $open['invalid'] = true;
                unset($open);
                $stack[] = array('offset'=>$token[1], 'info'=>$info, 'target'=>$target, 'invalid'=>$invalid);
            } elseif (preg_match('~^</a\s*>$~i', $token[0]) && $stack) {
                $open = array_pop($stack);
                if (!$open['target'] || $open['invalid']) continue;
                $info = $open['info'];
                // Only literal known URLs are rewritten. Query/fragment escaping and
                // surrounding whitespace remain exactly as stored in post_content.
                $url = preg_replace('~^(\s*)(?:(?i:https?://|//)(?i:www\.)?(?i:ogo\.ua)(?::(?:80|443))?)?'.self::path_pattern($rubric).'(?=[?#\s]|$)~', '$1https://ogo.ua/tag/'.$rule['tag'].'/', $info['raw'], 1, $count);
                if ($count) $edits[] = array($open['offset'] + $info['offset'], $info['length'], $url);
            }
        }
        usort($edits, function($a, $b) { return $b[0] <=> $a[0]; });
        $after = $content;
        foreach ($edits as $edit) $after = substr_replace($after, $edit[2], $edit[0], $edit[1]);
        $changed = count($edits); $review = $total - $changed;
        return array('after'=>$after, 'total'=>$total, 'removed'=>$changed, 'review'=>$review, 'fragments'=>array(),
            'summary'=>'Замінено посилань рубрики «'.$rule['label'].'» на тег: '.$changed.'.'.($review ? ' Неоднозначні або пошкоджені посилання залишено для ручної перевірки: '.$review.'.' : ''));
    }
}
