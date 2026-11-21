<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';

function batch_check($before,$after,$updated,$review=0,$project='source_batch_1') {
    $r=OGO_MC_Engine::analyze($before,$project);
    if ($r['after']!==$after || $r['removed']!==$updated || $r['review']!==$review || $r['total']!==$updated+$review) {
        throw new RuntimeException('Unexpected batch preview: '.var_export($r,true));
    }
    if (OGO_MC_Engine::analyze($after,$project)['removed']!==0) throw new RuntimeException('Second scan changes already processed links');
}
$open=array(
    'pogoda.rovno.ua','pravda.com.ua','unn.com.ua','moemisto.ua','rivne1.tv','ukrinform.ua',
    'radiosvoboda.org','rivnepost.rv.ua','bit.ly','4vlada.com','gazeta.ua','news.liga.net',
    'zik.ua','epravda.com.ua','rv.gov.ua','slovoidilo.ua','charivne.info','24tv.com.ua','24tv.ua',
    'prm.ua','zaxid.net','president.gov.ua','espreso.tv','life.pravda.com.ua','rtb.rv.ua','zik.com.ua',
    'docs.google.com','5.ua','7d.rv.ua',
);
foreach ($open as $host) {
    $tag='<a href="https://'.$host.'/x">Джерело</a>';
    batch_check($tag,str_replace('">','" target="_blank" rel="nofollow noopener">',$tag),1);
    $closed_tag='<a href="https://'.$host.'/x" rel="nofollow">Джерело</a>';
    batch_check($closed_tag,$closed_tag,0);
}
foreach (array('roe.vsei.ua','suspilne.media') as $host) {
    $tag='<a href="https://'.$host.'/x">Джерело</a>';
    batch_check($tag,$tag,0);
    $closed_tag='<a href="https://'.$host.'/x" rel="nofollow noreferrer">Джерело</a>';
    batch_check($closed_tag,'<a href="https://'.$host.'/x" rel="nofollow noopener noreferrer" target="_blank">Джерело</a>',1);
}
$ready='<a href="//www.pravda.com.ua/story?x=1&amp;y=2#example" rel="noreferrer" target="_self"><b>Джерело</b></a>';
$expected='<a href="//www.pravda.com.ua/story?x=1&amp;y=2#example" rel="nofollow noopener noreferrer" target="_blank"><b>Джерело</b></a>';
batch_check($ready,$expected,1);
foreach (array('t.me','facebook.com','m.facebook.com','my.rv.ua','tsn.ua','ua.korrespondent.net','example.pravda.com.ua','pravda.com.ua.example.com','www.example.org') as $host) {
    $tag='<a href="https://'.$host.'/post"><b>Джерело</b></a>';
    batch_check($tag,$tag,0);
}
foreach (array(
    '<p>«На правах реклами».</p>',
    '<p><strong>На правах</strong>&nbsp;реклами</p><!-- /wp:paragraph -->',
) as $marker) batch_check($ready.$marker,$ready.$marker,0);
$sponsored='<a href="https://www.suspilne.media/story" rel="sponsored ugc">Партнер</a>';
batch_check($sponsored,$sponsored,0);
$protected='<iframe src="https://example.org/embed">'.$ready.'</iframe>'
    .'<!-- wp:embed {"url":"https://pravda.com.ua/story"} --><figure>'.$ready.'</figure><!-- /wp:embed -->'
    .'<!-- wp:core-embed/twitter --><figure>'.$ready.'</figure><!-- /wp:core-embed/twitter -->'
    .'<video>'.$ready.'</video><template>'.$ready.'</template><pre>'.$ready.'</pre>[embed]'.$ready.'[/embed]';
batch_check($protected.$ready,$protected.$expected,1);
$wrapper='<a href="https://pravda.com.ua/story"><iframe src="https://example.org/embed"></iframe></a>';
batch_check($wrapper,$wrapper,0);
$unclosed='<a href="https://www.pravda.com.ua/story">Незакрите';
batch_check($unclosed,$unclosed,0,1);
echo "Passed first-batch domain list, advertising, rel status, embedded markup and repeat-run checks.\n";

