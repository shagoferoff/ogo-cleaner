<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
$old = 'https://ogo.ua/rubrics/view/pets';
$new = 'https://ogo.ua/tag/domashni-tvaryny/';
$count = 0;
function check_pets($input, $expected, $total, $changed, $review) {
    global $count;
    $r = OGO_MC_Engine::analyze($input, 'pets_urls');
    foreach (array('after'=>$expected,'total'=>$total,'removed'=>$changed,'review'=>$review) as $key=>$value) {
        if ($r[$key] !== $value) throw new RuntimeException('Pets case '.($count+1).' failed: '.$key.' '.var_export($r,true));
    }
    $again = OGO_MC_Engine::analyze($r['after'], 'pets_urls');
    if ($again['after'] !== $r['after'] || $again['removed'] !== 0) throw new RuntimeException('Pets repeat-run failed');
    ++$count;
}
foreach (array($old, $old.'/', 'http://ogo.ua/rubrics/view/pets', 'https://www.ogo.ua/rubrics/view/pets', '//ogo.ua/rubrics/view/pets', '/rubrics/view/pets', 'HTTPS://OGO.UA/rubrics/view/pets', 'https://ogo.ua:443/rubrics/view/pets') as $url) {
    foreach (array('"', "'", '') as $quote) {
        $html = '<p>Читайте <a class="pets" href='.$quote.$url.$quote.' target="_blank"><b>ДОМАШНІ ТВАРИНИ</b></a>.</p>';
        check_pets($html, str_replace($url,$new,$html),1,1,0);
    }
}
$html = '<!-- wp:paragraph --><p><a title="a > b" href=" '.$old.'/?x=1&amp;y=2#care ">Тварини</a> <a href="'.$old.'">Улюбленці</a></p><!-- /wp:paragraph -->';
check_pets($html,str_replace(array($old.'/', $old),$new,$html),2,2,0);
foreach (array('https://evil.example/rubrics/view/pets', 'https://ogo.ua.evil.example/rubrics/view/pets', 'https://user@ogo.ua/rubrics/view/pets', 'https://ogo.ua:444/rubrics/view/pets', $old.'-other', $old.'/more', 'https://ogo.ua/rubrics/view/goroskop', 'https://ogo.ua/rubrics/view/garden', 'https://ogo.ua/rubrics/view/kub', $new) as $url) {
    $html = '<a href="'.$url.'">Тварини</a>';
    check_pets($html,$html,0,0,0);
}
foreach (array('script','style','textarea','pre','code') as $element) {
    $html = '<'.$element.'><a href="'.$old.'">Тварини</a></'.$element.'>';
    check_pets($html,$html,0,0,0);
}
$html = '<!-- <a href="'.$old.'">Тварини</a> -->';
check_pets($html,$html,0,0,0);
foreach (array('<a href="'.$old.'">Тварини', '<a href="'.$old.'"><a href="https://example.com">Тварини</a></a>') as $html) check_pets($html,$html,1,0,1);
$html = '<a href="'.$old.'">Тварини</a><a href="'.$old.'">незакрите';
check_pets($html,'<a href="'.$new.'">Тварини</a><a href="'.$old.'">незакрите',2,1,1);
// Older rubric operations must not touch the new pets links.
foreach (array('garden_urls','horoscope_urls') as $project) {
    $html = '<a href="'.$old.'">Тварини</a>';
    $r = OGO_MC_Engine::analyze($html,$project);
    if ($r['after'] !== $html || $r['total'] !== 0) throw new RuntimeException('Pets isolation failed: '.$project);
    ++$count;
}
echo 'Passed '.$count." pets functional cases (including repeat-run and isolation checks).\n";
