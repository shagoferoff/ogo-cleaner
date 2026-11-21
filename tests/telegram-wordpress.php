<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) exit(2);
define('DOING_AJAX',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
wp_set_current_user(get_user_by('login','audit_admin')->ID);

class OGO_Telegram_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Telegram_Test_Exit();};});
function telegram_request($mode,$run='',$selection=array()) {
    $_POST=array('mode'=>$mode,'run'=>$run,'project'=>'telegram_urls','backup'=>'1','nonce'=>wp_create_nonce('ogo_mc'));
    if ($selection) { $_POST['selected']='1'; $_POST['ids']=$selection; }
    $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc'); } catch (OGO_Telegram_Test_Exit $e) {}
    finally { delete_option('ogo_mc_lock'); }
    $response=json_decode(ob_get_clean(),true);
    if (empty($response['success'])) throw new RuntimeException('Telegram request failed: '.json_encode($response));
    return $response['data']['state'];
}
function telegram_test_post($title,$html,$status='publish') {
    return wp_insert_post(wp_slash(array('post_title'=>$title,'post_status'=>$status,'post_content'=>$html)));
}
function telegram_test_check($ok,$message) {
    if (!$ok) throw new RuntimeException($message);
}
$link='<a href="https://t.me/test_ogo/12">Новина</a>';
$closed='<a href="https://t.me/test_ogo/12" target="_blank" rel="nofollow noopener">Новина</a>';
$embed='<iframe src="https://t.me/test_ogo/12?embed=1"></iframe>';
$mixed=telegram_test_post('Telegram mixed fixture',$link.' '.$embed);
$widget=telegram_test_post('Telegram embedded fixture',$embed);
$plain=telegram_test_post('Telegram plain fixture',$link);
$draft=telegram_test_post('Telegram draft fixture',$link,'draft');
try {
    $state=telegram_request('start'); $run=$state['run'];
    do { $state=telegram_request('scan',$run); } while (!$state['complete']);
    global $wpdb;
    $table=$wpdb->prefix.'ogo_media_cleanup';
    $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id IN (%d,%d,%d,%d)",$run,$mixed,$widget,$plain,$draft),OBJECT_K);
    $by_post=array_column(array_values($rows),null,'post_id');
    telegram_test_check(count($by_post)===3 && !isset($by_post[$draft]),'Scope is published articles');
    telegram_test_check($by_post[$mixed]->status==='ready' && (int)$by_post[$mixed]->review===1 && $by_post[$mixed]->after_content===$closed.' '.$embed,'A mixed article can update its plain link and still mark the embed');
    telegram_test_check($by_post[$widget]->status==='review' && $by_post[$widget]->after_content===$embed,'Standalone embed is manual');
    $_GET=array('project'=>'telegram_urls','run'=>$run,'review'=>'1');
    ob_start(); OGO_Media_Cleaner::page(); $html=ob_get_clean();
    telegram_test_check(strpos($html,'Telegram mixed fixture')!==false && strpos($html,'Telegram embedded fixture')!==false
        && strpos($html,'Telegram plain fixture')===false,'Manual filter shows standalone and mixed embeds');
    telegram_request('apply',$run,array($by_post[$mixed]->id));
    telegram_test_check(get_post($mixed)->post_content===$closed.' '.$embed && get_post($widget)->post_content===$embed,'Apply preserves embedded HTML exactly');
    telegram_request('restore',$run,array($by_post[$mixed]->id));
    telegram_test_check(get_post($mixed)->post_content===$link.' '.$embed,'Rollback restores original HTML');
} finally {
    foreach (array($mixed,$widget,$plain,$draft) as $id) wp_delete_post($id,true);
}
echo "Passed WordPress Telegram scope, mixed review filter, apply and rollback checks.\n";
