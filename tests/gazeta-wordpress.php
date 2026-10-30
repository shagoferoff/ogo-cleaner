<?php
$root=getenv('OGO_TEST_WP_ROOT');
if(!$root || !is_file($root.'/wp-load.php'))exit(2);
define('DOING_AJAX',true);require $root.'/wp-load.php';
if(DB_NAME!=='ogo_audit_test')throw new RuntimeException('Dedicated test database required');
add_filter('pre_wp_mail',function(){return true;});
class OGO_Gazeta_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Gazeta_Exit();};});
$count=0;
function gazeta_assert($ok,$why){global $count;if(!$ok)throw new RuntimeException($why);++$count;}
function gazeta_request($mode,$run=''){
    $_POST=array('project'=>'gazeta','mode'=>$mode,'run'=>$run,'backup'=>'1','nonce'=>wp_create_nonce('ogo_mc'));$_REQUEST=$_POST;
    ob_start();try{do_action('wp_ajax_ogo_mc');}catch(OGO_Gazeta_Exit $e){}finally{delete_option('ogo_mc_lock');}
    $r=json_decode(ob_get_clean(),true);if(empty($r['success']))throw new RuntimeException('Gazeta AJAX failed: '.json_encode($r));return $r['data'];
}
function gazeta_scan(){$r=gazeta_request('start');$run=$r['state']['run'];do{$r=gazeta_request('scan',$run);}while(!$r['state']['complete']);return $run;}
$admin=get_user_by('login','audit_admin');wp_set_current_user($admin->ID);global $wpdb;$table=$wpdb->prefix.'ogo_media_cleanup';
$credit='За матеріалами <a href="http://gazeta.rv.ua/">"Рівненської газети"</a>';
$plain='За матеріалами "Рівненської газети"';
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/gazeta.json'),true);$posts=array();
foreach($fixtures as $f) {
    $before='<p>Початок.</p><p>'.$f['html'].'</p><p>'.$credit.'</p><p>Кінець.</p>';
    $expected=$f['action']==='delete'?'':preg_replace_callback('~<a\b[^>]*>[\s\S]*?</a>~i',function($m){return OGO_MC_Engine::target($m[0],'gazeta')?preg_replace('~</?a\b[^>]*>~i','',$m[0]):$m[0];},$f['html']);
    $after='<p>Початок.</p>'.($expected!==''?'<p>'.$expected.'</p>':'').'<p>'.$plain.'</p><p>Кінець.</p>';
    $id=wp_insert_post(wp_slash(array('post_author'=>$admin->ID,'post_status'=>'publish','post_title'=>'Gazeta fixture','post_content'=>$before)));
    update_post_meta($id,'anons',$credit);$posts[$id]=array($before,$after);
}
$mixed_before='<p>'.$credit.'</p><p><a href="http://gazeta.rv.ua/"><img src="x.jpg"></a></p>';
$mixed=wp_insert_post(wp_slash(array('post_status'=>'publish','post_title'=>'Mixed gazeta','post_content'=>$mixed_before)));
$contacts='<p>Пишіть мені листи за адресою:</p><p>Надсилайте електронні листи на gazeta.rv.ua@gmail.com</p><p>Основний текст.</p>';
$contact=wp_insert_post(wp_slash(array('post_status'=>'publish','post_title'=>'Contact gazeta','post_content'=>$contacts)));
// Migrate historical completed work from 1.29.0 without losing rollback snapshots.
$historic=wp_insert_post(wp_slash(array('post_status'=>'publish','post_title'=>'Historic gazeta','post_content'=>$plain)));
$history_run=wp_generate_uuid4();$p=get_post($historic);
$wpdb->insert($table,array('run_id'=>$history_run,'project'=>'gazeta_sources','post_id'=>$historic,'before_content'=>$credit,'after_content'=>$plain,'modified_gmt'=>$p->post_modified_gmt,'post_status'=>'publish','status'=>'done','removed'=>1,'review'=>0,'changed_gmt'=>$p->post_modified_gmt,'message'=>'Historical caption'));
update_option('ogo_mc_schema','1.3.0');OGO_Media_Cleaner::activate();
gazeta_assert(get_option('ogo_mc_schema')==='1.3.1','Schema migration completed');
$history=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s",$history_run));
gazeta_assert($history && $history->project==='gazeta' && $history->before_content===$credit && $history->after_content===$plain && $history->status==='done','Old run snapshots and completion status preserved');
$editor=get_user_by('login','ogo_gazeta_editor');$editor_id=$editor?$editor->ID:wp_insert_user(array('user_login'=>'ogo_gazeta_editor','user_pass'=>'isolated-gazeta-test','role'=>'editor'));wp_set_current_user($editor_id);
gazeta_request('restore',$history_run);gazeta_assert(get_post($historic)->post_content===$credit,'Editor can restore migrated historical run');wp_delete_post($historic,true);
$run=gazeta_scan();
foreach($posts as $id=>$pair){$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id=%d",$run,$id));gazeta_assert($row && $row->status==='ready' && $row->after_content===$pair[1] && (int)$row->removed===2,'All supplied fixtures are ready with exact preview');gazeta_assert(get_post($id)->post_content===$pair[0],'Scan is read-only');}
$review=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id=%d",$run,$mixed));gazeta_assert($review && $review->status==='review' && (int)$review->review===1,'Mixed malformed/media material never bulk applies');
function gazeta_finish($mode,$run){do{$r=gazeta_request($mode,$run);}while($r['remaining']);}
gazeta_finish('apply',$run);
foreach($posts as $id=>$pair){gazeta_assert(get_post($id)->post_content===$pair[1],'Editor applies reviewed transformation');gazeta_assert(get_post_meta($id,'anons',true)===$credit,'anons untouched');}
gazeta_assert(get_post($mixed)->post_content===$mixed_before,'Review material untouched');gazeta_assert(get_post($contact)->post_content==='<p>Основний текст.</p>','Original contacts still cleaned');
$repeat=gazeta_scan();gazeta_assert(!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE run_id=%s AND status='ready'",$repeat)),'Repeat scan has no ready changes');
gazeta_finish('restore',$run);foreach($posts as $id=>$pair)gazeta_assert(get_post($id)->post_content===$pair[0],'Rollback restores exact content');gazeta_assert(get_post($contact)->post_content===$contacts,'Contacts rollback exact');
$run=gazeta_scan();$id=array_key_first($posts);$edited=$posts[$id][0].'<p>Редакторська зміна</p>';wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>$edited)));gazeta_finish('apply',$run);gazeta_assert(get_post($id)->post_content===$edited,'Concurrent edits preserved');
$_GET=array('project'=>'gazeta');ob_start();OGO_Media_Cleaner::page();$html=ob_get_clean();gazeta_assert(strpos($html,'value="gazeta"')!==false && strpos($html,'value="gazeta_sources"')===false,'Only existing gazeta operation appears in selector');
$_GET=array('project'=>'gazeta_sources','run'=>$history_run);ob_start();OGO_Media_Cleaner::page();$html=ob_get_clean();gazeta_assert(strpos($html,'<h2>Рівненська газета</h2>')!==false,'Old bookmarked report resolves to unified gazeta history');
foreach(array_merge(array_keys($posts),array($mixed,$contact)) as $id)wp_delete_post($id,true);
echo 'Passed '.$count." WordPress gazeta editor/fixtures/migration/preview/apply/rollback/repeat/conflict checks.\n";
