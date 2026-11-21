<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
require_once __DIR__.'/../ogo-media-cleaner/includes/external-engine.php';
require_once __DIR__.'/../ogo-media-cleaner/includes/site-links-engine.php';

function site_links_assert($ok,$message) {
    if (!$ok) throw new RuntimeException($message);
}
$normal='<a href="https://www.example.org/story" rel="noopener" target="_blank">Джерело</a>';
$nofollow='<a href="https://www.example.org/story" rel="NoFoLlOw noopener" target="_self">Джерело</a>';
$paid='<a href="https://example.org/paid" rel="sponsored">Спонсор</a>';
$ugc='<a href="//forum.example.org/1" rel="ugc">Форум</a>';
$internal='<a href="https://archives.ogo.ua/1">ОГО</a>';
list($rows,$review)=OGO_MC_Site_Links_Engine::inspect($normal.$normal.$nofollow.$paid.$ugc.$internal);
site_links_assert(count($rows)===4 && $review===0,'Groups duplicate anchors and omits internal links');
site_links_assert($rows[0]['domain']==='example.org' && $rows[0]['link_count']===2 && $rows[0]['new_tab']===1 && $rows[0]['closed']===0 && $rows[0]['paid']===0,'New tab and noopener are separate from open status');
site_links_assert($rows[0]['url_key']===$rows[1]['url_key'] && $rows[0]['variant_key']!==$rows[1]['variant_key'] && $rows[1]['closed']===1 && $rows[1]['paid']===0,'Status variants share the same URL');
site_links_assert($rows[2]['paid']===1 && $rows[2]['closed']===1 && $rows[2]['ad_marker']===0,'Sponsored is a paid signal');
site_links_assert($rows[3]['closed']===1 && $rows[3]['paid']===0 && $rows[3]['domain']==='forum.example.org','UGC is a rel restriction without an advertising marker');
list($rows)=OGO_MC_Site_Links_Engine::inspect($normal.'<p>«<strong>На правах</strong>&nbsp;реклами».</p><!-- /wp:paragraph -->');
site_links_assert($rows[0]['paid']===1 && $rows[0]['ad_marker']===1 && $rows[0]['closed']===0,'Visible footer sets article-level advertising without changing rel status');
list($rows)=OGO_MC_Site_Links_Engine::inspect($nofollow.'<p><strong>На правах реклами.</strong></p>');
site_links_assert($rows[0]['ad_marker']===1,'A footer immediately after inline text still has a block boundary');
foreach (array('<!-- На правах реклами -->','<pre>На правах реклами</pre>','<p>Редакція обговорила позначку «На правах реклами».</p>') as $suffix) {
    list($rows)=OGO_MC_Site_Links_Engine::inspect($normal.$suffix);
    site_links_assert($rows[0]['paid']===0,'Ignore hidden markers and mentions in editorial prose');
}
list($rows)=OGO_MC_Site_Links_Engine::inspect('<p>На правах реклами</p>'.$normal.'<p>Подальший текст.</p>');
site_links_assert($rows[0]['paid']===1 && $rows[0]['ad_marker']===1,'A visible marker can precede other paragraphs');
list($rows,$review)=OGO_MC_Site_Links_Engine::inspect('<a href="https://example.org/no-close">bad');
site_links_assert(!$rows && $review===1,'Incomplete HTML does not contaminate the counts');
site_links_assert(!isset(OGO_MC_External_Engine::extract($normal)[0]['rel']),'Keep the old Miralinks row shape');
echo "Passed site audit URL counts, advertising, rel, new-tab and shared-engine isolation checks.\n";
