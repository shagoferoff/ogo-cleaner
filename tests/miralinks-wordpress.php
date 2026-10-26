<?php
$root=getenv('OGO_TEST_WP_ROOT');
if(!$root || !is_file($root.'/wp-load.php')){fwrite(STDERR,"Set OGO_TEST_WP_ROOT to the isolated WordPress test installation.\n");exit(2);}
define('DOING_AJAX',true);require $root.'/wp-load.php';
if(!defined('DB_NAME') || DB_NAME!=='ogo_audit_test')throw new RuntimeException('Dedicated test database required');
wp_set_current_user(get_user_by('login','audit_admin')->ID);add_filter('pre_wp_mail',function(){return true;});
if(($argv[1]??'')==='export') {
    $run=$argv[2];$_GET=array('run'=>$run,'report'=>$argv[3]??'details','_wpnonce'=>wp_create_nonce('ogo_mla_export_'.$run));$_REQUEST=$_GET;OGO_MLA_Report::export();
}
class OGO_External_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_External_Test_Exit();};});
$count=0;
function ext_assert($ok,$why){global $count;if(!$ok)throw new RuntimeException($why);++$count;}
function ext_request($mode,$run='',$legacy=false,$nonce=null){
    $action=$legacy?'ogo_mc':'ogo_mla';
    $_POST=array('mode'=>$mode,'run'=>$run,'project'=>'external_audit','nonce'=>$nonce??wp_create_nonce($action));$_REQUEST=$_POST;
    ob_start();try{do_action('wp_ajax_'.$action);}catch(OGO_External_Test_Exit $e){}$result=json_decode(ob_get_clean(),true);
    if(!is_array($result))throw new RuntimeException('No AJAX JSON');return $result;
}
function ext_success($r){if(!$r['success'])throw new RuntimeException(json_encode($r));return $r['data'];}
global $wpdb;OGO_MLA_Report::activate();
ext_assert(get_option('ogo_mla_schema')==='1.0.0','Independent InnoDB table created');
$wpdb->query("SET SESSION sql_mode=CONCAT(@@sql_mode,',ONLY_FULL_GROUP_BY')");
$ids=OGO_MLA_List::ids();$fixtures=array_slice($ids,0,55);$fixtures[]=107026;
foreach($fixtures as $id)if(get_post($id))throw new RuntimeException('Fixture collision at '.$id.'; use a dedicated fresh test database');
$source='<p>Текст <a href="https://shop.example/item?x=1&amp;y=2">Фабрика Комфорта</a> далі.</p><a href="https://my.rv.ua/source/">Джерело</a><a href="https://ogo.ua/internal/">Внутрішнє</a>';
for($i=0;$i<505;++$i)$source.='<a href="https://domain-'.$i.'.example/item">Товар '.$i.'</a>';
foreach($fixtures as $id){$r=wp_insert_post(wp_slash(array('import_id'=>$id,'post_status'=>'publish','post_type'=>'post','post_title'=>$id===107026?'=FORMULA fixture':'OGO external fixture '.$id,'post_content'=>$id===107026?$source:'<a href="https://shop.example/">Реклама</a>')));ext_assert($r===$id,'Fixture ID preserved');}
$empty=$fixtures[0];$draft=$fixtures[1];$trash=$fixtures[2];$page=$fixtures[3];
wp_update_post(wp_slash(array('ID'=>$empty,'post_content'=>'Лише текст.')));
wp_update_post(array('ID'=>$draft,'post_status'=>'draft'));
wp_update_post(array('ID'=>$trash,'post_status'=>'trash'));
wp_update_post(array('ID'=>$page,'post_type'=>'page'));
update_post_meta($empty,'anons','<a href="https://anons-only.example/">Анонс</a>');
update_post_meta(107026,'anons','<p>Окремий вступ <a href="https://anons-only.example/">Реклама в анонсі</a></p>');
$green=113890;if(get_post($green))throw new RuntimeException('Green fixture collision');
wp_insert_post(array('import_id'=>$green,'post_status'=>'publish','post_title'=>'Green external fixture','post_content'=>'<a href="https://green-only.example/">Зелене</a>'));
$outside=wp_insert_post(array('post_status'=>'publish','post_title'=>'Outside external fixture','post_content'=>'<a href="https://outside-only.example/">Поза списком</a>'));
$posts_before=$wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY ID",ARRAY_A);
$meta_before=$wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",ARRAY_A);
$r=ext_success(ext_request('start'));$run=$r['state']['run'];
$r=ext_success(ext_request('scan',$run));
ext_assert($r['state']['scanned']===50 && !$r['state']['complete'],'First request examines only 50 manifest IDs');
$partial=OGO_MLA_Report::state($run);
while(!$r['state']['complete'])$r=ext_success(ext_request('scan',$run));
ext_assert($r['state']['scanned']===2215 && (int)$r['stats']['totals']['checked']===2215,'Every manifest ID gets a result including absent and empty records');
$stats=OGO_MLA_Report::stats($run);$table=$wpdb->prefix.'ogo_miralinks_audit';
ext_assert(!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE run_id=%s AND (post_id=%d OR post_id=%d)",$run,$green,$outside)),'Green and unlisted articles never scanned');
ext_assert(!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE run_id=%s AND domain IN ('anons-only.example','ogo.ua')",$run)),'anons and internal URLs are absent from inventory');
$get=function($id)use($wpdb,$table,$run){return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id=%d",$run,$id),ARRAY_A);};
ext_assert($get($empty)[0]['kind']==='empty','Body without links remains empty despite advertising in anons');
ext_assert($get($draft)[0]['kind']==='external','Draft body can be audited without publishing');
ext_assert($get($trash)[0]['kind']==='skipped' && $get($page)[0]['kind']==='skipped','Trash and non-article records are distinguished');
ext_assert($get($ids[60])[0]['kind']==='missing','Absent ID is explicitly reported');
$example=$get(107026);ext_assert(count($example)===507 && in_array('Фабрика Комфорта',array_column($example,'anchor_text'),true),'Snapshot contains source title, exact href and anchor');
ext_assert(in_array('network',array_column($example,'kind'),true),'Related sites are categorized separately');
$r=ext_success(ext_request('scan',$run));ext_assert($r['stats']===$stats,'Repeated completed scan never doubles counts');
$store=new ReflectionMethod('OGO_MLA_Report','store');$store->setAccessible(true);
$store->invoke(null,$run,107026,get_post(107026));$store->invoke(null,$run,107026,get_post(107026));
ext_assert(OGO_MLA_Report::stats($run)===$stats,'Replay is idempotent after commit before progress persistence');
$wpdb->suppress_errors(true);
$fail=function($sql)use($table){return strpos($sql,'INSERT INTO `'.$table.'`')===0?'INSERT INTO nonexistent_external_test VALUES (1)':$sql;};
add_filter('query',$fail);$failed=false;
try{$store->invoke(null,$run,107026,get_post(107026));}catch(RuntimeException $e){$failed=true;}
remove_filter('query',$fail);$wpdb->suppress_errors(false);
ext_assert($failed && OGO_MLA_Report::stats($run)===$stats,'Insert failure rolls back the per-article deletion');
$details=OGO_MLA_Report::details($run,'','',5000);
ext_assert(count($details)>500 && count(array_unique(array_column($details,'post_id')))===2215,'Detail export spans multiple chunks and covers the whole list');
$domain_rows=OGO_MLA_Report::domains($run,'',1000);
ext_assert(count($domain_rows)>500,'Domain export spans multiple chunks');
$domain_keys=array();$after='';
do {
    $chunk=OGO_MLA_Report::domains($run,'',500,0,$after);
    foreach($chunk as $item){$domain_keys[]=$item['domain'];$after=$item['domain'];}
}while(count($chunk)===500);
ext_assert(count($domain_keys)===count($domain_rows) && count(array_unique($domain_keys))===count($domain_rows),'Domain export keyset order includes each group exactly once');
$detail_keys=array();$after=0;
do {
    $chunk=OGO_MLA_Report::details($run,'','',500,0,$after);
    foreach($chunk as $item){$detail_keys[]=$item['id'];$after=(int)$item['id'];}
}while(count($chunk)===500);
ext_assert(count($detail_keys)===count($details) && count(array_unique($detail_keys))===count($details),'Detail export keyset covers every result exactly once');

foreach(array('apply','restore') as $mode)ext_assert(!ext_request($mode,$run)['success'],'Read-only endpoint rejects '.$mode);
ext_assert(!method_exists('OGO_MLA_Report','change'),'Standalone report has no content mutation method');
wp_set_current_user(0);ext_assert(!ext_request('scan',$run)['success'],'Unauthenticated requests denied');wp_set_current_user(get_user_by('login','audit_admin')->ID);
$before=OGO_MLA_Report::state();ob_start();$nonce_failed=false;
$_POST=array('mode'=>'start','nonce'=>'bad-nonce');$_REQUEST=$_POST;
try{do_action('wp_ajax_ogo_mla');}catch(OGO_External_Test_Exit $e){$nonce_failed=true;}
ob_end_clean();
ext_assert($nonce_failed && OGO_MLA_Report::state()===$before,'Invalid nonce cannot start a report');
$_GET=array('project'=>'external_audit','run'=>$run);ob_start();OGO_MLA_Report::page();$html=ob_get_clean();
ext_assert(strpos($html,'CSV — статті, адреси й анкори')!==false && strpos($html,'data-mode="apply"')===false,'UI has CSV export and no cleanup controls');
$_GET=array('project'=>'external_audit','run'=>$run,'view'=>'details');ob_start();OGO_MLA_Report::page();$html=ob_get_clean();
ext_assert(strpos($html,'anons')!==false && strpos($html,'Стаття')!==false,'Detail view explains the body-only scope');
$original_state=OGO_MLA_Report::state($run);$obsolete=$original_state;$obsolete['manifest']='obsolete-list';update_option('ogo_mla_run_'.$run,$obsolete,false);
$_GET=array('project'=>'external_audit','run'=>$run);ob_start();OGO_MLA_Report::page();$old_html=ob_get_clean();
ext_assert(strpos($old_html,'CSV-експорт заблоковано')!==false && strpos($old_html,'CSV — статті, адреси й анкори')===false,'Obsolete-list report warns about hidden sheets and hides CSV buttons');
$_GET=array('run'=>$run,'report'=>'details','_wpnonce'=>wp_create_nonce('ogo_mla_export_'.$run));$_REQUEST=$_GET;ob_start();$export_denied=false;
try{OGO_MLA_Report::export();}catch(OGO_External_Test_Exit $e){$export_denied=true;}ob_end_clean();
ext_assert($export_denied,'Direct export of an obsolete-list report is rejected');
update_option('ogo_mla_run_'.$run,$original_state,false);

ext_assert($wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY ID",ARRAY_A)===$posts_before,'All post fields and timestamps unchanged');
ext_assert($wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",ARRAY_A)===$meta_before,'All metadata including anons unchanged');
$r=ext_success(ext_request('start'));ext_assert($r['state']['run']!==$run,'New run retains independent history');
ext_assert(!ext_request('scan',$run)['success'] && OGO_MLA_Report::state($run)['complete'],'Stale scan rejected while old report remains available');
$new_run=$r['state']['run'];$wpdb->suppress_errors(true);
$read_failure=function($sql)use($wpdb){return strpos($sql,'SELECT ID,post_title,post_content,post_type,post_status FROM '.$wpdb->posts)===0?'SELECT * FROM nonexistent_external_posts_test':$sql;};
add_filter('query',$read_failure);$read_result=ext_request('scan',$new_run);remove_filter('query',$read_failure);$wpdb->suppress_errors(false);
ext_assert(!$read_result['success'] && OGO_MLA_Report::state($new_run)['cursor']===0 && (int)OGO_MLA_Report::stats($new_run)['totals']['checked']===0,'Database read failure never manufactures missing-article results');

file_put_contents('/tmp/ogo-miralinks-integration-result.json',json_encode(array('run'=>$run,'details'=>count($details),'domains'=>count($domain_rows),'stats'=>$stats,'expected_ids'=>$ids)));
foreach(array_merge($fixtures,array($green,$outside)) as $id)wp_delete_post($id,true);
echo 'Passed '.$count." WordPress standalone Miralinks scope/read-only/resume/CSV/permissions checks.\n";
