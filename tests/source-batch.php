<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';

function batch_check($before,$after,$updated,$review=0) {
    $r=OGO_MC_Engine::analyze($before,'source_batch_1');
    if ($r['after']!==$after || $r['removed']!==$updated || $r['review']!==$review || $r['total']!==$updated+$review) {
        throw new RuntimeException('Unexpected batch preview: '.var_export($r,true));
    }
    if (OGO_MC_Engine::analyze($after,'source_batch_1')['removed']!==0) throw new RuntimeException('Second scan changes already processed links');
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
