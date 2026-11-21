<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) exit(2);
define('DOING_AJAX',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
$editor=get_user_by('login','ogo_source_editor');
$editor_id=$editor?$editor->ID:wp_insert_user(array('user_login'=>'ogo_source_editor','user_pass'=>wp_generate_password(),'role'=>'editor'));
wp_set_current_user($editor_id);

class OGO_Coverage_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){ return function(){ throw new OGO_Coverage_Exit(); }; });
function coverage_ajax($mode,$run) {
    $_POST=array('mode'=>$mode,'run'=>$run,'nonce'=>wp_create_nonce('ogo_mc_404'));
    $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc_404'); } catch (OGO_Coverage_Exit $e) {}
    return json_decode(ob_get_clean(),true);
}
function coverage_page($run,$group='') {
    $_GET=array('project'=>'coverage_404','run'=>$run,'group'=>$group);
    ob_start(); OGO_Media_Cleaner::page(); return ob_get_clean();
}
function coverage_check($ok,$reason) {
    if (!$ok) throw new RuntimeException($reason);
}

$run=wp_generate_uuid4();
$path='/coverage-fixture-'.$run.'/?source=archive';
$markup='<p><a href="'.$path.'">Coverage source</a></p>';
$published=wp_insert_post(wp_slash(array('post_title'=>'Coverage QA publish','post_status'=>'publish','post_content'=>$markup)));
$draft=wp_insert_post(wp_slash(array('post_title'=>'Coverage QA draft','post_status'=>'draft','post_content'=>$markup)));
$stream=fopen('php://memory','w+b');
fputcsv($stream,array('URL','Last crawl'),',','"','');
fputcsv($stream,array('https://ogo.ua'.$path,'2026-11-17'),',','"','');
fputcsv($stream,array('https://ogo.ua/coverage-unlinked-'.$run.'/','2026-11-16'),',','"','');
rewind($stream); $targets=OGO_MC_Coverage_Engine::read_csv($stream); fclose($stream);
$state=array('run'=>$run,'cursor'=>$published-1,'max'=>$draft,'scanned'=>0,'complete'=>false,'version'=>OGO_Media_Cleaner::VERSION,'started'=>current_time('mysql'));
$old_state=OGO_MC_Audit::state('',true);
$legacy_state=OGO_MC_Audit::state();
update_option('ogo_mc_404_scan',$state,false);
add_option('ogo_mc_404_urls_'.$run,$targets,'',false);
try {
    $invalid=coverage_ajax('start','');
    coverage_check(!$invalid['success'] && OGO_MC_Audit::state('',true)===$state,'Failed upload leaves the current run alone');
    $reply=coverage_ajax('scan',$run);
    coverage_check($reply['success'] && $reply['data']['state']['complete'] && (int)$reply['data']['stats']['totals']['links']===1,'Only the published source is counted');
    coverage_check(coverage_ajax('scan',$run)['data']['stats']===$reply['data']['stats'],'Repeated scan does not duplicate links');
    $report=coverage_page($run);
    $details=coverage_page($run,$targets[0]['key']);
    coverage_check(strpos($report,'2026-11-17')!==false && strpos($report,'У матеріалах не знайдено')!==false,'Summary includes date and unlinked URLs');
    coverage_check(strpos($details,'Coverage QA publish')!==false && strpos($details,'action=edit')!==false && strpos($details,'Coverage QA draft')===false,'The editor can open the linked source');
    coverage_check(get_post($published)->post_content===$markup && OGO_MC_Audit::state()===$legacy_state,'Content and the old audit state are preserved');
    coverage_check(!coverage_ajax('apply',$run)['success'],'Coverage endpoint is read-only');
} finally {
    global $wpdb;
    $wpdb->delete($wpdb->prefix.'ogo_link_audit',array('run_id'=>$run));
    delete_option('ogo_mc_404_urls_'.$run);
    delete_option('ogo_mc_404_run_'.$run);
    if ($old_state) update_option('ogo_mc_404_scan',$old_state,false); else delete_option('ogo_mc_404_scan');
    wp_delete_post($published,true);
    wp_delete_post($draft,true);
}
echo "Passed WordPress 404 scope, source links, isolation and repeat-run checks.\n";