$project='source_batch_2';
$second_open=array(
    'diloverivne.com','dramteatr.com.ua','erve.ua','opora.rv.ua','suspilne.media','dt.ua',
    'rivne-cerkva.rv.ua','zdolbunivcity.net','hh.ua','rcwa.com.ua','delo.ua','volynnews.com',
    'automoto.ua','goo.gl','kommersant.ua','uk.wikipedia.org','send.monobank.ua','cgm.rv.ua',
    'nbnews.com.ua','vesti.ua','zdolbuniv.com','podrobnosti.ua','censor.net.ua','uz.gov.ua',
    'volynpost.com','city-adm.rv.ua','visnyk.lutsk.ua','kasa.in.ua','khomko.rv.ua','kmu.gov.ua',
    'meteo.gov.ua','roe.vsei.ua','takeinfo.net','vse.rv.ua',
);
foreach ($second_open as $host) {
    $tag='<a href="https://'.$host.'/x">Джерело</a>';
    batch_check($tag,str_replace('">','" target="_blank" rel="nofollow noopener">',$tag),1,0,$project);
    $closed_tag='<a href="https://'.$host.'/x" rel="nofollow">Джерело</a>';
    batch_check($closed_tag,$closed_tag,0,0,$project);
}
foreach (array('prosport.tsn.ua','forms.gle') as $host) {
    $tag='<a href="https://'.$host.'/x">Джерело</a>';
    batch_check($tag,$tag,0,0,$project);
    $closed_tag='<a href="https://'.$host.'/x" rel="ugc noreferrer">Джерело</a>';
    batch_check($closed_tag,'<a href="https://'.$host.'/x" rel="nofollow noopener ugc noreferrer" target="_blank">Джерело</a>',1,0,$project);
    $sponsored='<a href="https://'.$host.'/x" rel="sponsored">Реклама</a>';
    batch_check($sponsored,$sponsored,0,0,$project);
}
$ready='<a href="//www.send.monobank.ua/x?y=1&amp;z=2" rel="noreferrer" target="_self"><b>Джерело</b></a>';
$expected=str_replace(array('rel="noreferrer"','target="_self"'),array('rel="nofollow noopener noreferrer"','target="_blank"'),$ready);
batch_check($ready,$expected,1,0,$project);
$protected='<!-- '.$ready.' --><code>'.$ready.'</code><iframe src="https://example.org/embed">'.$ready.'</iframe>'
    .'<!-- wp:embed {"url":"https://send.monobank.ua/x"} --><figure>'.$ready.'</figure><!-- /wp:embed -->';
batch_check($protected.$ready,$protected.$expected,1,0,$project);
$paid=$ready.'<p>На правах реклами.</p>';
batch_check($paid,$paid,0,0,$project);
foreach (array('pravda.com.ua','tsn.ua','monobank.ua','en.wikipedia.org','other.forms.gle','send.monobank.ua.example.org') as $host) {
    $tag='<a href="https://'.$host.'/x" rel="ugc">Джерело</a><a href="https://'.$host.'/y">Джерело</a>';
    batch_check($tag,$tag,0,0,$project);
}
$overlap='<a href="https://suspilne.media/x" rel="nofollow">Закрите</a>';
batch_check($overlap,$overlap,0,0,$project);
batch_check($overlap,'<a href="https://suspilne.media/x" rel="nofollow noopener" target="_blank">Закрите</a>',1);
echo "Passed second-batch domain list, audit status, advertising, embed protection and repeat-run checks.\n";

