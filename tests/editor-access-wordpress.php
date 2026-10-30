<?php
/** Opt-in role/access integration test on a dedicated WordPress database. */
$root=getenv('OGO_TEST_WP_ROOT');
if(!$root || !is_file($root.'/wp-load.php')){fwrite(STDERR,"Set OGO_TEST_WP_ROOT to an isolated WordPress installation.\n");exit(2);}
define('DOING_AJAX',true);require $root.'/wp-load.php';
if(!defined('DB_NAME') || DB_NAME!=='ogo_audit_test')throw new RuntimeException('Dedicated test database required');
require_once ABSPATH.'wp-admin/includes/plugin.php';
add_filter('pre_wp_mail',function(){return true;});
class OGO_Role_Die extends RuntimeException {}
class OGO_Role_Export_Reached extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function($message=''){throw new OGO_Role_Die((string)$message);};});
$count=0;
function role_assert($ok,$why){global $count;if(!$ok)throw new RuntimeException($why);++$count;}
function role_user($role){$login='ogo_role_'.$role;$user=get_user_by('login',$login);if(!$user){$id=wp_insert_user(array('user_login'=>$login,'user_pass'=>'isolated-role-test','user_email'=>$login.'@example.invalid','role'=>$role));if(is_wp_error($id))throw new RuntimeException($id->get_error_message());$user=get_user_by('id',$id);}return $user;}
function role_ajax($action,$mode,$run='',$nonce=true){
    $_POST=array('project'=>'editorial_urls','mode'=>$mode,'run'=>$run,'backup'=>'1','nonce'=>$nonce?wp_create_nonce($action):'invalid');$_REQUEST=$_POST;
    ob_start();try{do_action('wp_ajax_'.$action);}catch(OGO_Role_Die $e){}finally{delete_option('ogo_mc_lock');}
    $raw=ob_get_clean();$r=json_decode($raw,true);return is_array($r)?$r:array('success'=>false,'raw'=>$raw);
}
function role_success($r){if(empty($r['success']))throw new RuntimeException('Unexpected denial: '.json_encode($r));return $r['data'];}
function role_scan(){ $r=role_success(role_ajax('ogo_mc','start'));$run=$r['state']['run'];do{$r=role_success(role_ajax('ogo_mc','scan',$run));}while(!$r['state']['complete']);return $run;}
function role_export_probe($class,$run,$nonce_action){
    $_GET=array('run'=>$run,'_wpnonce'=>wp_create_nonce($nonce_action));$_REQUEST=$_GET;
    $probe=function($action,$result)use($nonce_action){if($action===$nonce_action && $result)throw new OGO_Role_Export_Reached();};
    add_action('check_admin_referer',$probe,10,2);$allowed=false;ob_start();
    try{$class::export();}catch(OGO_Role_Export_Reached $e){$allowed=true;}catch(OGO_Role_Die $e){}
    finally{ob_end_clean();remove_action('check_admin_referer',$probe,10);}
    return $allowed;
}
$admin=role_user('administrator');$editor=role_user('editor');$author=role_user('author');$subscriber=role_user('subscriber');$contributor=role_user('contributor');
wp_set_current_user($admin->ID);OGO_Media_Cleaner::activate();OGO_MLA_Report::activate();
$editor_caps=get_role('editor')->capabilities;
$body='<p>Текст <a href="https://ogo.ua/rubrics/view/defense">Оборона</a>.</p>';
$after='<p>Текст <a href="https://ogo.ua/tag/oborona/">Оборона</a>.</p>';
$id=wp_insert_post(wp_slash(array('post_author'=>$admin->ID,'post_type'=>'post','post_status'=>'publish','post_title'=>'Editor access fixture','post_content'=>$body)));
wp_set_current_user($editor->ID);
role_assert(current_user_can('edit_others_posts') && current_user_can('edit_post',$id),'Editor can work on another author’s published post');
foreach(array('manage_options','install_plugins','activate_plugins','edit_users','promote_users','update_plugins') as $cap)role_assert(!current_user_can($cap),'Editor does not receive '.$cap);
// The actual menu registration must use the editor capability too.
global $menu,$submenu,$admin_page_hooks,$_registered_pages,$_wp_real_parent_file;
$menu=array();$submenu=array();$admin_page_hooks=array();$_registered_pages=array();$_wp_real_parent_file=array();
do_action('admin_menu');$slugs=array_column($submenu['tools.php']??array(),2);
role_assert(in_array('ogo-media-cleaner',$slugs,true) && in_array('ogo-miralinks-audit',$slugs,true),'Both tool menus are visible to editor');
foreach(array('media','audit','external_audit') as $project){$_GET=array('project'=>$project);ob_start();OGO_Media_Cleaner::page();$html=ob_get_clean();role_assert(strlen($html)>100,'Editor can open '.$project.' page');}
ob_start();OGO_MLA_Report::page();$html=ob_get_clean();role_assert(strlen($html)>100,'Editor can open standalone audit');
$run=role_scan();role_success(role_ajax('ogo_mc','apply',$run));role_assert(get_post($id)->post_content===$after,'Editor can apply replacement on another author’s post');
role_success(role_ajax('ogo_mc','restore',$run));role_assert(get_post($id)->post_content===$body,'Editor can restore exact source');
$deny=function($caps,$cap,$user_id,$args)use($id,$editor){return $cap==='edit_post' && $user_id===$editor->ID && (int)($args[0]??0)===$id?array('do_not_allow'):$caps;};
$run=role_scan();add_filter('map_meta_cap',$deny,10,4);role_success(role_ajax('ogo_mc','apply',$run));remove_filter('map_meta_cap',$deny,10);
role_assert(get_post($id)->post_content===$body,'Per-post permission denial still blocks application');
$run=role_scan();role_success(role_ajax('ogo_mc','apply',$run));add_filter('map_meta_cap',$deny,10,4);role_success(role_ajax('ogo_mc','restore',$run));remove_filter('map_meta_cap',$deny,10);
role_assert(get_post($id)->post_content===$after,'Per-post permission denial still blocks rollback');
$exports=array();
foreach(array('ogo_mc_audit'=>'OGO_MC_Audit','ogo_mc_external'=>'OGO_MC_External','ogo_mla'=>'OGO_MLA_Report') as $action=>$class){
    $r=role_success(role_ajax($action,'start'));$audit_run=$r['state']['run'];$r=role_success(role_ajax($action,'scan',$audit_run));
    role_assert($r['state']['scanned']>0,'Editor can start/scan '.$action);
    $nonce=$action.'_export_'.$audit_run;$exports[]=array($class,$audit_run,$nonce);
    role_assert(role_export_probe($class,$audit_run,$nonce),'Editor passes CSV export gate '.$class);
    $state=$class::state();role_assert(!role_ajax($action,'start','',false)['success'] && $class::state()===$state,'Nonce guard remains active for '.$action);
}
role_assert(!role_ajax('ogo_mc','start','',false)['success'],'Cleanup nonce is still required');
foreach(array($author,$subscriber,$contributor,null) as $denied){
    wp_set_current_user($denied?$denied->ID:0);
    foreach(array('ogo_mc','ogo_mc_audit','ogo_mc_external','ogo_mla') as $action)role_assert(!role_ajax($action,'start')['success'],'Lower role cannot invoke '.$action);
    foreach($exports as $entry)role_assert(!role_export_probe(...$entry),'Lower role cannot export '.$entry[0]);
    $_GET=array('project'=>'media');ob_start();OGO_Media_Cleaner::page();$html=ob_get_clean();role_assert($html==='','Lower role cannot render cleanup page');
}
wp_set_current_user($admin->ID);role_assert(role_ajax('ogo_mc','start')['success'],'Administrator retains access');
role_assert(get_role('editor')->capabilities===$editor_caps,'No persistent editor role changes or administrator grants');
wp_delete_post($id,true);
echo 'Passed '.$count." editor/admin/author/contributor/subscriber/guest access, CSV, nonce and per-post apply/restore checks.\n";
