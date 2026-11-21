<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';

function telegram_check($input,$expected,$changed,$manual) {
    $result=OGO_MC_Engine::analyze($input,'telegram_urls');
    if ($result['after']!==$expected || $result['removed']!==$changed || $result['review']!==$manual || $result['total']!==$changed+$manual) {
        throw new RuntimeException('Telegram preview mismatch: '.var_export($result,true));
    }
    if (OGO_MC_Engine::analyze($expected,'telegram_urls')['removed']) throw new RuntimeException('Repeat-run protection failed');
    return $result;
}
$link='<a href="//www.t.me/channel/12?x=1&amp;y=2" rel="noreferrer"><strong>Новина</strong></a>';
$after='<a href="//www.t.me/channel/12?x=1&amp;y=2" rel="nofollow noopener noreferrer" target="_blank"><strong>Новина</strong></a>';
$widget='<script async src="https://telegram.org/js/telegram-widget.js?22" data-telegram-post="channel/12" data-width="100%"></script>';
$frame='<iframe src="https://t.me/channel/12?embed=1"></iframe>';
$object='<object data="https://t.me/channel/12?embed=1">'.$link.'</object>';
$nested_object='<object><object data="/other"></object>'.$link.'</object>';
$sibling_objects='<object><object data="/other"></object><object data="/more"></object>'.$link.'</object>';
$block='<!-- wp:embed {"url":"https://t.me/channel/12"} --><figure>'.$link.'</figure><!-- /wp:embed -->';
$legacy_block='<!-- wp:core-embed/telegram --><figure>'.$link.'</figure><!-- /wp:core-embed/telegram -->';
$shortcode='[embed]https://t.me/channel/12[/embed]';
$legacy_widget='<blockquote class="telegram-post"><blockquote>'.$link.'</blockquote>'.$link.'</blockquote>';
$wp_fallback='<blockquote class="wp-embedded-content">'.$link.'</blockquote>';
telegram_check($link,$after,1,0);
foreach (array($widget,$frame,$object,$nested_object,$sibling_objects,$block,$legacy_block,$shortcode,$legacy_widget,$wp_fallback) as $embed) {
    telegram_check($embed,$embed,0,1);
    $mixed=telegram_check($link.' '.$embed,$after.' '.$embed,1,1);
    telegram_check($embed.' '.$link,$embed.' '.$after,1,1);
    if ($mixed['blocking_review']!==0 || strpos($mixed['summary'],'Вставки Telegram')===false) throw new RuntimeException('Mixed content must show manual embeds and allow the independent link edit');
}
$wrap='<a href="https://t.me/channel/12">'.$frame.'</a>';
telegram_check($wrap,$wrap,0,1);
$bez='<a href="https://t.me/channel/12" rel="nofollow">Вже закрите</a><a href="https://t.me/channel/12" rel="sponsored">Реклама</a>'
    .'<a href="https://t.me.evil.example/channel">Інший домен</a><a href="https://facebook.com/channel">Facebook</a>'
    .'<!-- '.$widget.' -->'.'<pre>'.$frame.$link.'</pre>';
telegram_check($bez,$bez,0,0);
$paid=$link.'<p>На правах реклами.</p>';
telegram_check($paid,$paid,0,0);
$broken='<a href="https://t.me/channel/12">незакрите';
$result=telegram_check($broken,$broken,0,1);
if ($result['blocking_review']!==1) throw new RuntimeException('Malformed anchors need manual review');
echo "Passed Telegram anchors, embed protection, manual review, scope and repeat-run checks.\n";
