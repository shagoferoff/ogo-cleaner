<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) { fwrite(STDERR,"Set OGO_TEST_WP_ROOT to a dedicated test WordPress installation.\n"); exit(2); }
define('DOING_AJAX',true); require $root.'/wp-load.php';
if (!defined('DB_NAME') || DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
wp_set_current_user(get_user_by('login','audit_admin')->ID);
add_filter('pre_wp_mail',function(){return true;});
update_option('permalink_structure','/%postname%/'); global $wp_rewrite; $wp_rewrite->init();
class OGO_Missing_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Missing_Test_Exit();};});
$count=0;
function missing_assert($ok,$why) { global $count; if (!$ok) throw new RuntimeException($why); ++$count; }
function missing_request($mode,$run='',$ids=null,$project='legacy_missing') {
    $_POST=array('mode'=>$mode,'run'=>$run,'project'=>$project,'backup'=>'1','nonce'=>wp_create_nonce('ogo_mc'));
    if ($ids!==null) { $_POST['ids']=$ids; $_POST['selected']='1'; }
    $_REQUEST=$_POST; ob_start();
    try { do_action('wp_ajax_ogo_mc'); } catch (OGO_Missing_Test_Exit $e) {}
    finally { delete_option('ogo_mc_lock'); }
    $r=json_decode(ob_get_clean(),true);
    if (!is_array($r) || !$r['success']) throw new RuntimeException('Missing AJAX failed: '.json_encode($r));
    return $r['data'];
}
function missing_scan($project='legacy_missing') {
    $r=missing_request('start','',null,$project); $run=$r['state']['run'];
    do { $r=missing_request('scan',$run,null,$project); } while (!$r['state']['complete']);
    return $run;
}
function missing_row($run,$id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ogo_media_cleanup WHERE run_id=%s AND post_id=%d",$run,$id));
}
function missing_post($title,$content) { return wp_insert_post(wp_slash(array('post_type'=>'post','post_status'=>'publish','post_title'=>$title,'post_content'=>$content))); }
$absent=999999997;
if (get_post($absent)) wp_delete_post($absent,true);
$dead='<a href="https://ogo.ua/articles/view/2010-02-12/'.$absent.'.html">Вищий пілотаж</a>';
$target=missing_post('OGO Missing target fixture','Target');
$live='<a href="https://ogo.ua/articles/view/2010-02-12/'.$target.'.html">Існує</a>';
$before='<p>Про '.$dead.' далі.</p><div align="justify"><font size="2">'.$dead.'</font></div><p>'.$live.'</p>';
$expected='<p>Про Вищий пілотаж далі.</p><div align="justify"><font size="2">Вищий пілотаж</font></div><p><a href="'.get_permalink($target).'">Існує</a></p>';
$source=missing_post('OGO Missing source fixture',$before);
$archive='<a href="https://ogo.ua/archive/2010-02-04/site/33708">Фапомед</a>';
$mixed=missing_post('OGO Missing mixed fixture','<p>'.$dead.'</p>'.$archive);
$private=missing_post('OGO Missing draft target fixture','Private');wp_update_post(array('ID'=>$private,'post_status'=>'draft'));
$protected=missing_post('OGO Missing protected fixture','<p><a href="/articles/view/2010-02-12/'.$private.'.html">Чернетка</a></p>');
$run=missing_scan(); $row=missing_row($run,$source);
missing_assert($row && $row->status==='ready' && $row->after_content===$expected && (int)$row->removed===3,'Preview combines replacement and unwrapping with intact paragraphs');
missing_assert(strpos($row->message,'Знято посилань зі збереженням тексту: 2')!==false,'Journal distinguishes action counts');
missing_assert(get_post($source)->post_content===$before,'Scan never changes the article');
missing_assert(missing_row($run,$mixed)->status==='ready' && missing_row($run,$protected)->status==='review','Archive can be unwrapped and draft targets require manual review');
missing_request('apply',$run);
missing_assert(get_post($source)->post_content===$expected,'Actual WordPress apply matches preview');
missing_assert(get_post($mixed)->post_content==='<p>Вищий пілотаж</p>Фапомед' && strpos(get_post($protected)->post_content,'<a ')!==false,'Bulk apply keeps archive label and skips draft-target materials');
missing_request('restore',$run);
missing_assert(get_post($source)->post_content===$before && get_post($mixed)->post_content==='<p>'.$dead.'</p>'.$archive,'Rollback restores original HTML byte-for-byte');
$run=missing_scan(); $row=missing_row($run,$source);
$restored=wp_insert_post(array('import_id'=>$absent,'post_type'=>'post','post_status'=>'publish','post_title'=>'Restored target fixture'));
missing_assert($restored===$absent,'Previously absent target restored after scan');
missing_request('apply',$run,array($row->id));
missing_assert(missing_row($run,$source)->status==='conflict' && get_post($source)->post_content===$before,'Restored target blocks deletion from stale preview');
wp_delete_post($absent,true);
$run=missing_scan();$row=missing_row($run,$source);
wp_update_post(wp_slash(array('ID'=>$source,'post_content'=>$before.'<p>Редакторська правка</p>')));
missing_request('apply',$run,array($row->id));
missing_assert(missing_row($run,$source)->status==='conflict' && strpos(get_post($source)->post_content,'Редакторська правка')!==false,'Concurrent source edit is not overwritten');
wp_update_post(wp_slash(array('ID'=>$source,'post_content'=>$before)));
$run=missing_scan();$row=missing_row($run,$source);missing_request('apply',$run,array($row->id));
wp_update_post(wp_slash(array('ID'=>$source,'post_content'=>$expected.'<p>Після очищення</p>')));
missing_request('restore',$run,array($row->id));
missing_assert(missing_row($run,$source)->status==='conflict' && strpos(get_post($source)->post_content,'Після очищення')!==false,'Rollback does not overwrite subsequent edits');
$_GET=array('project'=>'legacy_missing');ob_start();OGO_Media_Cleaner::page();$html=ob_get_clean();
missing_assert(strpos($html,'ОГО — відсутні статті: очищення')!==false && strpos($html,'Не використовує HTTP 404')!==false && strpos($html,'Очистити всі готові')!==false,'New operation documents absence criteria and exposes cleanup controls');
$source_links=array(
    'segodnya'=>array('<p>повідомляє "<a href="//news.segodnya.ua/"><b>Сегодня</b></a>"</p>','<p>повідомляє "<b>Сегодня</b>"</p>'),
    'tsn'=>array('<p><a href="https://ru.tsn.ua/" rel="sponsored noreferrer">ТСН</a></p>','<p><a href="https://ru.tsn.ua/" rel="nofollow noopener sponsored noreferrer" target="_blank">ТСН</a></p>'),
);
$source_ids=array();
$editor=get_user_by('login','ogo_source_editor');
$editor_id=$editor?$editor->ID:wp_insert_user(array('user_login'=>'ogo_source_editor','user_pass'=>'isolated-source-test','role'=>'editor'));
foreach ($source_links as $project=>list($original,$updated)) {
    wp_set_current_user(get_user_by('login','audit_admin')->ID);
    $id=missing_post('OGO source fixture',$original);
    $source_ids[]=$id;
    update_post_meta($id,'anons',$original);
    wp_set_current_user($editor_id);
    $run=missing_scan($project);
    $row=missing_row($run,$id);
    missing_assert($row && $row->status==='ready' && $row->after_content===$updated && get_post($id)->post_content===$original,$project.' editor gets an exact read-only preview');
    missing_request('apply',$run,array($row->id),$project);
    missing_assert(get_post($id)->post_content===$updated && get_post_meta($id,'anons',true)===$original,$project.' applies only to post_content');
    $repeat=missing_scan($project);
    missing_assert(!missing_row($repeat,$id),$project.' repeated scan has no pending changes');
    missing_request('restore',$run,array($row->id),$project);
    missing_assert(get_post($id)->post_content===$original && get_post_meta($id,'anons',true)===$original,$project.' older run restores exact HTML');
}
// Only fixtures created here are deleted; existing test rows and other suites remain intact.
foreach (array_merge(array($source,$mixed,$private,$protected,$target),$source_ids) as $id) wp_delete_post($id,true);
echo 'Passed '.$count." WordPress archive and source-link scan/apply/rollback/concurrency checks.\n";
