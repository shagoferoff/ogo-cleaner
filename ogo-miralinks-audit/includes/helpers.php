<?php
/** Small read-only parsing and CSV helpers; no cleanup engine is included. */
final class OGO_MLA_HTML {
    public static function href_info($tag) {
        if (!preg_match('~^<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', $tag, $m)) return null;
        preg_match_all('~([^\s=<>/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?~', substr($m[0], 2, -1), $attrs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $hrefs = array();
        foreach ($attrs as $a) {
            if (strtolower($a[1][0]) !== 'href') continue;
            $value = null;
            foreach (array(2,3,4) as $i) if (isset($a[$i][0])) { $value = $a[$i]; break; }
            if ($value === null) return null;
            $raw = $value[0];
            $url = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (substr($url, 0, 2) === '//') $url = 'https:' . $url;
            $hrefs[] = array('parts'=>parse_url($url), 'raw'=>$raw, 'offset'=>2+$value[1], 'length'=>strlen($raw));
        }
        return count($hrefs) === 1 ? $hrefs[0] : null;
    }
}
final class OGO_MLA_CSV {
    public static function csv_row($stream, $values) {
        $safe = array_map(function($v) {
            $v = (string)$v;
            return preg_match('/^[\x00-\x20]*[=+@-]/', $v) ? "'".$v : $v;
        }, $values);
        return fputcsv($stream, $safe, ',', '"', '');
    }
}
