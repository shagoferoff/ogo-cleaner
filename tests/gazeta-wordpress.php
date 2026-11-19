<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) exit(2);
define('DOING_AJAX',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
add_filter('pre_wp_mail',function(){return true;});
class OGO_Archive_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Archive_Test_Exit();};});
$count=0;
function archive_assert($condition,$why) {
    global $count;
    if (!$condition) throw new RuntimeException($why);
    ++$count;
}
function archive_request($project,$mode,$run) {
    $_POST=array('project'=>$project,'mode'=>$mode,'run'=>$run,'backup'=>'1','nonce'=>wp_create_nonce('ogo_mc'));
    $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc'); } catch (OGO_Archive_Test_Exit $e) {}
    finally { delete_option('ogo_mc_lock'); }
    return json_decode(ob_get_clean(),true);
}
function archive_page($project,$run='') {
    $_GET=array('project'=>$project);
    if ($run!=='') $_GET['run']=$run;
    ob_start();
    OGO_Media_Cleaner::page();
    return ob_get_clean();
}
global $wpdb;
$table=$wpdb->prefix.'ogo_media_cleanup';
$admin=get_user_by('login','audit_admin');
$editor=get_user_by('login','ogo_gazeta_editor');
$editor_id=$editor?$editor->ID:wp_insert_user(array('user_login'=>'ogo_gazeta_editor','user_pass'=>'isolated-gazeta-test','role'=>'editor'));
$projects=array('media','media_unwrap','blog','gazeta','catalog','dossier_urls');
$before='За матеріалами <a href="http://gazeta.rv.ua/">"Рівненської газети"</a>';
$after='За матеріалами "Рівненської газети"';
foreach ($projects as $project) {
    wp_set_current_user($admin->ID);
    $id=wp_insert_post(wp_slash(array('post_status'=>'publish','post_title'=>'Historic '.$project,'post_content'=>$after)));
    $run=wp_generate_uuid4();
    $post=get_post($id);
    $saved_project=$project==='gazeta'?'gazeta_sources':$project;
    $wpdb->insert($table,array(
        'run_id'=>$run,'project'=>$saved_project,'post_id'=>$id,
        'before_content'=>$before,'after_content'=>$after,'modified_gmt'=>$post->post_modified_gmt,
        'post_status'=>'publish','status'=>'done','removed'=>1,'review'=>0,'changed_gmt'=>$post->post_modified_gmt,'message'=>'Historical caption',
    ));
    if ($project==='gazeta') {
        update_option('ogo_mc_schema','1.3.0');
        OGO_Media_Cleaner::activate();
        archive_assert(get_option('ogo_mc_schema')==='1.3.1','Schema migration completed');
    }
    wp_set_current_user($editor_id);
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s",$run));
    archive_assert($row && $row->project===$project && $row->before_content===$before && $row->after_content===$after && $row->status==='done','Preserved historic snapshots for '.$project);
    foreach (array('start','scan','apply') as $mode) {
        $response=archive_request($project,$mode,$run);
        archive_assert($response && !$response['success'] && strpos($response['data'],'завершеного очищення')!==false,'Archived '.$project.' rejects '.$mode);
    }
    $html=archive_page($saved_project,$run);
    archive_assert(strpos($html,'data-mode="start"')===false && strpos($html,'data-mode="apply"')===false,'Historical page exposes no scan or apply for '.$project);
    archive_assert(strpos($html,'data-mode="restore"')!==false && strpos($html,'value="'.$run.'"')!==false && strpos($html,'Було → стане')!==false,'Editor can open report and restore for '.$project);
    $response=archive_request($project,'restore',$run);
    archive_assert(!empty($response['success']) && get_post($id)->post_content===$before,'Editor restores exact archived content for '.$project);
    wp_delete_post($id,true);
    $wpdb->delete($table,array('run_id'=>$run));
}
foreach (array('legacy_missing','audit','external_audit') as $page) {
    $html=archive_page($page);
    preg_match('~<select name="project"[^>]*>(.*?)</select>~s',$html,$selector);
    archive_assert(isset($selector[1]),'Active selector exists for '.$page);
    foreach ($projects as $project) {
        archive_assert(strpos($selector[1],'value="'.$project.'"')===false && strpos($html,'project='.$project.'"')!==false,'Archived operation stays reachable via journal links on '.$page.': '.$project);
    }
    foreach (array('segodnya','tsn') as $project) {
        archive_assert(strpos($selector[1],'value="'.$project.'"')!==false,'New operation appears on '.$page.': '.$project);
    }
}
echo 'Passed '.$count." WordPress archived menu, migration and editor rollback checks.\n";
