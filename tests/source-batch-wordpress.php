<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) exit(2);
define('DOING_AJAX',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
wp_set_current_user(get_user_by('login','audit_admin')->ID);

class OGO_Batch_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Batch_Test_Exit();};});
function batch_ajax($mode,$run='',$selection=array()) {
    global $batch_project;
    $_POST=array('mode'=>$mode,'run'=>$run,'project'=>$batch_project,'backup'=>'1','nonce'=>wp_create_nonce('ogo_mc'));
    if ($selection) { $_POST['selected']='1'; $_POST['ids']=$selection; }
    $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc'); } catch (OGO_Batch_Test_Exit $e) {}
    finally { delete_option('ogo_mc_lock'); }
    $reply=json_decode(ob_get_clean(),true);
    if (empty($reply['success'])) throw new RuntimeException('Batch AJAX failed: '.json_encode($reply));
    return $reply['data'];
}
function batch_fixture($html,$status='publish',$type='post') {
    return wp_insert_post(wp_slash(array('post_title'=>'Source batch QA','post_status'=>$status,'post_type'=>$type,'post_content'=>$html)));
}
function batch_assert($ok,$message) {
    if (!$ok) throw new RuntimeException($message);
}
$batch_project=getenv('OGO_TEST_SOURCE_BATCH')==='2'?'source_batch_2':'source_batch_1';
$host=$batch_project==='source_batch_2'?'suspilne.media':'pravda.com.ua';
$source='<p><a href="https://'.$host.'/test" rel="noreferrer">Джерело</a> <a href="https://t.me/channel/1">Telegram</a> <a href="https://facebook.com/video/2">Facebook</a></p>';
$expected=str_replace('rel="noreferrer"','rel="nofollow noopener noreferrer" target="_blank"',$source);
$paid=$source.'<p>На правах реклами.</p>';
$article=batch_fixture($source);
$draft=batch_fixture($source,'draft');
$page=batch_fixture($source,'publish','page');
$advert=batch_fixture($paid);
update_post_meta($article,'anons',$source);
try {
    $state=batch_ajax('start')['state']; $run=$state['run'];
    do { $state=batch_ajax('scan',$run)['state']; } while (!$state['complete']);
    global $wpdb;
    $table=$wpdb->prefix.'ogo_media_cleanup';
    $row=$wpdb->get_row($wpdb->prepare("SELECT id,status,after_content FROM $table WHERE run_id=%s AND post_id=%d",$run,$article));
    batch_assert($row && $row->status==='ready' && $row->after_content===$expected && get_post($article)->post_content===$source,'Scan offers a read-only preview of approved links');
    foreach (array($draft,$page,$advert) as $id) {
        batch_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE run_id=%s AND post_id=%d",$run,$id)),'Excluded content has no pending edit');
    }
    batch_ajax('apply',$run,array($row->id));
    batch_assert(get_post($article)->post_content===$expected && get_post_meta($article,'anons',true)===$source,'Apply preserves the rest of the content');
    batch_ajax('restore',$run,array($row->id));
    batch_assert(get_post($article)->post_content===$source,'Rollback restores the source HTML');
} finally {
    foreach (array($article,$draft,$page,$advert) as $id) wp_delete_post($id,true);
}
echo "Passed WordPress $batch_project preview, published scope, advertising exclusion, apply and rollback checks.\n";
