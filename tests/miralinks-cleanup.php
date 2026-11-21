<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
require_once __DIR__.'/../ogo-media-cleaner/includes/source-links.php';
require_once __DIR__.'/../ogo-media-cleaner/includes/external-engine.php';

function cleanup_check($condition,$message) {
    if (!$condition) throw new RuntimeException($message);
}
$target='<a href="https://example.org/item?x=1&amp;y=2"><b>Зовнішнє</b></a>';
$targets=OGO_MC_External_Engine::extract($target);
$internal='<a href="https://ogo.ua/news/">ОГО</a>';
$other='<a href="https://example.org/other">Інша адреса</a>';
$same_url='<a href="https://example.org/item?x=1&amp;y=2">Новий текст</a>';
$embed='<!-- wp:embed {"url":"https://example.net/video"} --><figure><a href="https://example.net/video">Відео</a></figure><!-- /wp:embed -->';
$before='<p>До '.$target.' після</p>'.$other.$same_url.$internal.$embed.'<!-- '.$target.' -->';
$expected=str_replace('До '.$target,'До <b>Зовнішнє</b>',$before);
$result=OGO_MC_Source_Links::miralinks($before,$targets);
cleanup_check($result['after']===$expected && $result['removed']===1 && $result['review']===0,'Only the audited URL and anchor are unwrapped');
cleanup_check(OGO_MC_Source_Links::miralinks($expected,$targets)['removed']===0,'Repeat run has no pending changes');
$duplicates=OGO_MC_Source_Links::miralinks($target.$target,$targets);
cleanup_check($duplicates['after']===$target.$target && $duplicates['removed']===0 && $duplicates['review']===1,'New duplicated occurrences are reviewed');
$broken=str_replace('</a>','',$target);
cleanup_check(OGO_MC_Source_Links::miralinks($broken,$targets)['review']===1,'Unclosed audited links are reviewed');
$embedded='<!-- wp:embed {"url":"https://example.org/item"} --><figure>'.$target.'</figure><!-- /wp:embed -->';
cleanup_check(OGO_MC_Source_Links::miralinks($embedded,$targets)['after']===$embedded,'Embedded content stays byte-for-byte');
echo "Passed audited URL, anchor, duplicate, embed and repeat-run checks.\n";
