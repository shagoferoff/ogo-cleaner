<?php
require_once __DIR__.'/../scripts/miralinks-unwrap.php';
$count=0;
function unwrap_check($html,$expected,$links){global $count;$r=OGO_Miralinks_Unwrap::analyze($html);if($r['after']!==$expected || $r['links']!==$links)throw new RuntimeException('Unwrap failure '.var_export($r,true));$again=OGO_Miralinks_Unwrap::analyze($r['after']);if($again['after']!==$expected || $again['links'])throw new RuntimeException('Repeat failure');++$count;}
foreach(array('https://shop.example/item','http://shop.example/item','//shop.example/item','https://shop.example/Odessa region/apartments','https://ogo.ua.evil.example/') as $url) {
    unwrap_check('<p>До <a href="'.$url.'"><strong>Товар 🐈</strong></a> після.</p>','<p>До <strong>Товар 🐈</strong> після.</p>',1);
}
foreach(array('https://ogo.ua/internal','https://lt.ogo.ua/path','http://www.ogo.ua./path','https://ogo.rv.ua/path','https://sub.ogo.rv.ua/path','/local/path','../relative','#part','mailto:test@example.com','tel:123','javascript:alert(1)') as $url) {
    $html='<a href="'.$url.'">Зберегти</a>';unwrap_check($html,$html,0);
}
foreach(array('script','style','textarea','pre','code') as $tag) {
    $html='<'.$tag.'><a href="https://shop.example/">Код</a></'.$tag.'>';unwrap_check($html,$html,0);
}
$html='<!-- <a href="https://shop.example/">Коментар</a> -->';unwrap_check($html,$html,0);
$html="<div data-test='<a href=\"https://shop.example/\">attribute</a>'>Текст</div>";unwrap_check($html,$html,0);
unwrap_check('<a href=https://shop.example/ target="_blank"><img src="x.jpg"></a>','<img src="x.jpg">',1);
$html='<a href="https://ogo.ua/local">Внутрішнє</a> <a href="https://shop.example/">Реклама</a>';unwrap_check($html,'<a href="https://ogo.ua/local">Внутрішнє</a> Реклама',1);
$html="Текст\r\n\r\n<A HREF='https://shop.example/' rel='nofollow'>Один</A> і <a href=\"https://shop.example/\">Два</a>\r\n";unwrap_check($html,"Текст\r\n\r\nОдин і Два\r\n",2);
$bad=0;foreach(array('<a href="https://shop.example/">Незакрите','<a href="https://shop.example/"><a href="/local">Вкладене</a></a>') as $html){try{OGO_Miralinks_Unwrap::analyze($html);}catch(RuntimeException $e){++$bad;}}
if($bad!==2)throw new RuntimeException('Malformed anchors were not rejected');
echo 'Passed '.$count." byte-preserving advertising unwrap cases and malformed-HTML protection.\n";
