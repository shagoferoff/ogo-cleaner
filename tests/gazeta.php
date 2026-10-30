<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
$count=0;
function gazeta_check($html,$expected,$removed=null,$review=0) {
    global $count;$r=OGO_MC_Engine::analyze($html,'gazeta');
    if($r['after']!==$expected || $r['review']!==$review || ($removed!==null && $r['removed']!==$removed))throw new RuntimeException('Gazeta case '.($count+1).' '.var_export($r,true).' expected '.var_export($expected,true));
    $again=OGO_MC_Engine::analyze($r['after'],'gazeta');
    if($again['after']!==$r['after'] || $again['removed']!==0)throw new RuntimeException('Not idempotent '.($count+1));
    ++$count;
}
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/gazeta.json'),true);
foreach($fixtures as $f) {
    $html=$f['html'];$expected=$f['action']==='delete'?'':preg_replace('~</?a\b[^>]*>~i','',$html);
    if($f['action']==='unwrap')$expected=preg_replace_callback('~<a\b[^>]*>[\s\S]*?</a>~i',function($m){return OGO_MC_Engine::target($m[0],'gazeta')?preg_replace('~</?a\b[^>]*>~i','',$m[0]):$m[0];},$html);
    foreach(array(array('',''),array('<p>','</p>'),array('<div align="justify">','</div>'),array('<p><span style="font-size: small;"><font size="2">','</font></span></p>'),array('<!-- wp:paragraph --><p>','</p><!-- /wp:paragraph -->')) as $wrap) {
        $wanted=$expected===''?'':$wrap[0].$expected.$wrap[1];
        gazeta_check($wrap[0].$html.$wrap[1],$wanted,1);
        gazeta_check('<p>Передмова.</p>'.$wrap[0].$html.$wrap[1].'<p>Завершення.</p>','<p>Передмова.</p>'.$wanted.'<p>Завершення.</p>',1);
    }
}

$link='<a href="http://gazeta.rv.ua/article/1/">назву</a>';
foreach(array('http://gazeta.rv.ua/','https://www.gazeta.rv.ua/articles/view/2000-01-01/123.html','//gazeta.rv.ua/','http://GAZETA.RV.UA/') as $url)foreach(array('"',"'",'') as $quote) {
    $a='<a href='.$quote.$url.$quote.'><b>назву</b></a>';
    gazeta_check('Текст '.$a.'.','Текст <b>назву</b>.',1);
}
foreach(array('За матеріалами ','Джерело: ') as $prefix)gazeta_check('<p>'.$prefix.$link.'</p>','<p>'.$prefix.'назву</p>',1);
foreach(array('https://gazeta.rv.ua.evil.example/','https://gazeta.ogo.ua/','https://evil.example/gazeta.rv.ua/','https://user@gazeta.rv.ua/','/article/1/','mailto:info@gazeta.rv.ua') as $url){$html='Текст <a href="'.$url.'">назву</a>.';gazeta_check($html,$html,0);}
$promo=$fixtures[12]['html'];
foreach(array('pre','code','script','style','textarea') as $tag){$html='<'.$tag.'>'.$promo.'</'.$tag.'>';gazeta_check($html,$html,0);}
$html='<!-- '.$promo.' -->';gazeta_check($html,$html,0);
$html="<span data-fake='".$promo."'>Текст</span>";gazeta_check($html,$html,0);
// Separators and adjacent article text must survive.
gazeta_check("Основний текст\n\n".$promo."\n\nКінець","Основний текст\n\n\n\nКінець",1);
gazeta_check('Основний текст<br/>'.$promo.'<br/>Кінець','Основний текст<br/>Кінець',1);
gazeta_check('<div>Основний текст<br/>'.$promo.'<br/>Кінець</div>','<div>Основний текст<br/>Кінець</div>',1);
gazeta_check('<div><p>Основний текст</p><p>'.$promo.'</p><p>Кінець</p></div>','<div><p>Основний текст</p><p>Кінець</p></div>',1);
gazeta_check('<div><p><font size="2">'.$promo.'</font></p></div>','',1);
gazeta_check('<div id="section"><p>'.$promo.'</p></div>','<div id="section"></div>',1);
// A recommendation within an actual prose paragraph must not erase the prose.
gazeta_check('<p>Основний текст. '.$promo.' Це важливо.</p>','<p>Основний текст. '.preg_replace('~</?a\b[^>]*>~i','',$promo).' Це важливо.</p>',1);
$two='<p><a href="http://gazeta.rv.ua/a">Перше</a> <a href="https://www.gazeta.rv.ua/b">Друге</a></p>';gazeta_check($two,'',2);
$html='<p>Текст '.$link.' та <a href="https://my.rv.ua/">інше джерело</a>.</p>';gazeta_check($html,'<p>Текст назву та <a href="https://my.rv.ua/">інше джерело</a>.</p>',1);
foreach(array('<a href="http://gazeta.rv.ua/">Текст','<p>'.$link,'<a href="http://gazeta.rv.ua/"><img src="image.jpg"></a>','<a href="http://gazeta.rv.ua/" id="reference">Текст</a>','<a href="http://gazeta.rv.ua/"><a href="https://example.com/">Текст</a></a>') as $html)gazeta_check($html,$html,0,1);
// Original contact rules still run before newspaper prose handling.
$contacts='<p>Пишіть мені листи за адресою:</p><p>Надсилайте електронні листи на gazeta.rv.ua@gmail.com</p>';
gazeta_check($contacts.'<p>Основний текст.</p>','<p>Основний текст.</p>',2);
gazeta_check('<p>Листи: gazeta.rv.ua@gmail.com</p><p>Основний текст.</p>','<p>Основний текст.</p>',1);
gazeta_check('<p>Пишіть мені листи за адресою:</p>','<p>Пишіть мені листи за адресою:</p>',0,1);
$uncertain='<p>Пишіть мені листи за адресою:</p><p>Або '.$link.' нестандартним способом.</p>';
gazeta_check($uncertain,$uncertain,0,2);
if(isset(OGO_MC_Engine::projects()['gazeta_sources']))throw new RuntimeException('Separate operation still exposed');
echo 'Passed '.$count." gazeta fixtures, prose, boundaries, contacts, protection and repeat-run cases.\n";
