<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) { fwrite(STDERR,"Set OGO_TEST_WP_ROOT to a dedicated WordPress test installation.\n"); exit(2); }
define('DOING_AJAX',true); require $root.'/wp-load.php';
if (!defined('DB_NAME') || DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
$user=get_user_by('login','audit_admin'); wp_set_current_user($user->ID);
add_filter('pre_wp_mail',function(){return true;});
update_option('permalink_structure','/%postname%/'); global $wp_rewrite; $wp_rewrite->init();
class OGO_Legacy_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Legacy_Test_Exit();};});
$count=0;
function legacy_integration_check($ok,$message) { global $count; if (!$ok) throw new RuntimeException($message); ++$count; }
function legacy_request($mode,$run='') {
    $_POST=array('mode'=>$mode,'run'=>$run,'project'=>'legacy_urls','backup'=>'1','nonce'=>wp_create_nonce('ogo_mc')); $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc'); } catch (OGO_Legacy_Test_Exit $e) {}
    finally { delete_option('ogo_mc_lock'); }
    $r=json_decode(ob_get_clean(),true);
    if (!is_array($r) || !$r['success']) throw new RuntimeException('Legacy AJAX failed: '.json_encode($r));
    return $r['data'];
}
function legacy_scan() { $r=legacy_request('start'); $run=$r['state']['run']; do { $r=legacy_request('scan',$run); } while (!$r['state']['complete']); return $run; }
$slug='paska-kovbaska-y-zoloti-yaytsya-skilky-koshtuvatyme-velykodniy-koshyk-2023';
$target=get_post(131461);
if (!$target) $target_id=wp_insert_post(array('import_id'=>131461,'post_type'=>'post','post_status'=>'publish','post_title'=>'OGO Legacy target fixture','post_name'=>$slug));
else { $target_id=131461; wp_update_post(array('ID'=>$target_id,'post_status'=>'publish','post_name'=>$slug)); }
legacy_integration_check($target_id===131461 && get_permalink($target_id)==='https://ogo.ua/'.$slug.'/','WordPress returns the authoritative target URL for ID 131461');
$canonical=get_permalink($target_id);
$before='<p><a href="https://mail.ogo.ua/articles/view/2010-02-30/131461.htm">Перша</a> <a href="https://ogo.ua/articles/2011-11-08/131461">Друга</a> <a href="https://ogo.ua/articles/view/2015-09-16/131461.htmlhttps://ogo.ua/articles/view/2015-09-16/131461.html#page-top">Третя</a></p>';
$expected='<p><a href="'.$canonical.'">Перша</a> <a href="'.$canonical.'">Друга</a> <a href="'.$canonical.'">Третя</a></p>';
$source=wp_insert_post(wp_slash(array('post_type'=>'post','post_status'=>'publish','post_title'=>'OGO Legacy source fixture','post_content'=>$before)));
$missing_html='<a href="https://ogo.ua/articles/view/2016-05-16/999999998.html">Відсутня</a>';
$missing=wp_insert_post(wp_slash(array('post_status'=>'publish','post_title'=>'OGO Legacy missing fixture','post_content'=>$missing_html)));
$mixed_html='<a href="https://ogo.ua/articles/view/2016-05-16/131461.htm">Є</a> '.$missing_html;
$mixed=wp_insert_post(wp_slash(array('post_status'=>'publish','post_title'=>'OGO Legacy mixed fixture','post_content'=>$mixed_html)));
$ambiguous_html='<a href="https://ogo.ua/articles/view/2016-05-16/131461.htmlhttps://ogo.ua/articles/view/2016-05-16/131462.html">Різні ID</a>';
$ambiguous=wp_insert_post(wp_slash(array('post_status'=>'publish','post_title'=>'OGO Legacy ambiguous fixture','post_content'=>$ambiguous_html)));
$run=legacy_scan();global $wpdb;$table=$wpdb->prefix.'ogo_media_cleanup';
$good=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id=%d",$run,$source));
legacy_integration_check($good && $good->status==='ready' && $good->after_content===$expected && (int)$good->removed===3,'Malformed but unambiguous URLs receive correct local permalink previews');
foreach (array($missing,$mixed,$ambiguous) as $id) {
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id=%d",$run,$id));
    legacy_integration_check($row && $row->status==='review','Missing, mixed and ambiguous cases remain manual');
}
legacy_integration_check(get_post($source)->post_content===$before,'Scanning is read-only');
legacy_request('apply',$run);
legacy_integration_check(get_post($source)->post_content===$expected,'Apply changes href values while preserving text and layout');
legacy_integration_check(get_post($missing)->post_content===$missing_html && get_post($mixed)->post_content===$mixed_html && get_post($ambiguous)->post_content===$ambiguous_html,'Bulk apply never deletes or changes unresolved materials');
legacy_request('restore',$run);
legacy_integration_check(get_post($source)->post_content===$before,'Rollback restores even malformed originals byte-for-byte');
$run=legacy_scan(); wp_update_post(array('ID'=>$target_id,'post_name'=>'ogo-test-target-renamed'));
legacy_request('apply',$run);
$status=$wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE run_id=%s AND post_id=%d",$run,$source));
legacy_integration_check($status==='conflict' && get_post($source)->post_content===$before,'Permalink changes after preview block application');
wp_update_post(array('ID'=>$target_id,'post_name'=>$slug,'post_status'=>'publish'));
$run=legacy_scan(); wp_update_post(array('ID'=>$target_id,'post_status'=>'draft'));
legacy_request('apply',$run);
$status=$wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE run_id=%s AND post_id=%d",$run,$source));
legacy_integration_check($status==='conflict' && get_post($source)->post_content===$before,'Unpublished target after preview blocks application');
wp_update_post(array('ID'=>$target_id,'post_status'=>'publish'));
$_GET=array('project'=>'legacy_urls'); ob_start();OGO_Media_Cleaner::page();$html=ob_get_clean();
legacy_integration_check(strpos($html,'mail.ogo.ua')!==false && strpos($html,'Замінити всі готові URL')!==false,'Legacy UI explains expanded formats and offers URL replacement');
echo 'Passed '.$count." WordPress legacy-ID scan/apply/rollback/target-revalidation checks.\n";
