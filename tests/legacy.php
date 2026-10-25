<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
$lookup=array();
function get_post($id) { global $lookup; return $lookup[$id] ?? null; }
function get_permalink($post) { return $post->permalink; }
function home_url($path='/') { return 'https://ogo.ua'.$path; }
function is_post_publicly_viewable($post) { return $post->viewable ?? true; }
$count=0;
function legacy_check($input,$expected,$total,$changed,$review,$reason='') {
    global $count;
    if (class_exists('OGO_MC_Legacy')) OGO_MC_Legacy::clear_cache();
    $r=OGO_MC_Engine::analyze($input,'legacy_urls');
    foreach (array('after'=>$expected,'total'=>$total,'removed'=>$changed,'review'=>$review) as $key=>$value) if ($r[$key]!==$value) throw new RuntimeException('Legacy case '.($count+1).' failed: '.$key.' '.var_export($r,true));
    if ($reason!=='' && strpos($r['summary'],$reason)===false) throw new RuntimeException('Missing review reason: '.$reason.' '.var_export($r,true));
    $again=OGO_MC_Engine::analyze($r['after'],'legacy_urls');
    if ($again['after']!==$r['after'] || $again['removed']!==0) throw new RuntimeException('Legacy repeat-run failure');
    ++$count;
}
$examples=array(
 'https://ogo.ua/articles/view/2016-05-16/75860.htm'=>75860,
 'https://ogo.ua/articles/2011-11-08/30777.html'=>30777,
 'https://ogo.ua/articles/view/2024-03-17/138311.html'=>138311,
 'https://ogo.ua/articles/view/2017-02-14/84408'=>84408,
 'https://ogo.ua/articles/view/2014-05-21/51131.html%3Cbr%20/%3E'=>51131,
 'https://ogo.ua/articles/view/2021-06-28/116986.html.'=>116986,
 'https://ogo.ua/articles/view/2024-03-13/139470'=>139470,
 'https://ogo.ua/articles/view/2016-11-08/ogo.ua/articles/view/2016-09-03/79112.html'=>79112,
 'https://gazeta.ogo.ua/articles/view/2013-04-22/39785.html'=>39785,
 'https://mail.ogo.ua/articles/view/2015-01-30/58918.html'=>58918,
 'https://ogo.ua/articles/view/2015-09-16/67228.htmlhttps://ogo.ua/articles/view/2015-09-16/67228.html'=>67228,
 'https://ogo.ua/articles/view/2015-10-25/ogo.ua/articles/view/2015-09-18/67210.html'=>67210,
 'https://ogo.ua/articles/view/2016-07-11/77677.htmlhttps://ogo.ua/articles/view/2016-07-11/77677.html'=>77677,
 'https://ogo.ua/articles/view/2012-05-22/33534.html%3Cbr%20/%3E'=>33534,
 'https://ogo.ua/articles/view/2015-02-29/73275.html'=>73275,
 'https://ogo.ua/articles/view/2016-02--08/72517.html'=>72517,
 'https://ogo.ua/articles/view/2016-02-07/72032html'=>72032,
 'https://ogo.ua/articles/view/2016-03-07/73287.html'."\u{2028}"=>73287,
 'https://ogo.ua/articles/view/2023-04-01/131461.html'=>131461
);
foreach (array_unique(array_values($examples)) as $id) $lookup[$id]=(object)array('post_type'=>'post','post_status'=>'publish','post_password'=>'','permalink'=>'https://ogo.ua/test-article-'.$id.'/');
$lookup[131461]->permalink='https://ogo.ua/paska-kovbaska-y-zoloti-yaytsya-skilky-koshtuvatyme-velykodniy-koshyk-2023/';
foreach ($examples as $url=>$id) {
    foreach (array('"',"'",'') as $quote) {
        $html='<p><a href='.$quote.$url.$quote.' title="a > b"><strong>Стаття</strong></a></p>';
        legacy_check($html,str_replace($url,$lookup[$id]->permalink,$html),1,1,0);
    }
}
foreach (array('http://ogo.ua','https://www.ogo.ua','//ogo.ua','//www.mail.ogo.ua','https://www.gazeta.ogo.ua','https://ogo.ua:443','http://ogo.ua:80','') as $origin) {
    $url=$origin.'/articles/2011-11-08/30777.html';
    legacy_check('<A HREF="'.$url.'">Стаття</A>','<A HREF="'.$lookup[30777]->permalink.'">Стаття</A>',1,1,0);
}
$url='/articles/view/2015-09-16/67228.htmlhttps://ogo.ua/articles/view/2015-09-16/67228.html';
legacy_check('<a href="'.$url.'">Повтор</a>','<a href="'.$lookup[67228]->permalink.'">Повтор</a>',1,1,0);
foreach (array('','#page-top','#section') as $fragment) {
    $html='<a href="  https://ogo.ua/articles/view/2016-05-16/75860.htm'.$fragment.'  ">label</a>';
    legacy_check($html,'<a href="  '.$lookup[75860]->permalink.($fragment==='#page-top'?'':$fragment).'  ">label</a>',1,1,0);
}
foreach (array(
 'https://ogo.ua/articles/view/2015-09-16/67228.htmlhttps://ogo.ua/articles/view/2015-09-16/77677.html'=>'різні ID',
 'https://ogo.ua/articles/view/2015-09-16/67228.htmlhttps://evil.example/articles/view/2015-09-16/67228.html'=>'структура',
 'https://ogo.ua/articles/view/2015-09-16/67228.html/evil.example/999.html'=>'структура',
 'https://ogo.ua/articles/view/2015-09-16/67228.html.exe'=>'суфікс',
 'https://ogo.ua/articles/view/2015-09-16/67228.html?next=other'=>'параметри',
 'https://ogo.ua/articles/view/2015-09-16/999999999999999999999999999999.html'=>'діапазону'
) as $url=>$reason) {
    $html='<a href="'.$url.'">Перевірити</a>';legacy_check($html,$html,1,0,1,$reason);
}
foreach (array('https://evil.example/articles/view/2015-09-16/67228.html','https://ogo.ua.evil.example/articles/view/2015-09-16/67228.html','https://user@ogo.ua/articles/view/2015-09-16/67228.html','https://ogo.ua:8443/articles/view/2015-09-16/67228.html','https://ogo.ua/articles/current-story','https://ogo.ua/tag/test/') as $url) {
    $html='<a href="'.$url.'">Інше</a>';legacy_check($html,$html,0,0,0);
}
foreach (array('lt.ogo.ua','catalog.ogo.ua','blog.ogo.ua','ogo.rv.ua') as $host) {
    $html='<a href="https://'.$host.'/articles/view/2015-09-16/67228.html">Підпроєкт</a>'; legacy_check($html,$html,1,0,1,'підтвердити відповідність ID');
}
$single='https://ogo.ua/articles/view/2016-05-16/75860.htm';
foreach (array('post_status'=>'draft','post_type'=>'page','post_password'=>'test','viewable'=>false,'permalink'=>'https://evil.example/article/','old_path'=>'https://ogo.ua/articles/2016-05-16/75860.html') as $key=>$value) {
    $saved=clone $lookup[75860];$field=$key==='old_path'?'permalink':$key;$lookup[75860]->$field=$value;
    $html='<a href="'.$single.'">Стаття</a>';legacy_check($html,$html,1,0,1);
    $lookup[75860]=$saved;
}
$saved=$lookup[75860];unset($lookup[75860]);
$html='<a href="'.$single.'">Стаття</a>';legacy_check($html,$html,1,0,1,'ID 75860: статтю не знайдено');$lookup[75860]=$saved;
foreach (array('script','style','textarea','pre','code') as $tag) {
    $html='<'.$tag.'><a href="'.$single.'">код</a></'.$tag.'>';legacy_check($html,$html,0,0,0);
}
foreach (array('<!-- <a href="'.$single.'">коментар</a> -->',"<div data-fake='<a href=\"".$single."\">x</a>'>data</div>") as $html) legacy_check($html,$html,0,0,0);
foreach (array('<a href="'.$single.'">unclosed','<a href="'.$single.'"><a href="https://example.com">nested</a></a>') as $html) legacy_check($html,$html,1,0,1);
$html='<a href="'.$single.'">good</a><a href="/articles/view/2016-05-16/999999999.html">missing</a>';
legacy_check($html,str_replace($single,$lookup[75860]->permalink,$html),2,1,1);
echo 'Passed '.$count." legacy URL/ID cases, including every supplied example and ambiguous-ID protection.\n";
