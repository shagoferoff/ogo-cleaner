<?php
/** Byte-preserving, conservative removal. No DOM reserialization. */
final class OGO_MC_Engine {
    private const TAG = '<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>';
    private const ANCHOR = '~<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?</a\s*>~i';
    public static function projects() {
        return array(
            'audit' => array('label'=>'Ревізія старих посилань', 'hosts'=>array('ogo.ua','ogo.rv.ua'), 'photo_callouts'=>false),
            'media' => array('label'=>'Медіа', 'hosts'=>array('media.ogo.ua','www.media.ogo.ua'), 'photo_callouts'=>true),
            'media_unwrap' => array('label'=>'Медіа — зняття посилань, зберегти текст', 'hosts'=>array('media.ogo.ua','www.media.ogo.ua'), 'photo_callouts'=>false),
            'blog' => array('label'=>'Блоги', 'hosts'=>array('blog.ogo.ua','www.blog.ogo.ua'), 'photo_callouts'=>false),
            'day' => array('label'=>'Рівне святкове — очищення посилань', 'hosts'=>array('day.ogo.ua'), 'photo_callouts'=>false),
            'day_urls' => array('label'=>'Рівне святкове — заміна URL', 'hosts'=>array('day.ogo.ua'), 'photo_callouts'=>false),
            'gazeta' => array('label'=>'Рівненська газета', 'hosts'=>array('gazeta.rv.ua','www.gazeta.rv.ua'), 'photo_callouts'=>false),
            'catalog' => array('label'=>'Каталог — зняття посилань', 'hosts'=>array('catalog.ogo.ua'), 'photo_callouts'=>false),
            'ustream' => array('label'=>'Ustream — видалення плеєрів і підписів', 'hosts'=>array('ustream.tv','www.ustream.tv'), 'photo_callouts'=>false),
            'editorial_urls' => array('label'=>'Погоджені рубрики — в теги', 'hosts'=>array('ogo.ua','www.ogo.ua'), 'photo_callouts'=>false),
            'woman_urls' => array('label'=>'Жіночий клуб — заміна URL', 'hosts'=>array('ogo.ua','www.ogo.ua'), 'photo_callouts'=>false),
            'nashregion_urls' => array('label'=>'Регіони — рубрики → теги', 'hosts'=>array('ogo.ua','www.ogo.ua'), 'photo_callouts'=>false),
            'dim_urls' => array('label'=>'Дім — рубрика → тег', 'hosts'=>array('ogo.ua','www.ogo.ua'), 'photo_callouts'=>false),
            'pets_urls' => array('label'=>'Домашні тварини — рубрика → тег', 'hosts'=>array('ogo.ua','www.ogo.ua'), 'photo_callouts'=>false),
            'garden_urls' => array('label'=>'Дача, сад, город — рубрика → тег', 'hosts'=>array('ogo.ua','www.ogo.ua'), 'photo_callouts'=>false),
            'horoscope_urls' => array('label'=>'Гороскоп — рубрика → тег', 'hosts'=>array('ogo.ua','www.ogo.ua'), 'photo_callouts'=>false),
            'legacy_urls' => array('label'=>'ОГО — старі URL → актуальні', 'hosts'=>array('ogo.ua','www.ogo.ua'), 'photo_callouts'=>false),
            'dossier_urls' => array('label'=>'Досьє ОГО → Рівняни', 'hosts'=>array('ogo.ua','www.ogo.ua','ogo.rv.ua','www.ogo.rv.ua'), 'photo_callouts'=>false),
            'vk' => array('label'=>'ВКонтакте — очищення посилань', 'hosts'=>array('vk.com','www.vk.com'), 'photo_callouts'=>false)
        );
    }
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
    public static function email_target($tag, $email) {
        $info=self::href_info($tag);
        return $info && is_array($info['parts']) && strtolower($info['parts']['scheme']??'')==='mailto' && strtolower($info['parts']['path']??'')===strtolower($email);
    }
    private static function migrated_path($parts) {
        return isset($parts['path']) && preg_match('~^/articles/view(?:/|$)~i', rawurldecode($parts['path'])) === 1;
    }
    public static function target($tag, $project = 'media') {
        $projects = self::projects();
        if (!isset($projects[$project])) return false;
        $info = self::href_info($tag);
        if (!$info) return false;
        $parts = $info['parts'];
        $valid = is_array($parts) && isset($parts['host'], $parts['scheme']) && in_array(strtolower($parts['scheme']), array('http','https'), true) && in_array(strtolower($parts['host']), $projects[$project]['hosts'], true) && !isset($parts['user']) && !isset($parts['pass']);
        if (!$valid) return false;
        if ($project === 'day') return !self::migrated_path($parts);
        if ($project === 'day_urls') return self::migrated_path($parts);
        return true;
    }
    private static function replace_day_urls($content, $masked) {
        preg_match_all('~<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', $masked, $tags, PREG_OFFSET_CAPTURE);
        $after = $content; $total = 0; $changed = 0; $fragments = array();
        foreach (array_reverse($tags[0]) as $tag) {
            $original = substr($content, $tag[1], strlen($tag[0]));
            if (!self::target($original, 'day_urls')) continue;
            ++$total;
            $info = self::href_info($original);
            // Replace just the origin in the literal href; retain query escaping,
            // path, fragment, quoting, label and every other attribute byte-for-byte.
            $url = preg_replace('~^(\s*)(?:https?:)?//day\.ogo\.ua(?=/articles/view/)~i', '$1https://ogo.ua', $info['raw'], 1, $count);
            if (!$count || $url === $info['raw']) continue;
            $after = substr_replace($after, $url, $tag[1]+$info['offset'], $info['length']);
            $fragments[] = $original; ++$changed;
        }
        return array('after'=>$after,'total'=>$total,'removed'=>$changed,'review'=>$total-$changed,'fragments'=>$fragments);
    }
    private static function blank($s) {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match('/^[\s\x{00a0}]*$/u', $s) === 1;
    }
    private static function media_mention_count($content) {
        preg_match_all('~(?:https?:)?//(?:www\.)?media\.ogo\.ua\b|(?:www\.)?media\.ogo\.ua\b~iu', $content, $matches);
        return count($matches[0]);
    }
    public static function unwrap_links($content, $masked, $project = 'catalog', $excluded = array()) {
        preg_match_all('~'.self::TAG.'~', $masked, $tokens, PREG_OFFSET_CAPTURE);
        $stack=array(); $ranges=array(); $total=0; $changed=0;
        foreach ($tokens[0] as $token) {
            if (preg_match('~^<a(?=[\s/>])~i', $token[0])) {
                $target=self::target($token[0], $project);
                if ($target) ++$total;
                // Nested anchors are invalid HTML. Preserve every affected anchor.
                $invalid=!empty($stack) || preg_match('~/\s*>$~', $token[0]);
                if ($stack) foreach ($stack as &$open) $open['invalid']=true;
                unset($open);
                $stack[]=array('offset'=>$token[1], 'length'=>strlen($token[0]), 'target'=>$target, 'invalid'=>$invalid);
            } elseif (preg_match('~^</a\s*>$~i', $token[0]) && $stack) {
                $open=array_pop($stack);
                if (!$open['target'] || $open['invalid']) continue;
                $blocked=false;
                foreach($excluded as $range) if($open['offset']<$range[1] && $token[1]+strlen($token[0])>$range[0])$blocked=true;
                if($project==='vk') {
                    $inside=substr($content,$open['offset']+$open['length'],$token[1]-$open['offset']-$open['length']);
                    $label=trim(html_entity_decode(preg_replace('~'.self::TAG.'~','',$inside),ENT_QUOTES|ENT_HTML5,'UTF-8'));
                    if(preg_match('~^(?:https?://)?(?:www\.)?vk\.com(?:/[^\s]*)?/?$~iu',$label))$blocked=true;
                }
                if($blocked)continue;
                $ranges[]=array($open['offset'], $open['length']);
                $ranges[]=array($token[1], strlen($token[0]));
                ++$changed;
            }
        }
        usort($ranges,function($a,$b){return $b[0]<=>$a[0];});
        $after=$content;
        foreach($ranges as $r) $after=substr_replace($after,'',$r[0],$r[1]);
        if ($project === 'media_unwrap') {
            $remaining = self::media_mention_count($after);
            return array(
                'after'=>$after,
                'total'=>$total + $remaining,
                'removed'=>$changed,
                'review'=>max(0,$total-$changed) + $remaining,
                'fragments'=>array(),
                'summary'=>$remaining ? 'Після зняття посилань залишаються згадки media.ogo.ua / www.media.ogo.ua. Перевірте вручну.' : ''
            );
        }
        return array('after'=>$after, 'total'=>$total, 'removed'=>$changed, 'review'=>$total-$changed, 'fragments'=>array());
    }
    private static function photo_callout($s) {
        $plain = preg_replace('~' . self::TAG . '~', '', $s);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/[\s\x{00a0}]+/u', ' ', $plain);
        // Deliberately narrow: a complete photo-gallery callout, no extra prose.
        return preg_match('/^Більше фото\s*(?:[-–—:]\s*)?(?:у|в)\s+(?:нашій\s+)?фотогалереї\s*[.!]?$/iu', trim($plain)) === 1;
    }
    private static function callout_text($s) {
        $s=preg_replace('~<br\s*/?>~i',' ',$s);
        return trim(preg_replace('/[\s\x{00a0}]+/u',' ',html_entity_decode(preg_replace('~'.self::TAG.'~','',$s),ENT_QUOTES|ENT_HTML5,'UTF-8')));
    }
    private static function media_callout($s) {
        preg_match_all(self::ANCHOR,$s,$links,PREG_OFFSET_CAPTURE);
        if(count($links[0])!==1)return false;
        $link=$links[0][0];$info=self::href_info($link[0]);
        if(!self::target($link[0],'media'))return false;
        $path=$info['parts']['path']??'';
        $prefix=self::callout_text(substr($s,0,$link[1]));
        $suffix=self::callout_text(substr($s,$link[1]+strlen($link[0])));
        if(!preg_match('/^[.!]?$/u',$suffix))return false;
        $label=self::callout_text($link[0]);
        $video=preg_match('~^/video/view/[^/]+(?:/[^/]+)?/?$~i',$path)===1;
        $gallery=preg_match('~^/gallery/view/[^/]+(?:/[^/]+)?/?$~i',$path)===1;
        if($gallery && preg_match('/^Більше\s+(?:фото|фотографій|фотографий|фотографый)\s+(?:можна|можно)\s+побачити\s+(?:у|в)\s+(?:нашій\s*)?$/iu',$prefix)
            && preg_match('/^(?:(?:у|в)\s+)?(?:нашій\s+)?галереї\s*[.!]?$/iu',$label))return true;
        if($gallery && preg_match('/^(?:Усі|Всі) конкурсні фото зібрані тут\s*[-–—:]$/iu',$prefix)
            && $label===trim(html_entity_decode($info['raw'],ENT_QUOTES|ENT_HTML5,'UTF-8')))return true;
        if($video && preg_match('/^ВІДЕО\s*[-–—:]?$/iu',$prefix) && $label!=='')return true;
        if(preg_match('/^(?:(?:у|в)\s+)?(?:нашій\s+)?(фото|відео|медіа)галереї\s*[.!]?$/iu',$label,$kind)) {
            $isVideo=preg_match('/^відео$/iu',$kind[1])===1;
            if($isVideo && !$video)return false;
            if(!$isVideo && !$gallery)return false;
            if(!$isVideo && preg_match('/^Більше\s+(?:фото|робіт)(?:\s+[^.!?;:]{1,160}?)?\s*[-–—:]\s*(?:(?:у|в)\s*)?$/iu',$prefix))return true;
            if($isVideo && preg_match('/^Переглянути відео можна$/iu',$prefix))return true;
            if($isVideo && preg_match('/^Відео\s+(?:з\s+[^.!?;:]{1,160}?|найцікавіших моментів\s+[^.!?;:]{1,160}?)\s*[-–—:]$/iu',$prefix))return true;
            if($isVideo && preg_match('/^Автогол\s+[^.!?;:]{1,100}?\s+дивіться$/iu',$prefix))return true;
        }
        return $video && preg_match('/^Для тих, хто\s+(?:із|з)\s+творчістю\s+[^.!?;:]{1,120}?\s+ще не знайомий\s*[-–—:]\s*пропонуємо$/iu',$prefix)===1
            && preg_match('/^переглянути один\s+(?:з|із)\s+відеокліпів гурту\s*[.!]?$/iu',$label)===1;
    }
    private static function standalone($s, $callout_only = false, $project = 'media') {
        // Require balanced inline markup. Never leave an orphan formatting tag.
        preg_match_all('~' . self::TAG . '~', $s, $tags);
        $stack = array();
        foreach ($tags[0] as $tag) {
            if (!preg_match('~^<(/?)(a|strong|b|em|i|span|u|s|small|br)\b~i', $tag, $m)) return false;
            $name = strtolower($m[2]);
            if ($name === 'br' && !$m[1]) continue;
            if ($m[1]) {
                if (array_pop($stack) !== $name) return false;
            } else {
                if (preg_match('~/\s*>$~', $tag)) return false;
                $stack[] = $name;
            }
        }
        if ($stack) return false;
        $count = 0; $safe = true;
        $rest = preg_replace_callback(self::ANCHOR, function($m) use (&$count, &$safe, $project) {
            if (!self::target($m[0], $project) || preg_match('~<(?:a\b[^>]*>|img\b|video\b|audio\b|iframe\b|picture\b|svg\b|script\b|object\b|embed\b)~i', substr($m[0], strpos($m[0], '>') + 1))) { $safe = false; return $m[0]; }
            // Text links may contain simple inline formatting, nothing else.
            $inside = preg_replace('~^<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>|</a\s*>$~i', '', $m[0]);
            $inside = preg_replace('~</?(?:strong|b|em|i|span|u|s|small)\b[^>]*>|<br\s*/?>~i', '', $inside);
            if (strpos($inside, '<') !== false || strpos($inside, '[') !== false) $safe = false;
            ++$count; return '';
        }, $s);
        $rest = preg_replace('~</?(?:strong|b|em|i|span|u|s|small)\b[^>]*>|<br\s*/?>~i', '', $rest);
        $projects = self::projects();
        $callout = !empty($projects[$project]['photo_callouts']) && $count === 1 && (self::photo_callout($s) || ($project==='media' && self::media_callout($s)));
        return $safe && $count > 0 && ($callout_only ? $callout : (self::blank($rest) || $callout));
    }
    private static function paragraph_callouts($inner, $offset, $project = 'media') {
        $ranges = array();
        // Do not interpret lines in raw text, comments or complex nested elements.
        if (strpos($inner, '<!--') !== false) return $ranges;
        preg_match_all('~' . self::TAG . '~', $inner, $tags);
        foreach ($tags[0] as $tag) if (!preg_match('~^</?(a|strong|b|em|i|span|u|s|small|br)(?=[\s/>])~i', $tag)) return $ranges;
        // Only explicit line boundaries inside paragraphs; ordinary prose stays intact.
        foreach (self::logical_lines($inner) as $line) {
            $fragment = substr($inner, $line[1], strlen($line[0]));
            if (!self::standalone($fragment, true, $project)) continue;
            $length = strlen($line[0]);
            if (substr($inner, $line[1] + $length, 2) === "\r\n") $length += 2;
            elseif (in_array(substr($inner, $line[1] + $length, 1), array("\n", "\r"), true)) ++$length;
            $ranges[] = array($offset + $line[1], $length);
        }
        return $ranges;
    }
    private static function logical_lines($masked) {
        // Newlines inside links/formatting do not delimit a standalone fragment.
        // Replace them only in the classification copy; all byte offsets stay intact.
        preg_match_all('~' . self::TAG . '|\r\n|\r|\n~', $masked, $tokens, PREG_OFFSET_CAPTURE);
        $stack = array();
        foreach ($tokens[0] as $token) {
            if ($token[0][0] !== '<') {
                if ($stack) $masked = substr_replace($masked, str_repeat(' ', strlen($token[0])), $token[1], strlen($token[0]));
                continue;
            }
            if (!preg_match('~^<(/?)(a|strong|b|em|i|span|u|s|small)\b~i', $token[0], $m)) continue;
            $name = strtolower($m[2]);
            if ($m[1]) {
                if ($stack && end($stack) === $name) array_pop($stack);
                // Unbalanced structures stay joined and fail standalone validation.
            } else $stack[] = $name;
        }
        preg_match_all('/[^\r\n]+/', $masked, $lines, PREG_OFFSET_CAPTURE);
        return $lines[0];
    }
    public static function analyze($content, $project = 'media', $base_rules = false) {
        if (!isset(self::projects()[$project])) throw new InvalidArgumentException('Unknown cleanup project');
        if ($project === 'audit') throw new InvalidArgumentException('Use the read-only audit engine');
        // Preserve comments, code and raw text verbatim. Mask length remains identical.
        $masked = preg_replace_callback('~<!--[\s\S]*?-->|<(script|style|textarea|pre|code)\b[^>]*>[\s\S]*?</\1\s*>~i', function($m) { return str_repeat(' ', strlen($m[0])); }, $content);
        if ($project === 'ustream') { require_once __DIR__.'/ustream.php'; return OGO_MC_Ustream::analyze($content,$masked); }
        if ($project === 'editorial_urls') { require_once __DIR__.'/editorial.php'; return OGO_MC_Editorial::analyze($content); }
        if ($project === 'woman_urls') { require_once __DIR__.'/woman.php'; return OGO_MC_Woman::analyze($content); }
        if ($project === 'nashregion_urls') { require_once __DIR__.'/nashregion.php'; return OGO_MC_Nashregion::analyze($content); }
        if ($project === 'dim_urls') { require_once __DIR__.'/rubric.php'; return OGO_MC_Rubric::analyze($content, 'dim'); }
        if ($project === 'pets_urls') { require_once __DIR__.'/rubric.php'; return OGO_MC_Rubric::analyze($content, 'pets'); }
        if ($project === 'garden_urls') { require_once __DIR__.'/rubric.php'; return OGO_MC_Rubric::analyze($content, 'garden'); }
        if ($project === 'horoscope_urls') { require_once __DIR__.'/horoscope.php'; return OGO_MC_Horoscope::analyze($content); }
        if ($project === 'legacy_urls') { require_once __DIR__.'/legacy.php'; return OGO_MC_Legacy::analyze($content,$masked); }
        if ($project === 'dossier_urls') { require_once __DIR__.'/dossier.php'; return OGO_MC_Dossier::analyze($content,$masked); }
        if ($project === 'vk') { require_once __DIR__.'/vk.php'; return OGO_MC_VK::analyze($content,$masked); }
        if (in_array($project,array('catalog','media_unwrap'),true)) return self::unwrap_links($content, $masked, $project);
        if ($project === 'gazeta' && !$base_rules) { require_once __DIR__.'/gazeta.php'; return OGO_MC_Gazeta::analyze($content,$masked); }
        if ($project === 'day_urls') return self::replace_day_urls($content, $masked);
        if ($project === 'day') { require_once __DIR__.'/day.php'; return OGO_MC_Day::analyze($content); }
        preg_match_all(self::ANCHOR, $masked, $anchors, PREG_OFFSET_CAPTURE);
        $total = 0;
        foreach ($anchors[0] as $a) if (self::target($a[0], $project)) ++$total;
        $ranges = array();
        $block = strpos($content, '<!-- wp:') !== false;
        if ($block) {
            // Only complete, unnested paragraph blocks. Other block types go to review.
            preg_match_all('~<!-- wp:paragraph(?:\s+\{[^\r\n]*\})?\s*-->\s*(<p\b[^>]*>([\s\S]*?)</p\s*>)\s*<!-- /wp:paragraph -->~i', $content, $paragraphs, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($paragraphs as $p) {
                if (strpos($p[2][0], '<!--') !== false) continue;
                if (self::standalone($p[2][0], false, $project)) $ranges[] = array($p[0][1], strlen($p[0][0]));
                else $ranges = array_merge($ranges, self::paragraph_callouts($p[2][0], $p[2][1], $project));
            }
        } else {
            preg_match_all('~<p\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>([\s\S]*?)</p\s*>~i', $masked, $paragraphs, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($paragraphs as $p) {
                $inner = substr($content, $p[1][1], strlen($p[1][0]));
                if (self::standalone($inner, false, $project)) $ranges[] = array($p[0][1], strlen($p[0][0]));
                elseif (strpos($inner, '<!--') === false && !preg_match('~<(script|style|textarea|pre|code)\b~i', $inner)) $ranges = array_merge($ranges, self::paragraph_callouts($inner, $p[1][1], $project));
            }
            // Resolve complete multiline media callouts before the line-based fallback.
            if($project==='media') {
                foreach(preg_split('/(?:\r\n|\n|\r)[ \t]*(?:\r\n|\n|\r)+/',$masked,-1,PREG_SPLIT_OFFSET_CAPTURE) as $unit) {
                    $fragment=substr($content,$unit[1],strlen($unit[0]));
                    if(self::standalone($fragment,true,'media'))$ranges[]=array($unit[1],strlen($unit[0]));
                }
            }
            // Classic editor: complete standalone fragments, including multiline links.
            $units = $project === 'day' ? array_map(function($p){ return array($p[0],$p[1]); }, preg_split('/(?:\r\n|\n|\r)[ \t]*(?:\r\n|\n|\r)+/', $masked, -1, PREG_SPLIT_OFFSET_CAPTURE)) : self::logical_lines($masked);
            foreach ($units as $line) {
                $within = false;
                foreach ($paragraphs as $p) if ($line[1] < $p[0][1]+strlen($p[0][0]) && $line[1]+strlen($line[0]) > $p[0][1]) $within = true;
                foreach($ranges as $range)if($line[1]<$range[0]+$range[1] && $line[1]+strlen($line[0])>$range[0])$within=true;
                if (!$within && self::standalone(substr($content, $line[1], strlen($line[0])), false, $project)) $ranges[] = array($line[1], strlen($line[0]));
            }
        }
        usort($ranges, function($a,$b) { return $b[0] <=> $a[0]; });
        $after = $content; $removed = 0; $fragments = array();
        foreach ($ranges as $r) {
            $fragment = substr($content, $r[0], $r[1]);
            preg_match_all(self::ANCHOR, $fragment, $links);
            foreach ($links[0] as $a) if (self::target($a, $project)) ++$removed;
            $fragments[] = $fragment;
            $after = substr_replace($after, '', $r[0], $r[1]);
        }
        $review=max(0,$total-$removed);
        $summary='';
        if ($project === 'media') {
            $remaining = self::media_mention_count($after);
            $total += $remaining;
            $review += $remaining;
            if ($remaining) $summary='Після автоматичного очищення залишаються згадки media.ogo.ua / www.media.ogo.ua. Перевірте вручну або запустіть режим зняття посилань.';
        }
        return array('after'=>$after, 'total'=>$total, 'removed'=>$removed, 'review'=>$review, 'fragments'=>$fragments, 'summary'=>$summary);
    }
}