$project='source_batch_3';
$third_open=array(
    'telekritika.ua','20minut.ua','biz.liga.net','press-centr.com','fakty.ictv.ua','lb.ua','ukr.net','expres.ua',
    'news.finance.ua','ekvator.ua','ipress.ua','mvs.gov.ua','rvosvita.org.ua','hromadske.ua','news.ub.ua','osvita.ua',
    'ubr.ua','glavcom.ua','fchoscha.inf.ua','obozrevatel.com','champion.com.ua','galinfo.com.ua','opogode.ua','prostobank.ua',
    'rbc.ua','zn.ua','bbc.co.uk','meteoprog.ua','moz.gov.ua','ua.for-ua.com','nashigroshi.org','dpsu.gov.ua',
);
foreach ($third_open as $host) {
    $tag='<a href="https://'.$host.'/x">Джерело</a>';
    batch_check($tag,str_replace('">','" target="_blank" rel="nofollow noopener">',$tag),1,0,$project);
    $closed_tag='<a href="https://'.$host.'/x" rel="nofollow">Джерело</a>';
    batch_check($closed_tag,$closed_tag,0,0,$project);
}
$url='https://fakty.ictv.ua/video/12';
$plain='<a href="'.$url.'" rel="noreferrer"><b>Джерело</b></a>';
$expected=str_replace('rel="noreferrer"','rel="nofollow noopener noreferrer" target="_blank"',$plain);
$embeds=array(
    '<iframe data-src="'.$url.'"></iframe>',
    '<object><param name="movie" value="'.$url.'"><embed src="'.$url.'">'.$plain.'</object>',
    '<object><object data="/player"></object>'.$plain.'</object>',
    '<object><object data="/player"></object><param name="movie" value="'.$url.'">'.$plain.'</object>',
    '<embed src="'.$url.'">',
    '<video><source src="https://media.fakty.ictv.ua/video.mp4"></video>',
    '<audio src="'.$url.'"></audio>',
    '[embed]'.$url.'[/embed]',
    '[video src="'.$url.'"]',
    '[iframe src="'.$url.'"]',
    '<p>'.$url.'</p>',
    "\n".$url."\n",
    '<script src="'.$url.'"></script>',
    '<figure class="wp-block-embed">'.$plain.'</figure>',
    '<div class="embed-container"><div>'.$plain.'</div>'.$plain.'</div>',
    '<div data-player-url="'.$url.'">'.$plain.'</div>',
    '<a href="'.$url.'" data-video-url="'.$url.'">Відео</a>',
    '<a href="'.$url.'"><embed src="https://example.org/player"></a>',
    '<a href="'.$url.'"><iframe src="'.$url.'"></iframe></a>',
    '<!-- wp:embed {"url":"https:\\/\\/fakty.ictv.ua\\/video\\/12"} --><figure>'.$plain.'</figure><!-- /wp:embed -->',
);
foreach ($embeds as $embed) {
    batch_check($embed,$embed,0,1,$project);
    batch_check($plain.$embed,$expected.$embed,1,1,$project);
    batch_check($embed.$plain,$embed.$expected,1,1,$project);
    $mixed=OGO_MC_Engine::analyze($plain.$embed,$project);
    if ($mixed['blocking_review']!==0 || strpos($mixed['summary'],'Вставки для ручної перевірки')===false) {
        throw new RuntimeException('Independent ordinary link and embed must be reported together');
    }
}
$ignored='<!-- <object data="'.$url.'">'.$plain.'</object> -->'
    .'<code>[embed]'.$url.'[/embed]</code><pre>'.$plain.'</pre>'
    .'<div class="embed-container"><!-- <embed src="'.$url.'"> --><code>'.$url.'</code></div>'
    .'<iframe src="https://fakty.ictv.ua.example.org/player"></iframe>'
    .'<a href="https://ictv.ua/video">Інший домен</a>'
    .'<a href="https://example.org">'.$url.'</a>'
    .'<a href="'.$url.'" rel="sponsored">Реклама</a>';
batch_check($ignored,$ignored,0,0,$project);
$paid=$plain.'<p>На правах реклами.</p>';
batch_check($paid,$paid,0,0,$project);
$www='<a href="//www.fakty.ictv.ua/video">Джерело</a>';
batch_check($www,str_replace('">','" target="_blank" rel="nofollow noopener">',$www),1,0,$project);
$unclosed='<a href="'.$url.'">Незакрите';
batch_check($unclosed,$unclosed,0,1,$project);
echo "Passed third-batch exact domains, ordinary links, embed review, conflicts and repeat-run checks.\n";
