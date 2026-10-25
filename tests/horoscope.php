<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
$checks = 0;
function verify($input, $expected, $total, $changed, $review, $name) {
    global $checks;
    $r = OGO_MC_Engine::analyze($input, 'horoscope_urls');
    foreach (array('after'=>$expected, 'total'=>$total, 'removed'=>$changed, 'review'=>$review) as $key=>$value) {
        if ($r[$key] !== $value) throw new RuntimeException($name.' failed: '.$key.' '.var_export($r, true));
    }
    $again = OGO_MC_Engine::analyze($r['after'], 'horoscope_urls');
    if ($again['after'] !== $r['after'] || $again['removed'] !== 0) throw new RuntimeException($name.' is not idempotent');
    ++$checks;
}
$old = 'https://ogo.ua/rubrics/view/goroskop';
$new = 'https://ogo.ua/tag/goroskop/';
foreach (array($old, $old.'/', 'http://ogo.ua/rubrics/view/goroskop', 'https://www.ogo.ua/rubrics/view/goroskop', '//ogo.ua/rubrics/view/goroskop', '//www.ogo.ua/rubrics/view/goroskop/', '/rubrics/view/goroskop', '/rubrics/view/goroskop/', 'HTTPS://OGO.UA/rubrics/view/goroskop', 'http://ogo.ua:80/rubrics/view/goroskop', 'https://ogo.ua:443/rubrics/view/goroskop', '//ogo.ua:443/rubrics/view/goroskop') as $url) {
    foreach (array('"', "'", '') as $quote) {
        $tag = '<a class="horoscope" href='.$quote.$url.$quote.' target="_blank" rel="noopener"><strong>ЩОДЕННИЙ ГОРОСКОП</strong></a>';
        verify($tag, str_replace($url, $new, $tag), 1, 1, 0, 'URL/quoting '.$url);
    }
}
$tag = '<A data-note="a > b" HREF = "  '.$old.'/?x=1&amp;y=2#today  " title="Гороскоп">ЩОДЕННИЙ' . "\n" . 'ГОРОСКОП</A>';
verify($tag, str_replace($old.'/', $new, $tag), 1, 1, 0, 'Query, fragment, whitespace, markup');
$tag = '<!-- wp:paragraph {"className":"test"} --><p>Читайте <a href="'.$old.'">гороскоп</a> та <a href="'.$old.'/">прогноз</a>.</p><!-- /wp:paragraph -->';
verify($tag, str_replace(array('href="'.$old.'/"', 'href="'.$old.'"'), 'href="'.$new.'"', $tag), 2, 2, 0, 'Gutenberg, multiple edits');
foreach (array('https://evil.example/rubrics/view/goroskop', 'https://ogo.ua.evil.example/rubrics/view/goroskop', 'https://ogo.ua@evil.example/rubrics/view/goroskop', 'https://user@ogo.ua/rubrics/view/goroskop', 'https://ogo.ua:444/rubrics/view/goroskop', 'https://ogo.ua:80/rubrics/view/goroskop', 'ftp://ogo.ua/rubrics/view/goroskop', $old.'-2026', $old.'/extra', '/rubrics/view/other', '/RUBRICS/view/goroskop', $new) as $url) {
    $tag = '<a href="'.$url.'">Гороскоп</a>';
    verify($tag, $tag, 0, 0, 0, 'Ignore '.$url);
}
foreach (array('script','style','textarea','pre','code') as $element) {
    $tag = '<'.$element.'><a href="'.$old.'">Гороскоп</a></'.$element.'>';
    verify($tag, $tag, 0, 0, 0, 'Protected '.$element);
    $tag = '<'.$element.'><a href="'.$old.'">Гороскоп</a>';
    verify($tag, $tag, 0, 0, 0, 'Unfinished '.$element);
}
foreach (array('<!-- <a href="'.$old.'">Гороскоп</a> -->', '<!-- <a href="'.$old.'">Гороскоп</a>', 'URL: '.$old, '<div data-link="<a href=\''.$old.'\'>">text</div>', '<a data-href="'.$old.'" href="https://example.com/">Гороскоп</a>', '<a href="'.$old.'" href="https://example.com/">Гороскоп</a>') as $tag) {
    verify($tag, $tag, 0, 0, 0, 'Non-anchor/ambiguous attribute');
}
foreach (array('<a href="'.$old.'">незакрите', '<a href="'.$old.'">зовнішнє <a href="https://example.com">внутрішнє</a></a>', '<a href="https://example.com"><a href="'.$old.'">внутрішнє</a></a>', '<a href="'.$old.'" />', '<a href="https://ogo.ua/rubrics/view/gorosko&#112;">encoded path</a>') as $tag) {
    verify($tag, $tag, 1, 0, 1, 'Manual review malformed/nonliteral');
}
$tag = '<a href="'.$old.'">готове</a> <a href="'.$old.'">незакрите';
verify($tag, '<a href="'.$new.'">готове</a> <a href="'.$old.'">незакрите', 2, 1, 1, 'Mixed valid and review');
// Existing URL replacement and removal operations must still dispatch normally.
$r = OGO_MC_Engine::analyze('<a href="https://day.ogo.ua/articles/view/2020-01-01/1.html">day</a>', 'day_urls');
if ($r['after'] !== '<a href="https://ogo.ua/articles/view/2020-01-01/1.html">day</a>' || $r['removed'] !== 1) throw new RuntimeException('day_urls regression');
++$checks;
$r = OGO_MC_Engine::analyze('<p><a href="https://catalog.ogo.ua/a">назва</a></p>', 'catalog');
if ($r['after'] !== '<p>назва</p>' || $r['removed'] !== 1) throw new RuntimeException('catalog regression');
++$checks;
echo 'Passed '.$checks." functional cases (including repeat-run checks).\n";
