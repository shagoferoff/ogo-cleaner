<?php
/** Opt-in integration test. Use an isolated WordPress database named ogo_audit_test. */
$root = getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) { fwrite(STDERR,"Set OGO_TEST_WP_ROOT to an isolated test WordPress installation.\n"); exit(2); }
define('DOING_AJAX',true);
require $root.'/wp-load.php';
if (!defined('DB_NAME') || DB_NAME !== 'ogo_audit_test') throw new RuntimeException('Refusing to run outside the dedicated ogo_audit_test database');
if (!class_exists('OGO_MC_Audit')) throw new RuntimeException('Activate OGO cleaner in the test installation first');
add_filter('pre_wp_mail',function(){return true;});
$user = get_user_by('login','audit_admin');
wp_set_current_user($user->ID);
if (($argv[1] ?? '') === 'export') {
    $run = $argv[2]; $kind = $argv[3] ?? 'groups';
    $_GET = array('run'=>$run,'kind'=>$kind,'_wpnonce'=>wp_create_nonce('ogo_mc_audit_export_'.$run));
    $_REQUEST = $_GET;
    OGO_MC_Audit::export();
}
class OGO_Audit_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Audit_Test_Exit();};});
$count = 0;
function integration_check($ok,$message) { global $count; if (!$ok) throw new RuntimeException($message); ++$count; }
function audit_request($mode,$run='',$project='audit_api') {
    $action = $project === 'audit_api' ? 'ogo_mc_audit' : 'ogo_mc';
    $_POST = array('mode'=>$mode,'run'=>$run,'project'=>'audit','nonce'=>wp_create_nonce($action)); $_REQUEST = $_POST;
    ob_start();
    try { do_action('wp_ajax_'.$action); } catch (OGO_Audit_Test_Exit $e) {}
    $raw = ob_get_clean();
    $json = json_decode($raw,true);
    if (!is_array($json)) throw new RuntimeException('Invalid AJAX JSON: '.$raw);
    return $json;
}
global $wpdb;
require_once ABSPATH.'wp-admin/includes/upgrade.php';
OGO_MC_Audit::activate();
integration_check(get_option('ogo_mc_audit_schema')==='1.0.0','Audit table created through dbDelta');
integration_check($wpdb->prefix==='qa_','Non-production prefix used');
$wpdb->query("SET SESSION sql_mode=CONCAT(@@sql_mode,',ONLY_FULL_GROUP_BY')");
register_post_type('press',array('public'=>true));
$content = '<a href="https://ogo.ua/rubrics/view/unknown-audit/page:16?x=1">one</a><a href="https://ogo.ua/rubrics/view/unknown-audit/page:16?x=1">two</a><a href="http://www.ogo.ua/rubrics/view/unknown-audit/">three</a><a href="https://ogo.ua/rubrics/view/life/">active</a><a href="https://ogo.ua/rubrics/view/dubno/">known</a><a href="https://ogo.ua/oldproj/2010-02-04/33708.html">old</a><a href="https://ogo.ua/current-story/">other</a>';
// WordPress versions may seed an internal privacy-policy link. Account for
// stock content separately; fixture group counts below remain exact.
$baseline_links = 0; $baseline_posts = array(); $expected_groups = array();
foreach ($wpdb->get_results("SELECT ID,post_content FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status IN ('publish','private','draft','pending','future')") as $seed) {
    foreach (OGO_MC_Audit_Engine::extract($seed->post_content,get_permalink($seed->ID)) as $link) {
        $baseline_links += $link['link_count']; $baseline_posts[$seed->ID] = true; $expected_groups[$link['group_key']] = true;
    }
}
foreach (array('unknown_rubric'=>'/rubrics/view/unknown-audit','active'=>'/rubrics/view/life','known'=>'/rubrics/view/dubno','old_article'=>'/oldproj/2010-02-04/33708.html','other'=>'/current-story') as $cat=>$path) $expected_groups[hash('sha256',$cat."\n".'https://ogo.ua'.$path)] = true;
$ids = array();
for ($i=0; $i<55; ++$i) {
    $ids[] = wp_insert_post(wp_slash(array('post_type'=>'post','post_status'=>'publish','post_title'=>$i===0 ? '=FORMULA <script>alert(1)</script>' : 'OGO audit fixture '.$i,'post_content'=>$content)));
}
$press = wp_insert_post(array('post_type'=>'press','post_status'=>'private','post_title'=>'OGO audit private press','post_content'=>'<a href="https://ogo.ua/rubrics/view/unknown-audit">press</a>'));
$ids[] = $press;
$trash = wp_insert_post(array('post_type'=>'post','post_status'=>'trash','post_title'=>'OGO audit excluded trash','post_content'=>$content));
$attachment = wp_insert_post(array('post_type'=>'attachment','post_status'=>'inherit','post_title'=>'OGO audit excluded attachment','post_content'=>$content));
$snapshot = $wpdb->get_results("SELECT ID,post_content,post_modified,post_modified_gmt,post_title,post_status FROM {$wpdb->posts} ORDER BY ID", ARRAY_A);
$r = audit_request('start'); integration_check($r['success'],'Start AJAX'); $run = $r['data']['state']['run'];
$r = audit_request('scan',$run); integration_check($r['success'] && $r['data']['state']['scanned']===50 && !$r['data']['state']['complete'],'First bounded batch scans exactly 50 posts');
$partial = OGO_MC_Audit::state($run);
$r = audit_request('scan',$run); integration_check($r['success'] && $r['data']['state']['complete'],'Resume completes scan');
$stats = $r['data']['stats'];
integration_check((int)$stats['totals']['links']===386+$baseline_links && (int)$stats['totals']['materials']===56+count($baseline_posts) && (int)$stats['totals']['urls']===count($expected_groups),'Aggregate totals distinguish occurrences, materials and groups');
$unknown = OGO_MC_Audit::groups($run,'unknown_rubric');
integration_check(count($unknown)===1 && (int)$unknown[0]['links']===166 && (int)$unknown[0]['materials']===56 && (int)$unknown[0]['variants']===3,'Grouped duplicates and variants across posts');
$details = OGO_MC_Audit::details($run,'unknown_rubric',$unknown[0]['group_key'],500);
integration_check(count($details)===111,'Per-material original href details');
integration_check(!in_array((string)$trash,array_column($details,'post_id'),true) && !in_array((string)$attachment,array_column($details,'post_id'),true),'Trash and unsupported post types excluded');
$r = audit_request('scan',$run); integration_check($r['success'] && $r['data']['stats']===$stats,'Repeated completed scan does not add counts');
$store = new ReflectionMethod('OGO_MC_Audit','store_post'); $store->setAccessible(true);
$post = get_post($ids[0]);
$store->invoke(null,$run,$post); $store->invoke(null,$run,$post);
integration_check(OGO_MC_Audit::stats($run)===$stats,'Replay after commit before cursor persistence is idempotent');
$wpdb->suppress_errors(true);
$fail = function($sql) use ($wpdb) {
    if (strpos($sql,'INSERT INTO `'.$wpdb->prefix.'ogo_link_audit`')===0) return 'INSERT INTO missing_ogo_audit_test_table VALUES (1)';
    return $sql;
};
add_filter('query',$fail);
$failed = false;
try { $store->invoke(null,$run,$post); } catch (RuntimeException $e) { $failed = true; }
remove_filter('query',$fail);
integration_check($failed && OGO_MC_Audit::stats($run)===$stats,'Failed insert rolls back deletion of previous post results');
$wpdb->suppress_errors(false);
$r = audit_request('apply',$run); integration_check(!$r['success'],'Audit endpoint rejects apply');
$r = audit_request('restore',$run); integration_check(!$r['success'],'Audit endpoint rejects restore');
$r = audit_request('apply',$run,'legacy_api'); integration_check(!$r['success'],'Cleanup endpoint rejects audit project');
wp_set_current_user(0); $r = audit_request('scan',$run); integration_check(!$r['success'],'Unauthenticated user cannot read or scan'); wp_set_current_user($user->ID);
$before_nonce = OGO_MC_Audit::state();
$_POST = array('mode'=>'start','nonce'=>'invalid'); $_REQUEST = $_POST;
ob_start(); $nonce_denied = false;
try { do_action('wp_ajax_ogo_mc_audit'); } catch (OGO_Audit_Test_Exit $e) { $nonce_denied = true; }
ob_end_clean();
integration_check($nonce_denied && OGO_MC_Audit::state()===$before_nonce,'Invalid nonce cannot start or change a report');
$r = audit_request('start'); integration_check($r['success'] && $r['data']['state']['run']!==$run,'New run keeps earlier history');
$r = audit_request('scan',$run); integration_check(!$r['success'],'Stale browser cannot continue an older run');
integration_check(OGO_MC_Audit::state($run)['complete'] && OGO_MC_Audit::stats($run)===$stats,'Old report remains available');
$_GET = array('project'=>'audit','run'=>$run);
ob_start(); OGO_Media_Cleaner::page(); $html = ob_get_clean();
integration_check(strpos($html,'CSV — згрупований звіт')!==false && strpos($html,'unknown-audit')!==false,'Audit page renders real grouped results and export links');
integration_check(strpos(wp_scripts()->registered['ogo-audit']->src,'ogo-media-cleaner/assets/audit.js')!==false,'Audit browser script resolves to the plugin asset');
integration_check(strpos($html,'data-mode="apply"')===false && strpos($html,'ogo-backup')===false,'Read-only page contains no cleanup controls');
$_GET['group'] = $unknown[0]['group_key'];
ob_start(); OGO_Media_Cleaner::page(); $html = ob_get_clean();
integration_check(strpos($html,'Оригінальний href')!==false && strpos($html,'post=')!==false,'Source detail links render');
integration_check(strpos($html,'<script>alert(1)</script>')===false,'Source titles escaped');
$after = $wpdb->get_results("SELECT ID,post_content,post_modified,post_modified_gmt,post_title,post_status FROM {$wpdb->posts} ORDER BY ID", ARRAY_A);
integration_check($snapshot===$after,'All posts, contents, titles, statuses and modification times remain byte-for-byte unchanged');
// A run has a fixed ID ceiling. Later posts are picked up only by a new run.
$second = OGO_MC_Audit::state();
$large_content = '';
for ($i=0; $i<505; ++$i) $large_content .= '<a href="https://ogo.ua/audit-large/'.$i.'">link</a>';
$large_id = wp_insert_post(wp_slash(array('post_type'=>'post','post_status'=>'publish','post_title'=>'OGO audit large export','post_content'=>$large_content)));
do { $r = audit_request('scan',$second['run']); if (!$r['success']) throw new RuntimeException('Second scan failed'); } while (!$r['data']['state']['complete']);
integration_check($r['data']['stats']===$stats,'New material above the run ceiling waits for the next scan');
$r = audit_request('start'); $large_run = $r['data']['state']['run'];
$large_snapshot = $wpdb->get_results("SELECT ID,post_content,post_modified,post_modified_gmt FROM {$wpdb->posts} ORDER BY ID",ARRAY_A);
do { $r = audit_request('scan',$large_run); if (!$r['success']) throw new RuntimeException('Large scan failed'); } while (!$r['data']['state']['complete']);
$large_stats = $r['data']['stats'];
integration_check((int)$large_stats['totals']['urls']===(int)$stats['totals']['urls']+505 && (int)$large_stats['totals']['links']===(int)$stats['totals']['links']+505,'Large report aggregates hundreds of unique paths');
$all_groups = array(); $after_group = '';
do {
    $batch = OGO_MC_Audit::groups($large_run,'',500,0,$after_group);
    foreach ($batch as $row) { integration_check(!isset($all_groups[$row['group_key']]),'No repeated group in streamed export'); $all_groups[$row['group_key']] = true; $after_group = $row['group_key']; }
} while (count($batch)===500);
integration_check(count($all_groups)===(int)$large_stats['totals']['urls'],'All groups exported across the 500-row boundary');
$all_details = array(); $after_id = 0;
do {
    $batch = OGO_MC_Audit::details($large_run,'','',500,0,$after_id);
    foreach ($batch as $row) { $all_details[$row['id']] = true; $after_id = (int)$row['id']; }
} while (count($batch)===500);
integration_check(count($all_details)===837,'All raw variants exported across the 500-row boundary');
integration_check(count(OGO_MC_Audit::groups($large_run,'',30,30))===30,'Second report page is populated');
integration_check($large_snapshot===$wpdb->get_results("SELECT ID,post_content,post_modified,post_modified_gmt FROM {$wpdb->posts} ORDER BY ID",ARRAY_A),'Large scan still leaves all post contents and dates unchanged');
$run = $large_run; $stats = $large_stats;
file_put_contents('/tmp/ogo-audit-integration-result.json',json_encode(array('run'=>$run,'checks'=>$count,'wordpress'=>get_bloginfo('version'),'totals'=>$stats['totals'])));
echo 'Passed '.$count.' WordPress/MariaDB integration checks on WordPress '.get_bloginfo('version').".\n";
