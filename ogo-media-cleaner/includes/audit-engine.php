<?php
/** Read-only inventory. No HTTP requests, post lookups or content transformations. */
final class OGO_MC_Audit_Engine {
    private const TAG = '<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private const ACTIVE = array('exclusive','life','nadzvychaini','region','opytuvannya','consumer','top','ukraine','foto-dnya');
    public static function categories() {
        return array('unknown_rubric'=>'Невідомі рубрики','known'=>'Відомі заміни','old_article'=>'Старі адреси статей','other'=>'Інші внутрішні адреси','active'=>'Активні рубрики');
    }
    private static function internal($host) {
        return $host === 'ogo.ua' || substr($host, -7) === '.ogo.ua' || $host === 'ogo.rv.ua' || substr($host, -10) === '.ogo.rv.ua';
    }
    private static function path($path) {
        $out = array();
        foreach (explode('/', $path) as $index=>$part) {
            if ($index === 0 || $part === '.') continue;
            if ($part === '..') { if ($out) array_pop($out); continue; }
            $out[] = $part;
        }
        $result = '/'.implode('/', $out);
        if (preg_match('~/(?:\.|\.\.)$~D', $path) && substr($result, -1) !== '/') $result .= '/';
        return $result;
    }
    public static function resolve($raw, $base = 'https://ogo.ua/') {
        $url = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || $url[0] === '#' || preg_match('/[\x00-\x20<>]/', $url)) return null;
        if (substr($url, 0, 2) === '//') $url = 'https:'.$url;
        elseif (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            $b = parse_url($base);
            if (!$b || empty($b['host']) || !self::internal(strtolower($b['host']))) return null;
            $origin = ($b['scheme'] ?? 'https').'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');
            if ($url[0] === '/') $url = $origin.$url;
            elseif ($url[0] === '?') $url = $origin.($b['path'] ?? '/').$url;
            else {
                $path = $b['path'] ?? '/';
                $dir = substr($path, 0, strrpos($path, '/') + 1);
                $url = $origin.$dir.$url;
            }
        }
        $p = parse_url($url);
        if (!$p || !isset($p['scheme'], $p['host']) || !in_array(strtolower($p['scheme']), array('http','https'), true) || isset($p['user']) || isset($p['pass'])) return null;
        $host = strtolower($p['host']);
        if (!self::internal($host)) return null;
        if (substr($host, 0, 4) === 'www.') $host = substr($host, 4);
        $port = $p['port'] ?? null;
        if (($port === 80 && strtolower($p['scheme']) === 'http') || ($port === 443 && strtolower($p['scheme']) === 'https')) $port = null;
        $p['host'] = $host; $p['port'] = $port; $p['path'] = self::path($p['path'] ?? '/');
        $p['url'] = $url;
        return $p;
    }
    public static function classify($raw, $base = 'https://ogo.ua/') {
        $p = self::resolve($raw, $base);
        if (!$p) return null;
        $path = $p['path']; $host = $p['host']; $main = $host === 'ogo.ua' && $p['port'] === null;
        $category = 'other'; $target = ''; $action = 'Актуальність не перевірено; переглянути за потреби'; $group_path = rtrim($path, '/') ?: '/';
        $rubric = preg_match('~^(.*?/rubrics/view/)([^/]+)(?:/page:[0-9]+)?/?$~D', $path, $m) ? $m : null;
        if ($rubric) {
            $group_path = $rubric[1].$rubric[2];
            $category = 'unknown_rubric'; $action = 'Визначити призначення рубрики та відповідник';
            if ($main && $rubric[1] === '/rubrics/view/' && in_array($rubric[2], self::ACTIVE, true)) {
                $category = 'active'; $action = 'Залишити: рубрика є у списку активних';
            } elseif ($main) {
                require_once __DIR__.'/nashregion.php';
                require_once __DIR__.'/woman.php';
                require_once __DIR__.'/rubric.php';
                require_once __DIR__.'/editorial.php';
                $slug = $rubric[2]; $prefix = $rubric[1];
                $regions = OGO_MC_Nashregion::rubric_map(); $women = OGO_MC_Woman::rubric_map(); $rules = OGO_MC_Rubric::rules();
                $editorial = OGO_MC_Editorial::destination_for_path($path);
                if ($editorial !== null) {
                    $target = $editorial; $action = 'Є карта: Погоджені рубрики — в теги';
                } elseif (in_array($prefix, array('/rubrics/view/','/nashregion/rubrics/view/'), true) && isset($regions[$slug])) {
                    $target = 'https://ogo.ua/tag/'.$regions[$slug].'/'; $action = 'Є карта: Регіони — рубрики → теги';
                } elseif (in_array($prefix, array('/rubrics/view/','/woman/rubrics/view/'), true) && isset($women[$slug])) {
                    $target = 'https://ogo.ua/tag/'.$women[$slug].'/'; $action = 'Є карта: Жіночий клуб — заміна URL';
                } elseif ($prefix === '/rubrics/view/' && isset($rules[$slug])) {
                    $target = 'https://ogo.ua/tag/'.$rules[$slug]['tag'].'/'; $action = 'Є карта: '.$rules[$slug]['label'].' — рубрика → тег';
                    if (strpos($path, '/page:') !== false && empty($rules[$slug]['pagination'])) $action .= '; для page:N потрібно розширити правило';
                }
                if ($target !== '') $category = 'known';
            }
        } elseif ($main && preg_match('~^/woman(?:/page:[0-9]+)?/?$~D', $path)) {
            $category = 'known'; $group_path = '/woman'; $target = 'https://ogo.ua/tag/zhinochyj-klub/'; $action = 'Є карта: Жіночий клуб — заміна URL';
        } elseif ($main && in_array($path, array('/nashregion','/nashregion/'), true)) {
            $category = 'known'; $group_path = '/nashregion'; $target = 'https://ogo.ua/rubrics/view/region/'; $action = 'Є карта: Регіони — рубрики → теги';
        } elseif (preg_match('~^/articles/view(?:/|$)~i', $path) || preg_match('~^/(?:[^/]+/)?[0-9]{4}-[0-9]{2}-[0-9]{2}/[1-9][0-9]*\.html$~D', $path)) {
            $category = 'old_article'; $action = 'Перевірити збережений ID, доступність статті й актуальний permalink';
        } elseif ($main && preg_match('~^/dossier(?:/|$)~', $path)) {
            require_once __DIR__.'/dossier.php';
            $map = OGO_MC_Dossier::map();
            if (isset($map[$group_path])) { $category = 'known'; $target = $map[$group_path]; $action = 'Є карта: Досьє ОГО → Рівняни'; }
            else $action = 'Досьє: перевірити відповідник у режимі Досьє ОГО → Рівняни';
        } elseif (in_array($host, array('media.ogo.ua','blog.ogo.ua','day.ogo.ua','catalog.ogo.ua'), true)) {
            $action = 'Старий підпроєкт: переглянути відповідний режим очищення плагіну';
        }
        // Queries remain distinct on other pages (notably /?p=ID).
        $query = $category === 'other' && isset($p['query']) ? '?'.$p['query'] : '';
        $group = 'https://'.$host.($p['port'] === null ? '' : ':'.$p['port']).$group_path.$query;
        return array('group_key'=>hash('sha256', $category."\n".$group), 'group_url'=>$group, 'category'=>$category, 'target_url'=>$target, 'suggestion'=>$action, 'raw_url'=>$raw, 'resolved_url'=>$p['url']);
    }
    public static function extract($content, $base = 'https://ogo.ua/') {
        // Tokenize outer tags too: an apparent <a> inside another tag's quoted
        // attribute is data, not a hyperlink. Raw/code blocks and comments are units.
        $raw = '<(script|style|textarea|pre|code)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\1\s*>|$)';
        preg_match_all('~<!--[\s\S]*?(?:-->|$)|'.$raw.'|'.self::TAG.'~i', $content, $tokens, PREG_SET_ORDER);
        $rows = array();
        foreach ($tokens as $token) {
            $tag = $token[0];
            if (!preg_match('~^<a(?=[\s/>])~i', $tag)) continue;
            $info = OGO_MC_Engine::href_info($tag);
            if (!$info) continue;
            $row = self::classify($info['raw'], $base);
            if (!$row) continue;
            $key = hash('sha256', $info['raw']);
            if (isset($rows[$key])) ++$rows[$key]['link_count'];
            else { $row['variant_key'] = $key; $row['link_count'] = 1; $rows[$key] = $row; }
        }
        return array_values($rows);
    }
    public static function csv_row($stream, $values) {
        $safe = array_map(function($v) {
            $v = (string)$v;
            return preg_match('/^[\x00-\x20]*[=+@-]/', $v) ? "'".$v : $v;
        }, $values);
        return fputcsv($stream, $safe, ',', '"', '');
    }
}
