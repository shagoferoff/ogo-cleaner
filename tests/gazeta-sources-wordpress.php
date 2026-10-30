<?php
$root=getenv('OGO_TEST_WP_ROOT');
if(!$root || !is_file($root.'/wp-load.php'))exit(2);
define('DOING_AJAX',true);require $root.'/wp-load.php';
if(DB_NAME!=='ogo_audit_test')throw new RuntimeException('Dedicated test database required');
add_filter('pre_wp_mail',function(){return true;});
class OGO_Source_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Source_Exit();};});
$count=0;
function source_assert($ok,$why){global $count;if(!$ok)throw new RuntimeException($why);++$count;}
function source_request($mode,$run=''){
    $_POST=array('project'=>'gazeta_sources','mode'=>$mode,'run'=>$run,'backup'=>'1','nonce'=>wp_create_nonce('ogo_mc'));$_REQUEST=$_POST;
    ob_start();try{do_action('wp_ajax_ogo_mc');}catch(OGO_Source_Exit $e){}finally{delete_option('ogo_mc_lock');}
    $r=json_decode(ob_get_clean(),true);if(empty($r['success']))throw new RuntimeException('Source AJAX failed: '.json_encode($r));return $r['data'];
}
function source_scan(){$r=source_request('start');$run=$r['state']['run'];do{$r=source_request('scan',$run);}while(!$r['state']['complete']);return $run;}
$admin=get_user_by('login','audit_admin');wp_set_current_user($admin->ID);
$a='За матеріалами <a href="http://gazeta.rv.ua/">"Рівненської газети"</a>';
$b='За матеріалами <a href="http://gazeta.rv.ua/articles/view/2011-06-16/17243.html">"Рівненської газети"</a>';
$plain='За матеріалами "Рівненської газети"';
$other='<a href="http://gazeta.rv.ua/article">Інша стаття</a>';
$before='<p>'.$a.'</p><p>'.$b.'</p><p>'.$other.'</p>';$after='<p>'.$plain.'</p><p>'.$plain.'</p><p>'.$other.'</p>';
$id=wp_insert_post(wp_slash(array('post_author'=>$admin->ID,'post_type'=>'post','post_status'=>'publish','post_title'=>'Gazeta source fixture','post_content'=>$before)));
update_post_meta($id,'anons',$a);
$mixed_before='<p>'.$a.'</p><p>За матеріалами <a href="http://gazeta.rv.ua/">Невідома назва</a></p>';
$mixed=wp_insert_post(wp_slash(array('post_status'=>'publish','post_title'=>'Gazeta mixed fixture','post_content'=>$mixed_before)));
$contact_before='<p>Пишіть мені листи за адресою:</p><p>Надсилайте електронні листи на gazeta.rv.ua@gmail.com</p>';
$contact=wp_insert_post(wp_slash(array('post_status'=>'publish','post_title'=>'Gazeta contacts fixture','post_content'=>$contact_before)));
$editor=get_user_by('login','ogo_source_editor');if(!$editor){$editor_id=wp_insert_user(array('user_login'=>'ogo_source_editor','user_pass'=>'isolated-source-test','role'=>'editor'));$editor=get_user_by('id',$editor_id);}wp_set_current_user($editor->ID);
$run=source_scan();global $wpdb;$table=$wpdb->prefix.'ogo_media_cleanup';
$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id=%d",$run,$id));
source_assert($row && $row->status==='ready' && $row->after_content===$after && (int)$row->removed===2,'Both supplied URL formats become the same credit, preserving other links');
source_assert(get_post($id)->post_content===$before,'Scanning is read-only');
$review=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id=%d",$run,$mixed));
source_assert($review && $review->status==='review' && (int)$review->review===1,'Mixed recognized/ambiguous material is not ready for bulk apply');
source_assert(!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE run_id=%s AND post_id=%d",$run,$contact)),'Old contact-cleanup rules are outside this operation');
source_request('apply',$run);
source_assert(get_post($id)->post_content===$after,'Editor applies the exact reviewed credit-only transformation');
source_assert(get_post($mixed)->post_content===$mixed_before && get_post($contact)->post_content===$contact_before,'Review and unrelated contact materials remain unchanged');
source_assert(get_post_meta($id,'anons',true)===$a,'anons remains untouched');
source_request('restore',$run);source_assert(get_post($id)->post_content===$before,'Rollback restores both source links byte-for-byte');
$run=source_scan();source_request('apply',$run);$repeat=source_scan();
source_assert(!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE run_id=%s AND post_id=%d",$repeat,$id)),'A repeated scan has no remaining recognized caption links');
source_request('restore',$run);
$run=source_scan();$edited=$before.'<p>Редакторська зміна</p>';wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>$edited)));source_request('apply',$run);
source_assert(get_post($id)->post_content===$edited,'Concurrent editorial change is never overwritten');
$_GET=array('project'=>'gazeta_sources');ob_start();OGO_Media_Cleaner::page();$html=ob_get_clean();
source_assert(strpos($html,'Рівненська газета — підписи джерела')!==false && strpos($html,'Зняти всі готові посилання')!==false,'Dedicated UI uses unlink labels and explains exact scope');
foreach(array($id,$mixed,$contact) as $fixture)wp_delete_post($fixture,true);
echo 'Passed '.$count." WordPress gazeta-credit editor/preview/apply/rollback/repeat/conflict checks.\n";
