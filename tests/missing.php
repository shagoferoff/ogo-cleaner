<?php
require_once __DIR__.'/../ogo-media-cleaner/includes/engine.php';
$lookup=array();
function get_post($id) { global $lookup; return $lookup[$id]??null; }
function get_permalink($p) { return $p->permalink; }
function home_url($path='/') { return 'https://ogo.ua'.$path; }
function is_post_publicly_viewable($p) { return $p->viewable??true; }
$count=0;
function missing_check($html,$expected,$total=1,$changed=1,$review=0) {
    global $count;
    if (class_exists('OGO_MC_Legacy')) OGO_MC_Legacy::clear_cache();
    $r=OGO_MC_Engine::analyze($html,'legacy_missing');
    foreach (array('after'=>$expected,'total'=>$total,'removed'=>$changed,'review'=>$review) as $key=>$value) if ($r[$key]!==$value) throw new RuntimeException('Missing case '.($count+1).' '.$key.' '.var_export($r,true));
    if (!$review) {
        $again=OGO_MC_Engine::analyze($r['after'],'legacy_missing');
        if ($again['after']!==$r['after'] || $again['removed']!==0) throw new RuntimeException('Not idempotent');
    }
    ++$count;
}
function old_link($id,$label='Стаття',$attr='') { return '<a href="https://ogo.ua/articles/view/2010-02-12/'.$id.'.html"'.$attr.'>'.$label.'</a>'; }
// The editor's exact sentence preserves the dossier and all prose.
$prefix='Рівненський губернатор <a href="https://my.rv.ua/dossier-details/matchuk-viktor-yosipovich/">Віктор Матчук</a>, не дивлячись на ';
$suffix=', теж завітав на свято. Щоправда, згодом зламана рука дала про себе знати, коли Мігель да Куня у пориві емоцій декілька раз постукав Матчука по плечу.';
missing_check($prefix.old_link(18230,'нещодавно отриману травму').$suffix,$prefix.'нещодавно отриману травму'.$suffix);
$fixtures=array(
 old_link(18278,'Вищий пілотаж Віктора Чайки'),
 '<div align="justify"><span style="font-size: small;">'.old_link(18280,'У Рівному відкривають нові торгові центри навіть під час кризи ').'</span></div>',
 '<p align="justify"><span style="font-size: small;">'.old_link(18285,'Читати більше').'  </span></p>',
 '<font size="2">'.old_link(18314,'ЧИТАТИ ДЕТАЛЬНІШЕ').'</font><br/>',
 "<p>\n<strong><font size=\"2\">".old_link(18378,'Вартість турів на ogo.rv.ua ',' target="_blank"')."</font></strong>\n</p>",
 "<div align=\"justify\">\n<font size=\"2\">".old_link(18335,'Дізнатися більше ')."</font>\n</div>",
 "<div align=\"justify\">\n<font size=\"2\">".old_link(18385,'Дізнатися більше на сайті  тижневика "ОГО"')."</font>\n</div>",
 "<div align=\"justify\">\n<font size=\"2\">".old_link(18479,'Дізнатися більше ')."</font>\n</div>"
);
foreach ($fixtures as $html) missing_check($html,'');
$archive='<a href="https://ogo.ua/archive/2010-02-04/site/33708">Фабрика ТзОВ "Фапомед" запустила тестове виробництво медичного одягу більше тижня тому</a>';
foreach (array(false,true) as $exists) {
    if ($exists) $lookup[33708]=(object)array('post_type'=>'post','post_status'=>'publish','permalink'=>'https://ogo.ua/poppy/');
    missing_check($archive,$archive,1,0,1);
}
$a=old_link(18230,'<strong>нещодавню</strong> травму');
foreach (array('Текст '.$a.' далі.','<p>Текст <span>'.$a.'</span> далі.</p>','<div>Текст <font>'.$a.'</font>.</div>') as $html) missing_check($html,str_replace($a,'<strong>нещодавню</strong> травму',$html));
foreach (array('<p><span><strong>'.old_link(18230).'</strong></span></p>','<div><p>'.old_link(18230).'</p></div>','<span><font>'.old_link(18230).'</font></span><br />') as $html) missing_check($html,'');
$html='<div>До<p>'.old_link(18230).'</p>Після</div>';missing_check($html,'<div>ДоПісля</div>');
$html='<div><p>До</p><p>'.old_link(18230).'</p><p>Після</p></div>';missing_check($html,'<div><p>До</p><p>Після</p></div>');
$html=old_link(18230).'<br />Текст';missing_check($html,'Текст');
$html='До<br>'.old_link(18230).'<br>Після';missing_check($html,'До<br>Після');
$html='До'."\n\n".old_link(18230)."\n\n".'Після';missing_check($html,'До'."\n\n\n\n".'Після');
// Additional text and independent links prevent block deletion.
$html='<p>'.old_link(18230).' <a href="https://example.com/">Інше</a></p>';
missing_check($html,'<p>Стаття <a href="https://example.com/">Інше</a></p>');
$html='<p>'.old_link(18230).' і '.old_link(18278).'</p>'; missing_check($html,'<p>Стаття і Стаття</p>',2,2);
$html='<p>'.old_link(18230).'</p><p>'.old_link(18278).'</p>';missing_check($html,'',2,2);
// Existing records are never classified as missing, regardless of availability.
$lookup[18230]=(object)array('post_type'=>'post','post_status'=>'publish','post_password'=>'','permalink'=>'https://ogo.ua/current/');
$html='<p>'.old_link(18230).'</p>';missing_check($html,'<p><a href="https://ogo.ua/current/">Стаття</a></p>');
foreach (array('post_status'=>'draft','post_type'=>'page','post_password'=>'test','viewable'=>false,'permalink'=>'https://evil.example/') as $key=>$value) {
    $save=clone $lookup[18230];$lookup[18230]->$key=$value; missing_check($html,$html,1,0,1); $lookup[18230]=$save;
}
$html='<p>'.old_link(18230).'</p><p>'.old_link(18278).'</p>';
missing_check($html,'<p><a href="https://ogo.ua/current/">Стаття</a></p>',2,2);
unset($lookup[18230]);
foreach (array('https://lt.ogo.ua/articles/view/2010-02-12/18230.html','https://ogo.ua/articles/view/2010-02-12/18230.html?x=1','https://ogo.ua/articles/view/2010-02-12/18230.htmlhttps://ogo.ua/articles/view/2010-02-12/18278.html') as $url) {
    $html='<p><a href="'.$url.'">Стаття</a></p>';missing_check($html,$html,1,0,1);
}
foreach (array('script','style','textarea','pre','code') as $tag) {
    $html='<'.$tag.'>'.old_link(18230).'</'.$tag.'>';missing_check($html,$html,0,0);
}
$html='<!-- '.old_link(18230).' -->';missing_check($html,$html,0,0);
$html="<div data-fake='".old_link(18230)."'>x</div>";missing_check($html,$html,0,0);
foreach (array('<p>'.old_link(18230).'</div>','<p>'.old_link(18230),'<a href="/articles/view/2010-02-12/18230.html"><a href="https://example.com/">nested</a></a>',old_link(18230,'<img src="x.jpg">')) as $html) missing_check($html,$html,1,0,1);
$html='<p><img src="x.jpg">'.old_link(18230).'</p>';missing_check($html,'<p><img src="x.jpg">Стаття</p>');
$html='<div id="keep">'.old_link(18230).'</div>';missing_check($html,'<div id="keep"></div>');
// Preserve named anchor destinations for manual review.
$html='<p>'.old_link(18230,'Стаття',' id="keep"').'</p>';missing_check($html,$html,1,0,1);
// A query error must never be interpreted as proof of absence.
$wpdb=(object)array('last_error'=>'database unavailable');
$thrown=false;
try { OGO_MC_Engine::analyze(old_link(18230),'legacy_missing'); } catch (RuntimeException $e) { $thrown=true; }
if (!$thrown) throw new RuntimeException('Database failure was not blocked');
echo 'Passed '.$count." missing-article cleanup cases and database-failure protection.\n";
