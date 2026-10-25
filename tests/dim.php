<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
$count = 0;
function check_dim($input, $expected, $total, $changed, $review) {
    global $count;
    $r = OGO_MC_Engine::analyze($input,'dim_urls');
    foreach (array('after'=>$expected,'total'=>$total,'removed'=>$changed,'review'=>$review) as $key=>$value) {
        if ($r[$key] !== $value) throw new RuntimeException('Dim case '.($count+1).' failed: '.$key.' '.var_export($r,true));
    }
    $again = OGO_MC_Engine::analyze($r['after'],'dim_urls');
    if ($again['after'] !== $r['after'] || $again['removed'] !== 0) throw new RuntimeException('Dim repeat-run failed');
    ++$count;
}
$new = 'https://ogo.ua/tag/dim/';
foreach (array('/rubrics/view/dim','/rubrics/view/dim/','/rubrics/view/dim/page:16','/rubrics/view/dim/page:16/','/rubrics/view/dim/page:1','/rubrics/view/dim/page:0','/rubrics/view/dim/page:999999999999999999999999') as $path) {
    $html = '<p><a href="https://ogo.ua'.$path.'" class="link"><strong>ДІМ</strong></a></p>';
    check_dim($html,'<p><a href="'.$new.'" class="link"><strong>ДІМ</strong></a></p>',1,1,0);
}
foreach (array('http://ogo.ua','https://www.ogo.ua','//ogo.ua','//www.ogo.ua','HTTPS://OGO.UA','https://ogo.ua:443','http://ogo.ua:80','') as $origin) {
    foreach (array('"', "'", '') as $quote) {
        $html = '<A title="a > b" HREF='.$quote.$origin.'/rubrics/view/dim/page:16'.$quote.' target="_blank">Дім</A>';
        check_dim($html,'<A title="a > b" HREF='.$quote.$new.$quote.' target="_blank">Дім</A>',1,1,0);
    }
}
$html = '<!-- wp:paragraph --><p><a href=" https://ogo.ua/rubrics/view/dim/?x=1&amp;y=2#section ">Дім</a> <a href="/rubrics/view/dim/page:16?x=1#tips">Поради</a></p><!-- /wp:paragraph -->';
check_dim($html,'<!-- wp:paragraph --><p><a href=" '.$new.'?x=1&amp;y=2#section ">Дім</a> <a href="'.$new.'?x=1#tips">Поради</a></p><!-- /wp:paragraph -->',2,2,0);
foreach (array('https://evil.example/rubrics/view/dim','https://ogo.ua.evil.example/rubrics/view/dim','https://user@ogo.ua/rubrics/view/dim','https://ogo.ua:444/rubrics/view/dim','/rubrics/view/dimensions','/rubrics/view/dim/page:16/extra','/rubrics/view/dim/page:abc','/rubrics/view/dim/page:-1','/rubrics/view/garden/page:16','/rubrics/view/goroskop','/rubrics/view/pets',$new) as $url) {
    $html = '<a href="'.$url.'">Дім</a>';
    check_dim($html,$html,0,0,0);
}
foreach (array('script','style','textarea','pre','code') as $element) {
    $html = '<'.$element.'><a href="/rubrics/view/dim/page:16">Дім</a></'.$element.'>';
    check_dim($html,$html,0,0,0);
}
$html = '<!-- <a href="/rubrics/view/dim/page:16">Дім</a> -->';
check_dim($html,$html,0,0,0);
foreach (array('<a href="/rubrics/view/dim/page:16">Дім', '<a href="/rubrics/view/dim/page:16"><a href="https://example.com">Дім</a></a>') as $html) check_dim($html,$html,1,0,1);
$html = '<a href="/rubrics/view/dim/">Дім</a><a href="/rubrics/view/dim/page:16">незакрите';
check_dim($html,'<a href="'.$new.'">Дім</a><a href="/rubrics/view/dim/page:16">незакрите',2,1,1);
// Pagination support is opt-in: earlier rubric projects keep their old scope.
foreach (array('garden'=>'garden_urls','goroskop'=>'horoscope_urls','pets'=>'pets_urls') as $rubric=>$project) {
    $html = '<a href="/rubrics/view/'.$rubric.'/page:16">Інша рубрика</a>';
    $r = OGO_MC_Engine::analyze($html,$project);
    if ($r['after'] !== $html || $r['total'] !== 0) throw new RuntimeException('Unexpected pagination expansion: '.$project);
    $html = '<a href="/rubrics/view/dim/page:16">Дім</a>';
    $r = OGO_MC_Engine::analyze($html,$project);
    if ($r['after'] !== $html || $r['total'] !== 0) throw new RuntimeException('Dim scope regression: '.$project);
    ++$count;
}
echo 'Passed '.$count." Dim functional cases (including pagination, repeat-run and isolation checks).\n";
