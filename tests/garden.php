<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
$old = 'https://ogo.ua/rubrics/view/garden';
$new = 'https://ogo.ua/tag/dacha-sad-gorod/';
$count = 0;
function check_garden($input, $expected, $total, $changed, $review) {
    global $count;
    $r = OGO_MC_Engine::analyze($input, 'garden_urls');
    foreach (array('after'=>$expected,'total'=>$total,'removed'=>$changed,'review'=>$review) as $key=>$value) {
        if ($r[$key] !== $value) throw new RuntimeException('Garden case '.($count+1).' failed: '.$key.' '.var_export($r,true));
    }
    $again = OGO_MC_Engine::analyze($r['after'], 'garden_urls');
    if ($again['after'] !== $r['after'] || $again['removed'] !== 0) throw new RuntimeException('Garden repeat-run failed');
    ++$count;
}
foreach (array($old, $old.'/', 'http://ogo.ua/rubrics/view/garden', 'https://www.ogo.ua/rubrics/view/garden', '//ogo.ua/rubrics/view/garden', '/rubrics/view/garden', 'HTTPS://OGO.UA/rubrics/view/garden', 'https://ogo.ua:443/rubrics/view/garden') as $url) {
    foreach (array('"', "'", '') as $quote) {
        $html = '<p>Читайте <a class="garden" href='.$quote.$url.$quote.' target="_blank"><b>ДАЧА, САД, ГОРОД</b></a>.</p>';
        check_garden($html, str_replace($url,$new,$html),1,1,0);
    }
}
$html = '<!-- wp:paragraph --><p><a title="a > b" href=" '.$old.'/?x=1&amp;y=2#care ">Сад</a> <a href="'.$old.'">Город</a></p><!-- /wp:paragraph -->';
check_garden($html,str_replace(array($old.'/', $old),$new,$html),2,2,0);
foreach (array('https://evil.example/rubrics/view/garden', 'https://ogo.ua.evil.example/rubrics/view/garden', 'https://user@ogo.ua/rubrics/view/garden', 'https://ogo.ua:444/rubrics/view/garden', $old.'s', $old.'/more', 'https://ogo.ua/rubrics/view/goroskop', $new) as $url) {
    $html = '<a href="'.$url.'">Сад</a>';
    check_garden($html,$html,0,0,0);
}
foreach (array('script','style','textarea','pre','code') as $element) {
    $html = '<'.$element.'><a href="'.$old.'">Сад</a></'.$element.'>';
    check_garden($html,$html,0,0,0);
}
$html = '<!-- <a href="'.$old.'">Сад</a> -->';
check_garden($html,$html,0,0,0);
foreach (array('<a href="'.$old.'">Сад', '<a href="'.$old.'"><a href="https://example.com">Сад</a></a>') as $html) check_garden($html,$html,1,0,1);
$html = '<a href="'.$old.'">Сад</a><a href="'.$old.'">незакрите';
check_garden($html,'<a href="'.$new.'">Сад</a><a href="'.$old.'">незакрите',2,1,1);
// Each operation must leave the other retired rubric untouched.
$html = '<a href="'.$old.'">Сад</a><a href="https://ogo.ua/rubrics/view/goroskop">Гороскоп</a>';
check_garden($html,'<a href="'.$new.'">Сад</a><a href="https://ogo.ua/rubrics/view/goroskop">Гороскоп</a>',1,1,0);
$r = OGO_MC_Engine::analyze($html,'horoscope_urls');
if ($r['after'] !== '<a href="'.$old.'">Сад</a><a href="https://ogo.ua/tag/goroskop/">Гороскоп</a>') throw new RuntimeException('Horoscope isolation failed');
++$count;
echo 'Passed '.$count." garden functional cases (including repeat-run and isolation checks).\n";
