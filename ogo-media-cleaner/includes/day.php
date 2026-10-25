<?php
/** Day recommendations are deleted; links in prose retain their literal contents. */
final class OGO_MC_Day {
    private const TAG = '<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private static function mask($s) {
        return preg_replace_callback('~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?(?:</\1\s*>|$)~i', function($m) { return str_repeat(' ', strlen($m[0])); }, $s);
    }
    private static function text($s) {
        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
    private static function count($s) {
        preg_match_all('~<a(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', self::mask($s), $tags);
        $n = 0;
        foreach ($tags[0] as $tag) if (OGO_MC_Engine::target($tag, 'day')) ++$n;
        return $n;
    }
    private static function removable($s) {
        // Balanced, simple inline markup only. Nested anchors are accepted here
        // only when every anchor belongs to day and the whole unit is removable.
        preg_match_all('~'.self::TAG.'~', $s, $tags, PREG_OFFSET_CAPTURE);
        $stack = array(); $depth = 0; $links = 0; $cursor = 0;
        $prefix = ''; $suffix = ''; $label = '';
        foreach ($tags[0] as $token) {
            $text = substr($s, $cursor, $token[1] - $cursor);
            if ($depth) $label .= $text;
            elseif ($links) $suffix .= $text;
            else $prefix .= $text;
            $tag = $token[0]; $cursor = $token[1] + strlen($tag);
            if (!preg_match('~^<(/?)(a|b|strong|em|i|u|s|span|small|br)(?=[\s/>])~i', $tag, $m)) return false;
            $name = strtolower($m[2]);
            if ($name === 'br' && !$m[1]) {
                if ($depth) $label .= ' '; elseif ($links) $suffix .= ' '; else $prefix .= ' ';
                continue;
            }
            if ($m[1]) {
                if (array_pop($stack) !== $name) return false;
                if ($name === 'a') --$depth;
            } else {
                if (preg_match('~/\s*>$~', $tag)) return false;
                if ($name === 'a') {
                    if (!OGO_MC_Engine::target($tag, 'day')) return false;
                    ++$depth; ++$links;
                }
                $stack[] = $name;
            }
        }
        if ($stack || !$links) return false;
        $suffix .= substr($s, $cursor);
        if (strpos($label.$prefix.$suffix, '<') !== false || strpos($label.$prefix.$suffix, '[') !== false) return false;
        if (self::text($label) === '') return false;
        $prefix = self::text($prefix); $suffix = self::text($suffix);
        if ($prefix === '' && preg_match('/^[.!?;,:–—-]*$/u', $suffix)) return true;
        if (!preg_match('/^[.!?]*$/u', $suffix)) return false;
        return preg_match('/^(?:За матеріалами\s*:?|Продовження(?: інтерв[\x{0027}’ʼ]ю)?(?: та більше фото)? (?:читайте|переглядайте) на сайті\s*:?|Більше фото (?:дивіться|переглядайте) на сайті\s*:?)$/iu', $prefix) === 1;
    }
    private static function lines($s) {
        // Delimit only outside inline markup; even blank lines before </a> stay inside.
        preg_match_all('~'.self::TAG.'|\r\n|\r|\n~', $s, $tokens, PREG_OFFSET_CAPTURE);
        $stack = array(); $start = 0; $units = array();
        foreach ($tokens[0] as $t) {
            $tag = $t[0]; $boundary = false;
            if ($tag[0] !== '<') $boundary = !$stack;
            elseif (preg_match('~^<br\s*/?>$~i', $tag)) $boundary = !$stack;
            elseif (preg_match('~^<(/?)(a|b|strong|em|i|u|s|span|small)(?=[\s/>])~i', $tag, $m)) {
                if ($m[1]) { if ($stack && end($stack) === strtolower($m[2])) array_pop($stack); }
                else $stack[] = strtolower($m[2]);
            }
            if ($boundary) {
                $units[] = array($start, $t[1] - $start, $t[1] + strlen($tag) - $start);
                $start = $t[1] + strlen($tag);
            }
        }
        $units[] = array($start, strlen($s) - $start, strlen($s) - $start);
        return $units;
    }
    private static function fragment_edits($s, $offset, &$edits) {
        $masked = self::mask($s);
        foreach (self::lines($s) as $line) {
            $fragment = substr($s, $line[0], $line[1]);
            if ($fragment !== substr($masked, $line[0], $line[1])) continue;
            if (self::removable($fragment)) $edits[] = array($offset + $line[0], $line[2]);
        }
    }
    private static function removable_paragraph($s) {
        if (self::removable($s)) return true;
        $found = false;
        foreach (self::lines($s) as $line) {
            $fragment = substr($s, $line[0], $line[1]);
            if (self::text($fragment) === '') continue;
            if (!self::removable($fragment)) return false;
            $found = true;
        }
        return $found;
    }
    public static function analyze($content) {
        $masked = self::mask($content); $total = self::count($content);
        $edits = array(); $cursor = 0; $block = strpos($content, '<!-- wp:') !== false;
        preg_match_all('~<p\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>([\s\S]*?)</p\s*>~i', $masked, $paragraphs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($paragraphs as $p) {
            $start = $p[0][1]; $length = strlen($p[0][0]);
            if (!$block) self::fragment_edits(substr($content, $cursor, $start - $cursor), $cursor, $edits);
            $inner = substr($content, $p[1][1], strlen($p[1][0]));
            if (self::removable_paragraph($inner)) {
                // Remove a complete Gutenberg paragraph including its metadata.
                if ($block && preg_match('~<!-- wp:paragraph(?:\s+\{[^\r\n]*\})?\s*-->\s*$~i', substr($content, 0, $start), $open, PREG_OFFSET_CAPTURE)
                    && preg_match('~^\s*<!-- /wp:paragraph\s*-->~i', substr($content, $start + $length), $close)) {
                    $length += $start - $open[0][1] + strlen($close[0]); $start = $open[0][1];
                } elseif ($block) {
                    // Other block types keep their structure and receive link unwrapping.
                    $cursor = $p[0][1] + strlen($p[0][0]); continue;
                }
                $edits[] = array($start, $length);
            } else self::fragment_edits($inner, $p[1][1], $edits);
            $cursor = $p[0][1] + strlen($p[0][0]);
        }
        if (!$block) self::fragment_edits(substr($content, $cursor), $cursor, $edits);
        usort($edits, function($a, $b) { return $b[0] <=> $a[0]; });
        $after = $content; $fragments = array();
        foreach ($edits as $edit) {
            $fragments[] = substr($content, $edit[0], $edit[1]);
            $after = substr_replace($after, '', $edit[0], $edit[1]);
        }
        $deleted = $total - self::count($after);
        $unwrapped = OGO_MC_Engine::unwrap_links($after, self::mask($after), 'day');
        $removed = $deleted + $unwrapped['removed'];
        return array('after'=>$unwrapped['after'], 'total'=>$total, 'removed'=>$removed,
            'review'=>max(0, $total - $removed), 'fragments'=>$fragments,
            'summary'=>'Видалено посилань разом із рекомендаціями/джерелами: '.$deleted.'. Знято зі збереженням тексту: '.$unwrapped['removed'].'.');
    }
}
