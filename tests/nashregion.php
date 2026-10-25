<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
$lookup = array();
function get_post($id) { global $lookup; return $lookup[$id] ?? null; }
function get_permalink($post) { return $post->permalink; }
function home_url($path = '/') { return 'https://ogo.ua'.$path; }
function is_post_publicly_viewable($post) { return $post->viewable ?? true; }
$count = 0;
function check_region($input, $expected, $total, $changed, $review) {
    global $count;
    if (class_exists('OGO_MC_Legacy')) OGO_MC_Legacy::clear_cache();
    $r = OGO_MC_Engine::analyze($input, 'nashregion_urls');
    foreach (array('after'=>$expected, 'total'=>$total, 'removed'=>$changed, 'review'=>$review) as $key=>$value) {
        if ($r[$key] !== $value) throw new RuntimeException('Region case '.($count+1).' failed: '.$key.' '.var_export($r,true));
    }
    $again = OGO_MC_Engine::analyze($r['after'], 'nashregion_urls');
    if ($again['after'] !== $r['after'] || $again['removed'] !== 0) throw new RuntimeException('Region repeat-run failed');
    ++$count;
}
$map = array('berezne'=>'berezne','dubno'=>'dubno','dubrovytsia'=>'dubrovyczya','hoshcha'=>'goshha','korets'=>'korecz','kostopil'=>'kostopil','mlyniv'=>'mlyniv','ostroh'=>'ostrog','radyvyliv'=>'radyvyliv','rivne'=>'rivne','sarny'=>'sarny','volodymyrets'=>'volodymyrecz','zarichne'=>'zarichne','zdolbuniv'=>'zdolbuniv');
foreach ($map as $source=>$dest) {
    $old = 'https://ogo.ua/nashregion/rubrics/view/'.$source;
    $new = 'https://ogo.ua/tag/'.$dest.'/';
    $html = '<p>Читайте <a href="'.$old.'" title="a > b"><strong>Новини регіону</strong></a>.</p>';
    check_region($html, str_replace($old,$new,$html), 1,1,0);
    $html = '<a href=" '.$old.'/?x=1&amp;y=2#page-top ">Регіон</a>';
    check_region($html, '<a href=" '.$new.'?x=1&amp;y=2 ">Регіон</a>', 1,1,0);
}
foreach (array('http://ogo.ua','https://www.ogo.ua','//ogo.ua','//www.ogo.ua','HTTPS://OGO.UA','https://ogo.ua:443','http://ogo.ua:80','') as $origin) {
    foreach (array('"', "'", '') as $quote) {
        $html = '<A HREF='.$quote.$origin.'/nashregion/rubrics/view/hoshcha#page-top'.$quote.' target="_blank">Гоща</A>';
        check_region($html, '<A HREF='.$quote.'https://ogo.ua/tag/goshha/'.$quote.' target="_blank">Гоща</A>', 1,1,0);
    }
}
foreach (array('/nashregion','/nashregion/','/nashregion#page-top','/nashregion/?x=1&amp;y=2#page-top') as $path) {
    $html = '<a href="https://ogo.ua'.$path.'">Наш регіон</a>';
    $expected = '<a href="https://ogo.ua/rubrics/view/region/'.(strpos($path,'?')!==false ? '?x=1&amp;y=2' : '').'">Наш регіон</a>';
    check_region($html,$expected,1,1,0);
}
$old = 'https://ogo.ua/nashregion/rubrics/view/rivne';
$html = '<!-- wp:paragraph --><p><a href="'.$old.'#section">Рівне</a> <a href="/nashregion">Новини</a></p><!-- /wp:paragraph -->';
check_region($html,'<!-- wp:paragraph --><p><a href="https://ogo.ua/tag/rivne/#section">Рівне</a> <a href="https://ogo.ua/rubrics/view/region/">Новини</a></p><!-- /wp:paragraph -->',2,2,0);
foreach (array('https://evil.example/nashregion','https://ogo.ua.evil.example/nashregion','https://user@ogo.ua/nashregion','ftp://ogo.ua/nashregion','https://ogo.ua:444/nashregion','https://ogo.ua/nashregions','https://ogo.ua/tag/rivne/','https://ogo.ua/rubrics/view/pets#page-top') as $url) {
    $html = '<a href="'.$url.'">Новини</a>';
    check_region($html,$html,0,0,0);
}
foreach (array('/nashregion/rubrics/view/unknown','/nashregion/rubrics/view/rivne/more','/nashregion/mystery','/nashregion/2010-02-04/33708','/nashregion/2010-02-30/33708.html','/nashregion/2010-02-04/999999999999999999999999999999.html') as $url) {
    $html = '<a href="'.$url.'">Новини</a>';
    check_region($html,$html,1,0,1);
}
foreach (array('script','style','textarea','pre','code') as $element) {
    $html = '<'.$element.'><a href="'.$old.'">Рівне</a></'.$element.'>';
    check_region($html,$html,0,0,0);
    $html = '<'.$element.'><a href="'.$old.'">Рівне</a>';
    check_region($html,$html,0,0,0);
}
$html = '<!-- <a href="'.$old.'">Рівне</a> -->';
check_region($html,$html,0,0,0);
foreach (array('<a href="'.$old.'">Рівне', '<a href="'.$old.'"><a href="https://example.com">Рівне</a></a>', '<a href="'.$old.'" />') as $html) check_region($html,$html,1,0,1);
$html = '<a href="'.$old.'">Рівне</a> <a href="/nashregion/rubrics/view/unknown">Інше</a>';
check_region($html,str_replace($old,'https://ogo.ua/tag/rivne/',$html),2,1,1);
// Local ID resolution: no HTTP redirects and no reliance on the old GUID.
$lookup[33708] = (object)array('post_type'=>'post','post_status'=>'publish','post_password'=>'','permalink'=>'https://ogo.ua/news/test-article/');
$article = 'https://ogo.ua/nashregion/2010-02-04/33708.html';
foreach (array('','#page-top','#section') as $suffix) {
    check_region('<a href="'.$article.$suffix.'">Стаття</a>', '<a href="https://ogo.ua/news/test-article/'.($suffix==='#page-top'?'':$suffix).'">Стаття</a>',1,1,0);
}
foreach (array('post_status'=>'draft','post_password'=>'secret','post_type'=>'page','viewable'=>false,'permalink'=>'https://evil.example/news/','permalink_old'=>'https://ogo.ua/nashregion/2010-02-04/33708.html') as $key=>$value) {
    $saved = clone $lookup[33708];
    $field = $key === 'permalink_old' ? 'permalink' : $key;
    $lookup[33708]->$field = $value;
    $html = '<a href="'.$article.'">Стаття</a>';
    check_region($html,$html,1,0,1);
    $lookup[33708] = $saved;
}
$html = '<a href="'.$article.'?x=1">Стаття</a>';
check_region($html,$html,1,0,1);
// Existing legacy URL migration still resolves the same ID.
OGO_MC_Legacy::clear_cache();
$r = OGO_MC_Engine::analyze('<a href="https://ogo.ua/articles/view/2010-02-04/33708.html">Стаття</a>','legacy_urls');
if ($r['after'] !== '<a href="https://ogo.ua/news/test-article/">Стаття</a>' || $r['removed'] !== 1) throw new RuntimeException('Legacy migration regression');
++$count;
// A changed permalink must be visible after clearing the per-request cache,
// as the apply path does before comparing against the stored preview.
$lookup[33708]->permalink = 'https://ogo.ua/news/changed/';
check_region('<a href="'.$article.'">Стаття</a>','<a href="https://ogo.ua/news/changed/">Стаття</a>',1,1,0);
unset($lookup[33708]);
$html = '<a href="'.$article.'">Стаття</a>';
check_region($html,$html,1,0,1);
echo 'Passed '.$count." Nash Region functional cases.\n";
// Optional private export verification; CSV content is never stored in Git.
if (isset($argv[1])) {
    $cases = json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    foreach ($cases as $case) check_region($case['before'],$case['expected'],1,1,0);
    echo 'Passed '.count($cases)." original exported articles, preserving all content outside the expected href replacement.\n";
}
