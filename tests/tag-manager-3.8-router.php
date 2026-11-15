<?php
define('OGO_TEST_BOOTSTRAP_ONLY',true);
require __DIR__.'/tag-manager-3.8-test.php';
session_start();$request_post=$_POST;reset_fixture();$_POST=$request_post;
foreach(['options','terms','links','uuid'] as $key)if(isset($_SESSION[$key]))$GLOBALS[$key]=$_SESSION[$key];
register_shutdown_function(function(){foreach(['options','terms','links','uuid'] as $key)$_SESSION[$key]=$GLOBALS[$key];});
function is_admin(){return false;}
function nocache_headers(){header('Cache-Control: no-cache, no-store, must-revalidate');}
function wp_safe_redirect($url,$status=302,$by=''){header('Location: '.$url,true,$status);header('X-Redirect-By: '.$by);return true;}
function check_admin_referer($action){}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);}
function plugins_url($path,$file){return '/'.$path;}
function wp_enqueue_script($handle,$url,...$args){$GLOBALS['scripts'][$handle]=$url;}
function wp_enqueue_style($handle,$url,...$args){$GLOBALS['styles'][$handle]=$url;}
function wp_localize_script($handle,$name,$data){$GLOBALS['localized'][$name]=$data;}
function wp_create_nonce($action){return 'nonce';}
function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="nonce">';}
function wp_die($message){http_response_code(400);echo $message;exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/reset'){reset_fixture();echo 'reset';return;}
if($path==='/seed-export-test'){
 for($i=100;$i<105;$i++){$GLOBALS['terms'][$i]=(object)['term_id'=>$i,'term_taxonomy_id'=>$i,'name'=>'Позначка '.$i,'slug'=>'позначка-'.$i];$GLOBALS['links'][$i]=[$i];}
 echo 'seeded';return;
}
if($path==='/assets/simple.js'){header('Content-Type: application/javascript');readfile(OGO_TEST_PLUGIN_ROOT.'/assets/simple.js');return;}
if($path==='/assets/simple.css'){header('Content-Type: text/css');readfile(OGO_TEST_PLUGIN_ROOT.'/assets/simple.css');return;}
if($path==='/api'){
 try{OGO_Tag_Manager::simple_api();}catch(JsonResult $r){header('Content-Type: application/json');if(!$r->ok)http_response_code(400);echo json_encode(['success'=>$r->ok,'data'=>$r->data]);}return;
}
if($path==='/export'){if(!isset($_POST['scope'])&&!isset($_GET['scope']))$_GET['scope']='all';OGO_Tag_Manager::simple_csv();return;}
if($path==='/delete-source'){unset($GLOBALS['terms'][5681]);echo 'deleted';return;}
if($path==='/page'){
 echo '<!doctype html><html lang="uk"><meta charset="utf-8"><title>OGO Tag Manager QA</title><style>body{font:14px Arial;background:#f0f0f1;color:#1d2327;padding:20px}.wrap{max-width:1450px}button,.button{padding:8px;border:1px solid #2271b1;border-radius:3px;display:inline-block;cursor:pointer;background:#fff;color:#2271b1;text-decoration:none}.button-primary{background:#2271b1;color:#fff}input{padding:6px}table{border-collapse:collapse;background:white;width:100%}th,td{text-align:left;padding:9px;vertical-align:top;border-bottom:1px solid #ddd;overflow-wrap:anywhere}td input[type=text]{width:95%;box-sizing:border-box}h2{margin-top:32px}#ogo-simple-progress{padding:16px;background:white;white-space:pre-wrap}[hidden]{display:none!important}</style>';
 OGO_Tag_Manager::simple_assets('tools_page_ogo-tag-manager');
 foreach($GLOBALS['styles']??[] as $url)echo '<link rel="stylesheet" href="'.esc_url($url).'">';
 OGO_Tag_Manager::page();
 foreach($GLOBALS['localized']??[] as $name=>$data){$data['ajax']='/api';echo '<script>var '.$name.'='.json_encode($data).'</script>';}
 foreach($GLOBALS['scripts']??[] as $url)echo '<script src="'.esc_url($url).'"></script>';
 echo '</html>';return;
}
// Use local server URLs only for these test responses.
OGO_Tag_Manager::simple_redirect();
header('Content-Type: text/plain');echo 'No redirect';
