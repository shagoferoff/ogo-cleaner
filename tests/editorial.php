<?php
if (!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
require_once __DIR__.'/../ogo-media-cleaner/includes/audit-engine.php';
$count=0;
function editorial_check($input,$expected,$total,$changed,$review) {
    global $count;
    $r=OGO_MC_Engine::analyze($input,'editorial_urls');
    foreach (array('after'=>$expected,'total'=>$total,'removed'=>$changed,'review'=>$review) as $key=>$value) {
        if ($r[$key]!==$value) throw new RuntimeException('Editorial case '.($count+1).' failed: '.$key.' '.var_export($r,true));
    }
    $again=OGO_MC_Engine::analyze($r['after'],'editorial_urls');
    if ($again['after']!==$r['after'] || $again['removed']!==0) throw new RuntimeException('Editorial repeat-run failure');
    ++$count;
}
$map=array('/gazeta/rubrics/view/gazeta-recipes'=>'reczepty','/gazeta/rubrics/view/goroskop'=>'goroskop','/vybir2010/rubrics/view/vybory-news'=>'vybory','/vybir2010/rubrics/view/vybory-newsregion'=>'vybory','/rubrics/view/pidsymku_2011'=>'pidsumky-roku','/rubrics/view/ogo20'=>'rejtyngy','/rubrics/view/defense'=>'oborona','/rubrics/view/volyn'=>'volyn','/rubrics/view/euro'=>'yevropa','/rubrics/view/evro2012'=>'chempionat-yevropy-z-futbolu','/rubrics/view/dovidka'=>'dovidka','/rubrics/view/oneday'=>'odyn-den','/rubrics/view/policy'=>'polityka','/rubrics/view/new-face'=>'novi-oblychchya-rivnogo','/rubrics/view/elect2012'=>'vybory','/rubrics/view/biznesova_kyhnya'=>'biznes','/rubrics/view/agro'=>'agrovyrobnyky','/rubrics/view/vlada'=>'vlada','/rubrics/view/people-handmade'=>'rukodillya');
foreach (array('top-influence2011','top-influencewoman2011','top-influence','top-influence-2010','top-influencepoliticians','top-20-2012','top-influence2013','people-top20-tvorzinezalezhnosti','top-lideru','top-2014','top-20-2013','top-influencewoman','top-influence-molod-2013','top-2015','top-publicservant','top-2026','political-influence') as $slug) $map['/rubrics/view/'.$slug]='rejtyngy';
foreach ($map as $path=>$tag) {
    $target='https://ogo.ua/tag/'.$tag.'/';
    foreach (array('','/','/page:16','/page:16/') as $suffix) {
        $old='https://ogo.ua'.$path.$suffix;
        $html='<p><a href="'.$old.'" title="a > b"><strong>Текст</strong></a></p>';
        editorial_check($html,str_replace($old,$target,$html),1,1,0);
        $audit=OGO_MC_Audit_Engine::classify($old);
        if ($audit['category']!=='known' || $audit['target_url']!==$target) throw new RuntimeException('Audit mapping disagrees: '.$old);
    }
}
foreach (array('http://ogo.ua','https://www.ogo.ua','//ogo.ua','//www.ogo.ua','HTTPS://OGO.UA','https://ogo.ua:443','http://ogo.ua:80','') as $origin) {
    foreach (array('"',"'",'') as $quote) {
        editorial_check('<A HREF='.$quote.$origin.'/rubrics/view/policy/page:2'.$quote.' target="_blank">Політика</A>','<A HREF='.$quote.'https://ogo.ua/tag/polityka/'.$quote.' target="_blank">Політика</A>',1,1,0);
    }
}
$html='<!-- wp:paragraph --><p><a href=" /gazeta/rubrics/view/gazeta-recipes/?x=1&amp;y=2#page-top ">Рецепти</a> <a href="/rubrics/view/euro/page:16?x=1#section">Європа</a></p><!-- /wp:paragraph -->';
editorial_check($html,'<!-- wp:paragraph --><p><a href=" https://ogo.ua/tag/reczepty/?x=1&amp;y=2 ">Рецепти</a> <a href="https://ogo.ua/tag/yevropa/?x=1#section">Європа</a></p><!-- /wp:paragraph -->',2,2,0);
foreach (array('exclusive','life','nadzvychaini','region','opytuvannya','consumer','top','ukraine','foto-dnya') as $slug) {
    foreach (array('','/','/page:16','/page:16/') as $suffix) {
        $url='https://ogo.ua/rubrics/view/'.$slug.$suffix;
        $html='<a href="'.$url.'">Активна рубрика</a>';
        editorial_check($html,$html,0,0,0);
        if (OGO_MC_Audit_Engine::classify($url)['category']!=='active') throw new RuntimeException('Active whitelist altered');
    }
}
foreach (array('https://evil.example/rubrics/view/defense','https://ogo.ua.evil.example/rubrics/view/defense','https://user@ogo.ua/rubrics/view/defense','https://ogo.ua:444/rubrics/view/defense','/rubrics/view/topology','/rubrics/view/desktop','/rubrics/view/stopy','/woman/rubrics/view/top-new','/gazeta/rubrics/view/pidsymku_2011','/rubrics/view/unknown','/tag/rejtyngy/') as $url) {
    $html='<a href="'.$url.'">Інше</a>';editorial_check($html,$html,0,0,0);
}
foreach (array('script','style','textarea','pre','code') as $element) {
    $html='<'.$element.'><a href="/rubrics/view/defense">x</a></'.$element.'>';editorial_check($html,$html,0,0,0);
}
foreach (array('<!-- <a href="/rubrics/view/defense">x</a> -->',"<div data-note='<a href=\"/rubrics/view/defense\">x</a>'>data</div>") as $html) editorial_check($html,$html,0,0,0);
foreach (array('<a href="/rubrics/view/defense">x','<a href="/rubrics/view/defense"><a href="https://example.com">nested</a></a>','<a href="/rubrics/view/defense/more">unsupported</a>') as $html) editorial_check($html,$html,1,0,1);
$html='<a href="/rubrics/view/defense">good</a><a href="/rubrics/view/policy">unclosed';
editorial_check($html,'<a href="https://ogo.ua/tag/oborona/">good</a><a href="/rubrics/view/policy">unclosed',2,1,1);
echo 'Passed '.$count." editorial mapping cases, audit agreement and active-rubric protection.\n";
if (isset($argv[1])) {
    $cases=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    $links=0;
    foreach ($cases as $case) {
        editorial_check('<a href="'.$case['url'].'">Текст</a>','<a href="'.$case['target'].'">Текст</a>',1,1,0);
        $a=OGO_MC_Audit_Engine::classify($case['url']);
        if ($a['category']!=='known' || $a['target_url']!==$case['target']) throw new RuntimeException('Export mapping audit mismatch');
        $links+=$case['links'];
    }
    echo 'All '.count($cases).' unknown rubric groups from the audit are covered ('.$links." link occurrences).\n";
}
