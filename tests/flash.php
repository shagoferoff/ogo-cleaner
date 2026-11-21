<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';

$count=0;
function flash_check($before,$after,$removed,$review=0) {
    global $count;
    $result=OGO_MC_Engine::analyze($before,'flash');
    if ($result['after']!==$after || $result['removed']!==$removed || $result['review']!==$review || $result['total']!==$removed+$review) {
        throw new RuntimeException('Flash fixture '.($count+1).': '.var_export($result,true));
    }
    if (OGO_MC_Engine::analyze($after,'flash')['removed']!==0) throw new RuntimeException('Second scan should find no further automatic edits');
    ++$count;
}
$ictv='<object data="http://kriminal.ictv.ua/public/swfobject/zl_player.swf" type="application/x-shockwave-flash">'
    .'<param name="movie" value="http://kriminal.ictv.ua/public/swfobject/zl_player.swf"/><param name="bgcolor" value="#000000"/></object>';
$youtube='<embed allowfullscreen="true" src="//www.youtube.com/v/iKPo4-Qeudw?version=3" type="application/x-shockwave-flash"></embed>';
$iframe='<iframe src="https://example.org/player.SWF?x=1#start"> </iframe>';
$modern='<iframe src="https://www.youtube.com/embed/iKPo4-Qeudw"></iframe><video controls src="/story.mp4"></video>';
flash_check('<p>До</p>'.$ictv.'<p>Підпис до відео</p>'.$youtube.$iframe.$modern,'<p>До</p><p>Підпис до відео</p>'.$modern,3);
flash_check('<OBJECT classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000"><param name=movie value="/old/player"/></OBJECT>','',1);
flash_check('<object><param name="movie" value="player.swf"><embed src=/player.swf></object>','',1);
flash_check('<object data="outer.swf"><object><embed src="inner.swf"></object></object>','',1);
flash_check('<embed src="https://example.org/player%2ESWF?media=intro.mp4" />','',1);
flash_check('<object codebase="https://example.org/swflash.cab#version=1"><param name=movie value="/v/video"></object>','',1);
flash_check('<!-- wp:html -->'.$ictv.'<!-- /wp:html -->','<!-- wp:html --><!-- /wp:html -->',1);
foreach (array(
    '<object data="/modern.pdf"><param name="movie" value="player.swf"></object>',
    '<object data="/player.swf"><a href="/story.mp4">Відкрити відео</a></object>',
    '<object data="/player.swf"><img src="/fallback.jpg" alt="Фото"></object>',
    '<object data="/player.swf"><p>Важливий підпис</p></object>',
    '<iframe src="/player.swf">Резервний текст</iframe>',
    '<object data="/player.swf"><param name="movie" value="/player.swf">',
    '<object data="/player.swf"><object data="/other.pdf"></object></object>',
    '<iframe src="/player.swf">',
) as $manual) flash_check($manual,$manual,0,1);
$protected='<!-- '.$ictv.' -->'.'<pre>'.$youtube.'</pre>'.'<script>'.$iframe.'</script>'
    .'<div data-example=\''.$ictv.'\'>Приклад</div>'.$modern
    .'<iframe src="/video">'.$youtube.'</iframe>'
    .'<iframe src="/player.swf" srcdoc="Важливий текст"></iframe>'
    .'<a href="/download/player.swf">Файл</a>'
    .'<object data="/paper.pdf"><param name="src" value="/paper.pdf"></object>'
    .'<embed src="/image.svg?name=player.swf">';
flash_check($protected,$protected,0);
echo 'Passed '.$count." Flash scope, fallback, protected markup and repeat-run checks.\n";
