<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) exit(2);
define('DOING_AJAX',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
wp_set_current_user(get_user_by('login','audit_admin')->ID);
OGO_MC_Site_Links::activate();

class OGO_Site_Links_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Site_Links_Exit();};});
function site_links_request($mode,$run='') {
    $_POST=array('mode'=>$mode,'run'=>$run,'nonce'=>wp_create_nonce('ogo_mc_site_links'));
    $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc_site_links'); } catch (OGO_Site_Links_Exit $e) {}
    return json_decode(ob_get_clean(),true);
}
function inventory_check($condition,$message) {
    if (!$condition) throw new RuntimeException($message);
}
function inventory_post($status,$html) {
    return wp_insert_post(wp_slash(array('post_status'=>$status,'post_title'=>'Inventory '.$status,'post_content'=>$html)));
}
$domain='inventory-'.wp_generate_uuid4().'.invalid';
$url='https://'.$domain.'/story';
$open='<a href="'.$url.'" target="_blank" rel="noopener">Відкрите</a>';
$closed='<a href="'.$url.'" rel="nofollow">Закрите</a>';
$paid=$closed.'<p><strong>На правах реклами.</strong></p>';
$first=inventory_post('publish',$open.$open.$closed);
$second=inventory_post('publish',$paid);
$draft=inventory_post('draft',$open);
$registered=inventory_post('publish',$open);
$field=inventory_post('publish',$open);
update_post_meta($field,'paid_article','1');
$previous_paid=get_option('ogo_mc_paid_articles',array());
update_option('ogo_mc_paid_articles',$previous_paid+array('/inventory-fixture'=>$registered),false);
update_post_meta($first,'anons','<a href="https://ignore-anons.invalid/">Анонс</a>');
$previous=OGO_MC_External::state();
$admin=get_current_user_id();
$editor=get_user_by('login','ogo_source_editor');
$editor_id=$editor?$editor->ID:wp_insert_user(array('user_login'=>'ogo_source_editor','user_pass'=>wp_generate_password(),'role'=>'editor'));
wp_set_current_user($editor_id);
try {
    $start=site_links_request('start');
    inventory_check(!empty($start['success']),'Editor can start audit');
    $run=$start['data']['state']['run'];
    do {
        $response=site_links_request('scan',$run);
        inventory_check(!empty($response['success']),'Scan batch succeeds: '.json_encode($response));
    } while (empty($response['data']['state']['complete']));
    global $wpdb;
    $table=$wpdb->prefix.'ogo_site_links';
    $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND domain=%s ORDER BY post_id,closed",$run,$domain),ARRAY_A);
    inventory_check(count($rows)===5 && (int)$rows[0]['link_count']===2 && (int)$rows[0]['new_tab']===1 && (int)$rows[0]['closed']===0,'One URL can be represented in different rel states');
    $paid_row=array_values(array_filter($rows,function($row) use ($second){return (int)$row['post_id']===(int)$second;}));
    inventory_check(count($paid_row)===1 && (int)$paid_row[0]['paid']===1 && (int)$paid_row[0]['ad_marker']===1,'Footer is surfaced for published source: '.json_encode(array_column($rows,'ad_marker')));
    $classified=array_column($rows,null,'post_id');
    foreach (array($registered,$field) as $id) {
        inventory_check((int)$classified[$id]['paid']===0 && (int)$classified[$id]['ad_marker']===0 && (int)$classified[$id]['closed']===0,'Private flags stay out of new audit reports');
    }
    $_GET=array('project'=>'site_links','run'=>$run,'domain'=>$domain,'paid'=>'1','closed'=>'1');
    ob_start(); OGO_Media_Cleaner::page(); $html=ob_get_clean();
    inventory_check(strpos($html,'На правах реклами')!==false && strpos($html,'post='.$second)!==false && strpos($html,'post='.$first)===false,'Status filters open only the matching article');
    $state_key='ogo_mc_site_links_run_'.$run;
    update_option($state_key,get_option($state_key)+array('paid_revision'=>'old-fixture'),false);
    foreach (array($registered,$field) as $id) $wpdb->update($table,array('paid'=>1),array('run_id'=>$run,'post_id'=>$id));
    $_GET=array('project'=>'site_links','run'=>$run,'domain'=>$domain,'paid'=>'1');
    ob_start(); OGO_Media_Cleaner::page(); $legacy=ob_get_clean();
    inventory_check(strpos($legacy,'post='.$second)!==false && strpos($legacy,'post='.$registered)===false
        && strpos($legacy,'post='.$field)===false,'Older paid filters show only public signals');
    $_GET=array('project'=>'site_links','run'=>$run,'paid'=>'1','closed'=>'0');
    ob_start(); OGO_Media_Cleaner::page(); $legacy_summary=ob_get_clean();
    inventory_check(strpos($legacy_summary,$domain)===false,'Older paid aggregates omit private flags');
    inventory_check(!site_links_request('apply',$run)['success'] && get_post($first)->post_content===$open.$open.$closed,'Audit has no content mutation');
    inventory_check(OGO_MC_External::state()===$previous,'Miralinks run remains independent');
    wp_set_current_user(0);
    inventory_check(!site_links_request('start')['success'],'Unauthorized visitors cannot start the audit');
} finally {
    wp_set_current_user($admin);
    if ($previous_paid) update_option('ogo_mc_paid_articles',$previous_paid,false);
    else delete_option('ogo_mc_paid_articles');
    foreach (array($first,$second,$draft,$registered,$field) as $id) wp_delete_post($id,true);
}
echo "Passed WordPress site audit grouping, filters, permissions and read-only scope checks.\n";
