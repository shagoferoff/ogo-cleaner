<?php
if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
require_once __DIR__.'/../ogo-media-cleaner/includes/audit-engine.php';
$count = 0;
function audit_check($ok,$message) { global $count; if (!$ok) throw new RuntimeException($message); ++$count; }
$active = array('exclusive','life','nadzvychaini','region','opytuvannya','consumer','top','ukraine','foto-dnya');
foreach ($active as $slug) {
    foreach (array('https://ogo.ua/rubrics/view/'.$slug, 'http://www.ogo.ua/rubrics/view/'.$slug.'/page:16/?page=16#top', '/rubrics/view/'.$slug.'/') as $url) {
        $r = OGO_MC_Audit_Engine::classify($url);
        audit_check($r['category']==='active' && $r['group_url']==='https://ogo.ua/rubrics/view/'.$slug,'Active whitelist '.$url);
    }
    audit_check(OGO_MC_Audit_Engine::classify('https://ogo.ua/woman/rubrics/view/'.$slug)['category']==='unknown_rubric','Subproject must not inherit active status');
}
foreach (array('https://ogo.ua/rubrics/view/unknown','https://ogo.ua/oldproject/rubrics/view/unknown/page:19','https://blog.ogo.ua/rubrics/view/life') as $url) audit_check(OGO_MC_Audit_Engine::classify($url)['category']==='unknown_rubric','Unknown rubric '.$url);
$known = array('/rubrics/view/dubno'=>'https://ogo.ua/tag/dubno/', '/nashregion/rubrics/view/dubrovytsia/page:2'=>'https://ogo.ua/tag/dubrovyczya/', '/woman/rubrics/view/wedding'=>'https://ogo.ua/tag/vesillya/','/rubrics/view/beauty-care'=>'https://ogo.ua/tag/krasa/','/rubrics/view/dim/page:16'=>'https://ogo.ua/tag/dim/','/rubrics/view/goroskop'=>'https://ogo.ua/tag/goroskop/','/rubrics/view/pets'=>'https://ogo.ua/tag/domashni-tvaryny/','/rubrics/view/garden'=>'https://ogo.ua/tag/dacha-sad-gorod/','/woman/page:3'=>'https://ogo.ua/tag/zhinochyj-klub/','/nashregion/'=>'https://ogo.ua/rubrics/view/region/','/dossier/vlada/homko'=>'https://my.rv.ua/dossier-details/homko-volodimir-yevgenovich/');
foreach ($known as $url=>$target) {
    $r = OGO_MC_Audit_Engine::classify($url);
    audit_check($r['category']==='known' && $r['target_url']===$target,'Known mapping '.$url);
}
audit_check(strpos(OGO_MC_Audit_Engine::classify('/rubrics/view/goroskop/page:16')['suggestion'],'розширити')!==false,'Do not promise unsupported cleanup pagination');
foreach (array('/articles/view/2010-02-04/33708.html','/woman/2010-02-04/33708.html','/nashregion/2010-02-04/33708.html','/unknownproject/2010-02-04/33708.html') as $url) audit_check(OGO_MC_Audit_Engine::classify($url)['category']==='old_article','Old article '.$url);
foreach (array('https://evil.example/rubrics/view/life','https://ogo.ua.evil.example/rubrics/view/life','https://user@ogo.ua/rubrics/view/life','javascript:alert(1)','mailto:editor@ogo.ua','#top','https://ogo.ua./rubrics/view/life') as $url) audit_check(OGO_MC_Audit_Engine::classify($url)===null,'Exclude external/non-HTTP/ambiguous '.$url);
audit_check(OGO_MC_Audit_Engine::classify('/rubrics/view/life/extra')['category']!=='active','Unrecognized suffix is not active');
audit_check(OGO_MC_Audit_Engine::classify('https://ogo.ua:8443/rubrics/view/life')['category']!=='active','Nondefault origin is not whitelisted');
audit_check(OGO_MC_Audit_Engine::classify('http://ogo.ua:80/rubrics/view/life')['category']==='active','Default port');
audit_check(OGO_MC_Audit_Engine::classify('https://www.media.ogo.ua/video/view/3')['category']==='other','Old subdomain retained');
audit_check(OGO_MC_Audit_Engine::classify('https://ogo.rv.ua/old')['category']==='other','Old domain retained');
$first = OGO_MC_Audit_Engine::classify('http://www.ogo.ua/rubrics/view/dubno/');
foreach (array('https://ogo.ua/rubrics/view/dubno/page:16#page-top','/rubrics/view/dubno/?x=1&amp;y=2') as $url) audit_check(OGO_MC_Audit_Engine::classify($url)['group_key']===$first['group_key'],'Equivalent variant group');
audit_check(OGO_MC_Audit_Engine::classify('/?p=1')['group_key']!==OGO_MC_Audit_Engine::classify('/?p=2')['group_key'],'Different query-based posts stay distinct');
$r = OGO_MC_Audit_Engine::classify('../rubrics/view/dubno','https://ogo.ua/news/article/');
audit_check($r['group_url']==='https://ogo.ua/news/rubrics/view/dubno' && $r['category']==='unknown_rubric','Relative URL uses actual source directory');
$r = OGO_MC_Audit_Engine::classify('../../rubrics/view/dubno','https://ogo.ua/news/article/');
audit_check($r['target_url']==='https://ogo.ua/tag/dubno/','Relative path dot resolution');
$content = '<a href="http://www.ogo.ua/rubrics/view/dubno/">one</a><a href="http://www.ogo.ua/rubrics/view/dubno/">two</a><a href="/rubrics/view/dubno/page:16#page-top">three</a><a href="https://external.example/">external</a>';
$before = $content; $rows = OGO_MC_Audit_Engine::extract($content);
audit_check($before===$content,'Inventory never changes input');
audit_check(count($rows)===2 && $rows[0]['link_count']===2 && $rows[1]['link_count']===1,'Repeated href counted per variant');
audit_check($rows[0]['group_key']===$rows[1]['group_key'] && $rows[0]['variant_key']!==$rows[1]['variant_key'],'Normalized group and raw variants stay separate');
foreach (array('script','style','textarea','pre','code') as $tag) {
    audit_check(OGO_MC_Audit_Engine::extract('<'.$tag.'><a href="/woman">x</a></'.$tag.'>')===array(),'Protected '.$tag);
    audit_check(OGO_MC_Audit_Engine::extract('<'.$tag.'><a href="/woman">x</a>')===array(),'Unclosed protected '.$tag);
}
audit_check(OGO_MC_Audit_Engine::extract('<!-- <a href="/woman">x</a> -->')===array(),'Comments');
audit_check(OGO_MC_Audit_Engine::extract('<a href="/woman" href="/nashregion">x</a>')===array(),'Ambiguous duplicate href');
audit_check(OGO_MC_Audit_Engine::extract('https://ogo.ua/woman <img src="https://ogo.ua/old.jpg">')===array(),'Only anchor href is in scope');
$r = OGO_MC_Audit_Engine::extract('<a href=" /woman?x=1&amp;y=2 ">x</a>')[0];
audit_check($r['raw_url']===' /woman?x=1&amp;y=2 ' && $r['resolved_url']==='https://ogo.ua/woman?x=1&y=2','Raw escaping/spacing retained');
$stream = fopen('php://temp','r+');
OGO_MC_Audit_Engine::csv_row($stream,array('=1+1',' @SUM(1)','-bad','normal','https://ogo.ua/','<tag>','comma,quote"line'."\n"));
rewind($stream); $row = fgetcsv($stream,0,',','"',''); fclose($stream);
audit_check($row[0]==="'=1+1" && $row[1]==="' @SUM(1)" && $row[2]==="'-bad" && $row[3]==='normal' && $row[6]==='comma,quote"line'."\n",'Safe CSV, correct quoting and multiline round-trip');
audit_check(OGO_MC_Audit_Engine::classify('../../../../rubrics/view/dubno','https://ogo.ua/news/article/')['target_url']==='https://ogo.ua/tag/dubno/','Dot paths cannot escape above origin root');
try { OGO_MC_Engine::analyze('<a href="/woman">x</a>','audit'); audit_check(false,'Cleanup engine must reject audit'); } catch (InvalidArgumentException $e) { audit_check(true,'Cleanup engine rejects audit'); }
audit_check(OGO_MC_Audit_Engine::extract("<div data-note='<a href=\"/woman\">fake</a>'>text</div>")===array(),'Quoted markup is not a hyperlink');
audit_check(count(OGO_MC_Audit_Engine::extract("<div data-note='<script>'>text</div><a href=\"/woman\">real</a>"))===1,'Quoted raw-text marker does not hide real links');
echo 'Passed '.$count." audit engine cases.\n";
