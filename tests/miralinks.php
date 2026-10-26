<?php
require_once __DIR__.'/../ogo-miralinks-audit/includes/helpers.php';
require_once __DIR__.'/../ogo-miralinks-audit/includes/inventory.php';
require_once __DIR__.'/../ogo-miralinks-audit/includes/list.php';
$count=0;
function external_check($ok,$why) {global $count;if(!$ok)throw new RuntimeException($why);++$count;}
foreach(array('https://shop.example/item?x=1&amp;y=2#part','http://WWW.Shop.example/item','//shop.example/item','https://ogo.ua.evil.example/x','https://ogo.ua@evil.example/x','https://приклад.укр/товар') as $url) {
    $rows=OGO_MLA_Inventory::extract('<p>До <a href="'.$url.'"><strong>Купити</strong> товар&nbsp;тут</a> після</p>');
    external_check(count($rows)===1 && $rows[0]['anchor_text']==='Купити товар тут' && $rows[0]['link_count']===1,'External addresses and anchor labels are inventoried');
    external_check($rows[0]['raw_url']===$url,'Original href is preserved');
}
foreach(array('https://ogo.ua/path','http://WWW.OGO.UA./path','https://lt.ogo.ua/x','https://ogo.rv.ua/a','https://sub.ogo.rv.ua/a','/articles/view/2020-04-09/107026.html','../local','#part','mailto:test@example.com','tel:123','javascript:alert(1)','data:text/plain,test') as $url) {
    external_check(OGO_MLA_Inventory::extract('<a href="'.$url.'">Текст</a>')===array(),'Internal/relative/non-web hrefs are skipped: '.$url);
}
foreach(array('my.rv.ua','dity.my.rv.ua','volyninfo.com','www.volyninfo.com') as $domain) {
    $rows=OGO_MLA_Inventory::extract('<a href="https://'.$domain.'/x">Джерело</a>');
    external_check(count($rows)===1 && $rows[0]['kind']==='network','Related sites are separate from ordinary external URLs');
}
$html='<a href="https://shop.example/">Один</a><a href="https://shop.example/">Один</a><a href="https://shop.example/">Два</a>';
$rows=OGO_MLA_Inventory::extract($html);
external_check(count($rows)===2 && $rows[0]['link_count']===2 && $rows[1]['link_count']===1,'Same URL with different anchors remains distinct');
foreach(array('script','style','textarea','pre','code') as $tag)external_check(OGO_MLA_Inventory::extract('<'.$tag.'><a href="https://shop.example/">Код</a></'.$tag.'>')===array(),'Protected block skipped');
external_check(OGO_MLA_Inventory::extract('<!-- <a href="https://shop.example/">Код</a> -->')===array(),'Comments skipped');
external_check(OGO_MLA_Inventory::extract("<div data-fake='<a href=\"https://shop.example/\">x</a>'>data</div>")===array(),'Fake anchors inside attribute values skipped');
external_check(OGO_MLA_Inventory::extract('https://shop.example/')===array(),'Bare URLs skipped');
foreach(array('<a href="https://shop.example/">Незакрите','<a href="https://shop.example/"><a href="https://other.example/">Вкладене</a></a>','<a href="https://shop.example"/>','<a href="http://">Помилка</a>') as $html) {
    $rows=OGO_MLA_Inventory::extract($html);
    external_check(count($rows)>0 && !array_filter($rows,function($r){return $r['kind']!=='review';}),'Ambiguous HTML and URLs flagged for review');
}
$rows=OGO_MLA_Inventory::extract('<a href="https://shop.example/"><img src="image.jpg"></a>');
external_check($rows[0]['anchor_text']==='[посилання без тексту]','Image-only anchor remains visible in the inventory');
$oversize=OGO_MLA_Inventory::extract('<a href="https://'.str_repeat('a',254).'.example/">Помилка</a>');
external_check($oversize[0]['kind']==='review' && $oversize[0]['domain']==='','Oversize host cannot overflow the report table');
$groups=OGO_MLA_List::groups();$ids=OGO_MLA_List::ids();
external_check(count($groups['monthly'])===2215 && count($groups['other'])===0 && count($ids)===2215 && count(array_unique($ids))===2215,'Workbook ID list matches the deduplicated/excluded worklist');
foreach(array(114709,115043,115424) as $hidden_id)external_check(!in_array($hidden_id,$ids,true),'Hidden monthly-sheet ID excluded');
external_check(in_array(107026,$ids,true),'Example article is included');
external_check(!in_array(94582,$ids,true),'Conflicting green duplicate is excluded');
external_check(!in_array(113890,$ids,true),'Green March record is excluded');
external_check(!method_exists('OGO_MLA_HTML','analyze'),'Standalone plugin has no cleanup engine');
echo 'Passed '.$count." external-link inventory and workbook-scope checks.\n";
