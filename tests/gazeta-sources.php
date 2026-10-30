<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
$count=0;
function source_check($html,$expected,$total,$removed,$review){global $count;$r=OGO_MC_Engine::analyze($html,'gazeta_sources');foreach(array('after'=>$expected,'total'=>$total,'removed'=>$removed,'review'=>$review) as $key=>$value)if($r[$key]!==$value)throw new RuntimeException('Source case '.($count+1).' '.$key.' '.var_export($r,true));$again=OGO_MC_Engine::analyze($r['after'],'gazeta_sources');if($again['after']!==$r['after'] || $again['removed']!==0)throw new RuntimeException('Not idempotent');++$count;}
foreach(array('http://gazeta.rv.ua/','http://gazeta.rv.ua/articles/view/2011-06-16/17243.html','https://gazeta.rv.ua/','https://www.gazeta.rv.ua/articles/view/2011-06-16/17243.html?x=1&amp;y=2#part','//gazeta.rv.ua/article','http://GAZETA.RV.UA') as $url) {
    foreach(array('"',"'",'') as $quote){$html='За матеріалами <a href='.$quote.$url.$quote.'>"Рівненської газети"</a>';source_check($html,'За матеріалами "Рівненської газети"',1,1,0);}
}
foreach(array('"Рівненської газети"','&quot;Рівненської газети&quot;','«Рівненської газети»','“Рівненської газети”','„Рівненської газети“','Рівненської газети','<strong>"Рівненської газети"</strong>','"<span>Рівненської</span> <em>газети</em>"','"Рівненської&nbsp;газети"') as $label){$html='<p>За матеріалами <a href="http://gazeta.rv.ua/" target="_blank">'.$label.'</a>.</p>';source_check($html,'<p>За матеріалами '.$label.'.</p>',1,1,0);}
foreach(array('За&nbsp;матеріалами ','За матеріалами: ','ЗА МАТЕРІАЛАМИ ','За <strong>матеріалами</strong> ','За матеріалами<br>','За матеріалами'."\r\n") as $prefix){$html=$prefix.'<a href="http://gazeta.rv.ua/">"Рівненської газети"</a>';source_check($html,$prefix.'"Рівненської газети"',1,1,0);}
foreach(array(array('За матеріалами "','"'),array('За матеріалами «','»')) as $quotes){$html=$quotes[0].'<a href="http://gazeta.rv.ua/">Рівненської газети</a>'.$quotes[1];source_check($html,$quotes[0].'Рівненської газети'.$quotes[1],1,1,0);}
foreach(array('https://gazeta.rv.ua.evil.example/','https://evil.example/gazeta.rv.ua','https://gazeta.ogo.ua/','https://user@gazeta.rv.ua/','/gazeta/','mailto:gazeta.rv.ua@gmail.com') as $url){$html='За матеріалами <a href="'.$url.'">"Рівненської газети"</a>';source_check($html,$html,0,0,0);}
foreach(array('<a href="http://gazeta.rv.ua/">"Рівненської газети"</a>','<p>За матеріалами</p><p><a href="http://gazeta.rv.ua/">"Рівненської газети"</a></p>',"За матеріалами\n\n<a href=\"http://gazeta.rv.ua/\">\"Рівненської газети\"</a>",'<span data-credit="За матеріалами"><a href="http://gazeta.rv.ua/">"Рівненської газети"</a></span>') as $html)source_check($html,$html,0,0,0);
foreach(array('script','style','textarea','pre','code') as $tag){$html='<'.$tag.'>За матеріалами <a href="http://gazeta.rv.ua/">"Рівненської газети"</a></'.$tag.'>';source_check($html,$html,0,0,0);}
$html='<!-- За матеріалами <a href="http://gazeta.rv.ua/">"Рівненської газети"</a> -->';source_check($html,$html,0,0,0);
$html="<div data-fake='За матеріалами <a href=\"http://gazeta.rv.ua/\">\"Рівненської газети\"</a>'>Текст</div>";source_check($html,$html,0,0,0);
foreach(array('Іншої газети','"Рівненської газети','<img src="x.jpg">','<strong>"Рівненської газети"') as $label){$html='За матеріалами <a href="http://gazeta.rv.ua/">'.$label.'</a>';source_check($html,$html,1,0,1);}
foreach(array('За матеріалами <a href="http://gazeta.rv.ua/">"Рівненської газети"','За матеріалами <a href="http://gazeta.rv.ua/"><a href="http://example.com/">"Рівненської газети"</a></a>','За матеріалами <a href="http://gazeta.rv.ua/"/>') as $html)source_check($html,$html,1,0,1);
$good='За матеріалами <a href="http://gazeta.rv.ua/">"Рівненської газети"</a>';
$other='<a href="http://gazeta.rv.ua/article">Інша стаття</a>';
$html='<p>'.$good.'</p><p>'.$other.'</p>';source_check($html,'<p>За матеріалами "Рівненської газети"</p><p>'.$other.'</p>',1,1,0);
$html='<p>'.$good.'</p><div>'.$good.'</div>';source_check($html,'<p>За матеріалами "Рівненської газети"</p><div>За матеріалами "Рівненської газети"</div>',2,2,0);
$html='<!-- wp:paragraph --><p>'.$good.'</p><!-- /wp:paragraph -->';source_check($html,'<!-- wp:paragraph --><p>За матеріалами "Рівненської газети"</p><!-- /wp:paragraph -->',1,1,0);
$html='<p>За матеріалами <a href="http://gazeta.rv.ua/">"Рівненської газети"</a></p><p>За матеріалами <a href="http://gazeta.rv.ua/">Інше джерело</a></p>';source_check($html,'<p>За матеріалами "Рівненської газети"</p><p>За матеріалами <a href="http://gazeta.rv.ua/">Інше джерело</a></p>',2,1,1);
echo 'Passed '.$count." gazeta source-caption unlink cases, protected content and repeat-run checks.\n";
