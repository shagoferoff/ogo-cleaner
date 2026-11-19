<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';

function source_check($project,$before,$after,$changed,$review=0) {
    $result=OGO_MC_Engine::analyze($before,$project);
    $expected=array('after'=>$after,'removed'=>$changed,'review'=>$review,'total'=>$changed+$review);
    foreach ($expected as $name=>$value) {
        if ($result[$name]!==$value) throw new RuntimeException($project.' '.$name.' '.var_export($result,true));
    }
    $repeat=OGO_MC_Engine::analyze($result['after'],$project);
    if ($repeat['after']!==$after || $repeat['removed']!==0) throw new RuntimeException($project.' is not idempotent');
}

$credit='<a href="https://segodnya.ua/news/1"><strong>Сегодня</strong></a>';
$before='<!-- wp:paragraph --><p>повідомляє "'.$credit.'"</p><!-- /wp:paragraph -->';
source_check('segodnya',$before,'<!-- wp:paragraph --><p>повідомляє "<strong>Сегодня</strong>"</p><!-- /wp:paragraph -->',1);
source_check('segodnya','<p><A href=//news.archive.SEGODNYA.ua/>Фото <img src="x.jpg"></A ></p>','<p>Фото <img src="x.jpg"></p>',1);
source_check('segodnya','<p>'.$credit.'</p>','<p><strong>Сегодня</strong></p>',1);

$tsn='<a href="https://ru.tsn.ua/news?one=1&amp;two=2" class="source"><em>ТСН</em></a>';
$updated='<a href="https://ru.tsn.ua/news?one=1&amp;two=2" class="source" target="_blank" rel="nofollow noopener"><em>ТСН</em></a>';
source_check('tsn',$tsn,$updated,1);
source_check('tsn',$updated,$updated,0);
source_check('tsn',
    "<A REL='NoFoLlOw sponsored' target=_self href=//www.tsn.ua/ rel=\"noreferrer SPONSORED NOOPENER\" TARGET='_top' data-keep='rel=external'>Текст</A>",
    "<A rel=\"nofollow noopener sponsored noreferrer\" target=\"_blank\" href=//www.tsn.ua/ data-keep='rel=external'>Текст</A>",1);
source_check('tsn',
    '<a href="http://tsn.ua" rel="sponsored&#32;noreferrer nofollow noreferrer">ТСН</a>',
    '<a href="http://tsn.ua" rel="nofollow noopener sponsored noreferrer" target="_blank">ТСН</a>',1);
source_check('tsn','<a href="//tsn.ua" rel target>ТСН</a>','<a href="//tsn.ua" rel="nofollow noopener" target="_blank">ТСН</a>',1);
source_check('tsn','<a href=https://tsn.ua/>ТСН</a>','<a href=https://tsn.ua/ target="_blank" rel="nofollow noopener">ТСН</a>',1);

$korrespondent='<a href="http://korrespondent.net/"><strong>Корреспондент.net</strong></a>';
$updated_korrespondent='<a href="http://korrespondent.net/" target="_blank" rel="nofollow noopener"><strong>Корреспондент.net</strong></a>';
source_check('korrespondent',$korrespondent.$tsn,$updated_korrespondent.$tsn,1);
source_check('tsn',$korrespondent.$tsn,$korrespondent.$updated,1);
source_check('korrespondent',
    '<a href="//news.korrespondent.net/story?q=1&amp;from=ogo" target="_self" rel="sponsored nofollow noreferrer NOOPENER nofollow">Джерело</a>',
    '<a href="//news.korrespondent.net/story?q=1&amp;from=ogo" target="_blank" rel="nofollow noopener sponsored noreferrer">Джерело</a>',1);

$sources=array(
    'tsn'=>array($tsn,'tsn.ua'),
    'segodnya'=>array($credit,'segodnya.ua'),
    'korrespondent'=>array($korrespondent,'korrespondent.net'),
);
foreach ($sources as $project=>list($target,$domain)) {
    $protected='<!-- '.$target.' -->';
    foreach (array('code','pre','style','script','textarea') as $tag) $protected.='<'.$tag.' data-test=">">'.$target.'</'.$tag.'>';
    $protected.='<div data-demo=\''.$target.'\'>зразок</div>';
    source_check($project,$protected,$protected,0);
    $protected='<!-- comment without closing '.$target;
    source_check($project,$protected,$protected,0);
    $protected='<pre>'.$target;
    source_check($project,$protected,$protected,0);
    $nested=str_replace('</a>','<a href="/other">nested</a></a>',$target);
    source_check($project,$nested,$nested,0,1);
    $unclosed=str_replace('</a>','',$target);
    source_check($project,$unclosed,$unclosed,0,1);
    $selfClosing=preg_replace('~>.*$~',' />',$target);
    source_check($project,$selfClosing,$selfClosing,0,1);
    foreach (array('https://'.$domain.'.example/','https://not'.$domain.'/','https://example.org/'.$domain,'https://'.$domain.'@example.org/','https://example.org@'.$domain.'/','mailto:mail@'.$domain,'/news/') as $url) {
        $other='<a href="'.$url.'">Інше джерело</a>';
        source_check($project,$other,$other,0);
    }
}
echo "Passed source link scope, preserved HTML, attribute merging, protected markup and repeat-run checks.\n";
