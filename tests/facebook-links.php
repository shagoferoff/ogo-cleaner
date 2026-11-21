<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';

function facebook_check($input,$expected,$changed,$manual) {
    $result=OGO_MC_Engine::analyze($input,'facebook_urls');
    if ($result['after']!==$expected || $result['removed']!==$changed || $result['review']!==$manual
        || $result['total']!==$changed+$manual || ($result['blocking_review']??0)!==0) {
        throw new RuntimeException('Facebook preview mismatch: '.var_export($result,true));
    }
    if (OGO_MC_Engine::analyze($expected,'facebook_urls')['removed']) throw new RuntimeException('Repeat-run protection failed');
}

$link='<a href="//www.facebook.com/ogo/posts/1?x=1&amp;y=2" rel="noreferrer noopener noreferrer" target="_self"><strong>Новина</strong></a>';
$after='<a href="//www.facebook.com/ogo/posts/1?x=1&amp;y=2" rel="nofollow noopener noreferrer" target="_blank"><strong>Новина</strong></a>';
$frame='<iframe src="https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2Fogo%2Fvideos%2F1"></iframe>';
$widget='<div class="fb-post" data-href="https://facebook.com/ogo/posts/1"><blockquote class="fb-xfbml-parse-ignore">'.$link.'</blockquote></div>';
$block='<!-- wp:embed {"providerNameSlug":"facebook"} --><figure>'.$link.'</figure><!-- /wp:embed -->';
$legacy_block='<!-- wp:core-embed/facebook --><figure>'.$link.'</figure><!-- /wp:core-embed/facebook -->';
$shortcode='[embed]https://fb.watch/example/[/embed]';
$xfbml='<fb:post href="https://facebook.com/ogo/posts/1" />';
$fallback='<blockquote class="wp-embedded-content">'.$link.'</blockquote>';
facebook_check($link,$after,1,0);
foreach (array($frame,$widget,$block,$legacy_block,$shortcode,$xfbml,$fallback) as $embed) {
    facebook_check($embed,$embed,0,1);
    facebook_check($link.' '.$embed,$after.' '.$embed,1,1);
    facebook_check($embed.' '.$link,$embed.' '.$after,1,1);
}
$wrap='<a href="https://facebook.com/ogo/posts/1">'.$frame.'</a>';
facebook_check($wrap,$wrap,0,1);
$skip='<a href="https://facebook.com/ogo" rel="nofollow">Закрите</a>'
    .'<a href="https://facebook.com/ogo" rel="sponsored">Реклама</a>'
    .'<a href="https://facebook.com.evil.example/ogo">Інший домен</a>'
    .'<a href="https://m.facebook.com/ogo">Інший рядок ревізії</a>'
    .'<a href="https://fb.watch/example/">Інший рядок ревізії</a>'
    .'<a href="https://t.me/ogo">Telegram</a>'
    .'<script src="https://connect.facebook.net/uk_UA/sdk.js"></script>'
    .'<!-- '.$widget.' --><pre>'.$frame.$link.'</pre>';
facebook_check($skip,$skip,0,0);
$paid=$link.'<p>На правах реклами.</p>';
facebook_check($paid,$paid,0,0);
$plain='<a href="http://facebook.com/ogo">Facebook</a>';
$closed='<a href="http://facebook.com/ogo" target="_blank" rel="nofollow noopener">Facebook</a>';
facebook_check($plain,$closed,1,0);
echo "Passed Facebook anchors, embed protection, manual review, scope and repeat-run checks.\n";
