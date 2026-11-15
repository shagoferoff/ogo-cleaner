<?php
define('OGO_TEST_BOOTSTRAP_ONLY',true);
putenv('OGO_TEST_PLUGIN_ROOT='.(is_dir(__DIR__.'/../ogo-tag-manager-3.11.1/ogo-tag-manager')?__DIR__.'/../ogo-tag-manager-3.11.1/ogo-tag-manager':__DIR__.'/../ogo-tag-manager'));
require __DIR__.'/tag-manager-3.8-test.php';$passed=0;
reset_fixture();$GLOBALS['terms']=[];$GLOBALS['links']=[];
$pairs=[[8422,'кар’єра','кар’єра',6385,"кар'єра",'karyera'],[13161,'Случ','Случ',8811,'СЛУЧ','sluch'],[30768,"п'яні водії","п'яні водії",24238,'п’яні водії','pyani-vodiyi']];
foreach($pairs as [$source,$name,$slug,$target,$targetName,$targetSlug]){
 $GLOBALS['terms'][$source]=(object)['term_id'=>$source,'term_taxonomy_id'=>$source,'name'=>$name,'slug'=>$slug];
 $GLOBALS['terms'][$target]=(object)['term_id'=>$target,'term_taxonomy_id'=>$target,'name'=>$targetName,'slug'=>$targetSlug];
 $GLOBALS['links'][$source]=[$source];$GLOBALS['links'][$target]=[$target];$GLOBALS['lookup_transliteration'][$slug]=$targetSlug;
}
foreach($pairs as [$source,$name,$slug,$target,$targetName,$targetSlug]){
 check(get_term_by('slug',$slug,'post_tag')->term_id===$target,'fixture reproduces filtered slug lookup returning Latin target for '.$source);
 check(merge_pair($source,$target)->ok,'migration completes for '.$source);
 check(count(array_filter(call_private('pending_rules'),fn($row)=>str_contains(rawurldecode($row['source']),$slug)))===1,'Cyrillic source remains an active pending 301 for '.$source);
}
$scan=api('delete_scan');check(count($scan->data['items'])===3&&count(array_filter($scan->data['items'],fn($r)=>$r['eligible']))===3,'all three screenshot sources become eligible after exact ownership check');
check(count(call_private('export_csv_rows','pending'))===3,'CSV contains exactly the three previously blocked redirects');
$GLOBALS['terms'][8422]->slug='renamed-source';check(count(active_rules())===2,'editing original source slug still disables its old rule');
$GLOBALS['terms'][8422]->slug='кар’єра';$GLOBALS['terms'][999]=(object)['term_id'=>999,'term_taxonomy_id'=>999,'name'=>'Other owner','slug'=>'кар’єра'];$GLOBALS['links'][999]=[];
check(count(active_rules())===2,'a different real Cyrillic path owner still blocks redirect despite filtered lookup');
unset($GLOBALS['terms'][999]);$GLOBALS['terms'][8422]->slug=rawurlencode('кар’єра');check(call_private('simple_source_available','/tag/'.rawurlencode('кар’єра').'/',8422),'encoded and literal stored source slugs normalize to the same owner');
$GLOBALS['wpdb']->last_error='simulated read failure';check(!call_private('simple_source_available','/tag/karyera/',6385),'database read failure fails closed');$GLOBALS['wpdb']->last_error='';
echo "ALL $passed EXACT-OWNERSHIP CHECKS PASSED\n";
