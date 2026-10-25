<?php
/** Opt-in application/rollback test on the dedicated local WordPress test database. */
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) { fwrite(STDERR,"Set OGO_TEST_WP_ROOT to an isolated test installation.\n"); exit(2); }
define('DOING_AJAX',true); require $root.'/wp-load.php';
if (!defined('DB_NAME') || DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
$user=get_user_by('login','audit_admin'); wp_set_current_user($user->ID);
add_filter('pre_wp_mail',function(){return true;});
class OGO_Editorial_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Editorial_Test_Exit();};});
$count=0;
function editorial_integration_check($ok,$message) { global $count; if (!$ok) throw new RuntimeException($message); ++$count; }
function editorial_request($mode,$run='') {
    $_POST=array('mode'=>$mode,'run'=>$run,'project'=>'editorial_urls','backup'=>'1','nonce'=>wp_create_nonce('ogo_mc')); $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc'); } catch (OGO_Editorial_Test_Exit $e) {}
    finally { delete_option('ogo_mc_lock'); } // Emulate end-of-request shutdown in this CLI process.
    $r=json_decode(ob_get_clean(),true);
    if (!is_array($r) || !$r['success']) throw new RuntimeException('Cleanup AJAX failed: '.json_encode($r));
    return $r['data'];
}
$before='<p><a href="/rubrics/view/top/">Активний Топ</a> <a href="/rubrics/view/policy">Політика</a> <a href="https://ogo.ua/rubrics/view/top-publicservant/page:2#page-top">Рейтинг</a></p>';
$expected='<p><a href="/rubrics/view/top/">Активний Топ</a> <a href="https://ogo.ua/tag/polityka/">Політика</a> <a href="https://ogo.ua/tag/rejtyngy/">Рейтинг</a></p>';
$id=wp_insert_post(wp_slash(array('post_title'=>'OGO editorial good fixture','post_status'=>'publish','post_content'=>$before)));
$broken='<a href="/rubrics/view/defense">Добре</a> <a href="/rubrics/view/volyn">Незакрите';
$review_id=wp_insert_post(wp_slash(array('post_title'=>'OGO editorial review fixture','post_status'=>'publish','post_content'=>$broken)));
$r=editorial_request('start'); $run=$r['state']['run'];
do { $r=editorial_request('scan',$run); } while (!$r['state']['complete']);
global $wpdb; $table=$wpdb->prefix.'ogo_media_cleanup';
$good=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id=%d",$run,$id));
$manual=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id=%d",$run,$review_id));
editorial_integration_check($good && $good->status==='ready' && $good->after_content===$expected && (int)$good->removed===2,'New operation scans exact targets, preserves active top and stores the correct preview');
editorial_integration_check($manual && $manual->status==='review' && (int)$manual->review===1,'Mixed safe/unsafe material is held for manual review');
editorial_integration_check(get_post($id)->post_content===$before,'Scan does not change source content');
editorial_request('apply',$run);
editorial_integration_check(get_post($id)->post_content===$expected,'Apply writes approved href changes only');
editorial_integration_check(get_post($review_id)->post_content===$broken,'Bulk apply skips manual-review material');
$state=$wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE id=%d",$good->id));
editorial_integration_check($state==='done','Applied result recorded in cleanup journal');
editorial_request('restore',$run);
editorial_integration_check(get_post($id)->post_content===$before,'Rollback restores original content exactly');
$r=editorial_request('start'); $next=$r['state']['run'];
do { $r=editorial_request('scan',$next); } while (!$r['state']['complete']);
$edited=$before.'<p>Редакторська правка після сканування.</p>';
wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>$edited)));
editorial_request('apply',$next);
editorial_integration_check(get_post($id)->post_content===$edited,'Concurrent editorial changes are not overwritten');
$state=$wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE run_id=%s AND post_id=%d",$next,$id));
editorial_integration_check($state==='conflict','Concurrent edit recorded as conflict');
$_GET=array('project'=>'editorial_urls'); ob_start(); OGO_Media_Cleaner::page(); $html=ob_get_clean();
editorial_integration_check(strpos($html,'Замінити всі готові URL')!==false && strpos($html,'Погоджені рубрики')!==false,'New operation renders URL-replacement controls');
echo 'Passed '.$count." WordPress scan/apply/rollback/concurrent-edit checks for editorial mappings.\n";
