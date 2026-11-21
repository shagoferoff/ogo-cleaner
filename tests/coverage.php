<?php
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
require_once __DIR__.'/../ogo-media-cleaner/includes/audit-engine.php';
require_once __DIR__.'/../ogo-media-cleaner/includes/coverage.php';

function coverage_assert($ok,$message) {
    if (!$ok) throw new RuntimeException($message);
}
$stream=fopen('php://memory','w+b');
fwrite($stream,"\xEF\xBB\xBFURL,Останнє сканування\n");
foreach (array(
    'https://ogo.ua/story/old?id=1&x=2',
    "https://ogo.ua/story/Нова \nадреса",
    'https://ogo.ua/story/%D0%9D%D0%BE%D0%B2%D0%B0%20%D0%B0%D0%B4%D1%80%D0%B5%D1%81%D0%B0',
    'https://ogo.ua/story/old/',
    'https://other.example/story/old?id=1&x=2',
) as $url) fputcsv($stream,array($url,'2026-11-17'),',','"','');
rewind($stream);
$targets=OGO_MC_Coverage_Engine::read_csv($stream);
fclose($stream);
coverage_assert(count($targets)===5 && $targets[0]['date']==='2026-11-17','Import preserves all rows and dates');
coverage_assert($targets[1]['key']===$targets[2]['key'] && $targets[4]['key']==='','Multiline Unicode normalizes without matching another host');
$href='<a href="./old?id=1&amp;x=2#fragment">Читайте</a>';
$html=$href.$href.'<a href="http://www.ogo.ua/story/Нова адреса">Адреса</a>';
$html.='<a href="/story/old?id=2&amp;x=2">Інший параметр</a><a href="/story/old">Без слеша</a>';
$html.='<!-- '.$href.' --><pre>'.$href.'</pre><div data-markup=\''.$href.'\'>Зразок</div>';
$keys=array_fill_keys(array_filter(array_column($targets,'key')),true);
$rows=OGO_MC_Coverage_Engine::extract($html,'http://ogo-test.local/story/article',$keys);
coverage_assert(count($rows)===2 && $rows[0]['link_count']===2 && $rows[1]['link_count']===1,'Only matching links are counted, preserving query, slash and quoted HTML boundaries');
coverage_assert($rows[0]['raw_url']==='./old?id=1&amp;x=2#fragment','Original href is available for editing');
$local='<a href="http://ogo-test.local/story/old?id=1&amp;x=2">Local</a><a href="http://other.local/story/old?id=1&amp;x=2">Other</a>';
coverage_assert(count(OGO_MC_Coverage_Engine::extract($local,'http://ogo-test.local/story/article',$keys))===1,'The local WordPress origin can represent ogo.ua');
if (isset($argv[1])) {
    $actual=OGO_MC_Coverage::import_file($argv[1],basename($argv[1]));
    coverage_assert(count($actual)===1000 && $actual[0]['raw']==='https://ogo.ua/articles/2014-06-27/52557.html','Read the supplied Search Console export');
    $matchable=count(array_filter(array_column($actual,'key')));
    echo "Imported ".count($actual)." real CSV rows, ".$matchable." matchable.\n";
}
echo "Passed CSV, multiline URL, normalization and source-count checks.\n";
