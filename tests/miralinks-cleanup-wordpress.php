<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) exit(2);
define('DOING_AJAX',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
wp_set_current_user(get_user_by('login','audit_admin')->ID);

class OGO_Miralinks_Cleanup_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Miralinks_Cleanup_Exit();};});
function miralinks_cleanup_request($mode,$run='',$audit='') {
    $_POST=array('mode'=>$mode,'run'=>$run,'project'=>'miralinks_unwrap','audit_run'=>$audit,'backup'=>'1','nonce'=>wp_create_nonce('ogo_mc'));
    $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc'); } catch (OGO_Miralinks_Cleanup_Exit $e) {}
    finally { delete_option('ogo_mc_lock'); }
    return json_decode(ob_get_clean(),true);
}
function miralinks_cleanup_check($condition,$message) {
    if (!$condition) throw new RuntimeException($message);
}
$target='<a href="https://example.invalid/old-promo"><strong>Послуги</strong></a>';
$other='<a href="https://example.invalid/editorial">Джерело</a>';
$changed='<a href="https://example.invalid/new-promo">Новий URL</a>';
$body='<p>'.$target.' '.$other.'</p>';
$expected='<p><strong>Послуги</strong> '.$other.'</p>';
$id=wp_insert_post(wp_slash(array('post_title'=>'Audited Miralinks fixture','post_status'=>'publish','post_content'=>$body)));
$unlisted=wp_insert_post(wp_slash(array('post_title'=>'Unaudited Miralinks fixture','post_status'=>'publish','post_content'=>$body)));
$unmatched=wp_insert_post(wp_slash(array('post_title'=>'Edited Miralinks fixture','post_status'=>'publish','post_content'=>$changed)));
$audit=wp_generate_uuid4();
$audit_state=array('run'=>$audit,'manifest'=>OGO_MC_External_List::digest(),'complete'=>false,'scanned'=>2,'total'=>2,'started'=>current_time('mysql'));
update_option('ogo_mc_external_run_'.$audit,$audit_state,false);
global $wpdb;
$audit_table=$wpdb->prefix.'ogo_external_audit';
$journal=$wpdb->prefix.'ogo_media_cleanup';
foreach (array($id,$unmatched) as $post_id) {
    $row=OGO_MC_External_Engine::extract($target)[0];
    $wpdb->insert($audit_table,$row+array('run_id'=>$audit,'post_id'=>$post_id,'post_title'=>'Audited fixture','post_status'=>'publish','list_group'=>'monthly','post_url'=>'https://ogo.ua/fixture/'));
}
try {
    miralinks_cleanup_check(!miralinks_cleanup_request('start','',$audit)['success'],'Partial audit cannot prepare cleanup');
    $audit_state['complete']=true;
    update_option('ogo_mc_external_run_'.$audit,$audit_state,false);
    $_GET=array('project'=>'external_audit','run'=>$audit);
    ob_start(); OGO_Media_Cleaner::page(); $html=ob_get_clean();
    miralinks_cleanup_check(strpos($html,'Підготувати очищення за цією ревізією')!==false && strpos($html,'audit_run='.$audit)!==false,'Existing audit displays cleanup link');
    $response=miralinks_cleanup_request('start','',$audit);
    miralinks_cleanup_check(!empty($response['success']),'Can prepare for an existing complete audit');
    $run=$response['data']['state']['run'];
    miralinks_cleanup_check(!miralinks_cleanup_request('scan','stale-preview')['success'],'Stale AJAX request cannot scan a different cleanup run');
    do {
        $response=miralinks_cleanup_request('scan',$run);
        miralinks_cleanup_check(!empty($response['success']),'Preview batch succeeds');
    } while (empty($response['data']['state']['complete']));
    $rows=$wpdb->get_results($wpdb->prepare("SELECT post_id,after_content,status FROM $journal WHERE run_id=%s",$run));
    miralinks_cleanup_check(count($rows)===1 && (int)$rows[0]->post_id===(int)$id && $rows[0]->after_content===$expected && $rows[0]->status==='ready','Only exact audited post and link appear in preview');
    miralinks_cleanup_check(get_post($id)->post_content===$body,'Preview preserves current content');
    miralinks_cleanup_check(!empty(miralinks_cleanup_request('apply',$run)['success']) && get_post($id)->post_content===$expected,'Apply unwraps only the audited link');
    miralinks_cleanup_check(get_post($unlisted)->post_content===$body && get_post($unmatched)->post_content===$changed,'Other posts and changed URLs remain intact');
    miralinks_cleanup_check(!empty(miralinks_cleanup_request('restore',$run)['success']) && get_post($id)->post_content===$body,'Rollback restores original HTML');
} finally {
    delete_option('ogo_mc_external_run_'.$audit);
    $wpdb->delete($audit_table,array('run_id'=>$audit));
    foreach (array($id,$unlisted,$unmatched) as $post_id) wp_delete_post($post_id,true);
}
echo "Passed WordPress Miralinks audit reuse, preview scope, apply and rollback checks.\n";
