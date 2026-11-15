<?php
define('OGO_TEST_BOOTSTRAP_ONLY',true);
putenv('OGO_TEST_PLUGIN_ROOT='.(is_dir(__DIR__.'/../ogo-tag-manager-3.9.0/ogo-tag-manager')?__DIR__.'/../ogo-tag-manager-3.9.0/ogo-tag-manager':__DIR__.'/../ogo-tag-manager'));
require __DIR__.'/tag-manager-3.8-test.php';
$passed=0;
function plan($id=5681){$r=api('delete_scan');if(!$r->ok)throw new Exception(json_encode($r->data));foreach($r->data['items'] as $row)if($row['id']===$id)return $row;return null;}
function delete_source($row,$extra=[]){return api('delete',$extra+['id'=>$row['id'],'token'=>$row['token'],'confirmed'=>'1']);}
reset_fixture();merge_pair();$p=plan();check($p&&$p['eligible']&&!isset($p['_audit']),'preview selects an already merged source without leaking audit metadata');
check(plan(3)===null&&plan(7058)===null,'unmerged tags and final targets are not offered for deletion');
$before=$GLOBALS['links'][7058];$r=delete_source($p);check($r->ok&&!isset($GLOBALS['terms'][5681])&&isset($GLOBALS['terms'][7058]),'deletes source term only');
check($GLOBALS['links'][7058]===$before,'all target article relationships remain');
check(count(active_rules())===1,'automatic redirect remains after bulk deletion');
check(get_option('ogo_tm_deletions_v39')[5681]['state']==='deleted'&&isset(get_option(OGO_Tag_Manager::STAGED)[5681]),'durable deletion journal and migration record retained');
check(plan()===null,'deleted sources disappear from new preview');
check(delete_source($p)->ok,'repeated delete request is idempotent');
$staged=get_option(OGO_Tag_Manager::STAGED);$staged[5681]['last_verify']['result']='Помилка читання зв’язків.';$GLOBALS['options'][OGO_Tag_Manager::STAGED]=$staged;check(count(active_rules())===1,'later legacy verification cannot erase redirect for a recorded deletion');
reset_fixture();merge_pair();$p=plan();$r=delete_source($p,['confirmed'=>'0']);check(!$r->ok&&isset($GLOBALS['terms'][5681]),'confirmation is mandatory');
$r=delete_source($p,['token'=>'forged']);check(!$r->ok&&isset($GLOBALS['terms'][5681]),'forged preview token rejected');
$GLOBALS['links'][7058]=[12,13];$r=delete_source($p);check(!$r->ok&&isset($GLOBALS['terms'][5681]),'missing target article blocks deletion');
reset_fixture();merge_pair();$p=plan();$GLOBALS['links'][5681][]=999;$r=delete_source($p);check(!$r->ok&&isset($GLOBALS['terms'][5681]),'new source article blocks deletion');
reset_fixture();merge_pair();$p=plan();$GLOBALS['terms'][5681]->name='changed';$r=delete_source($p);check(!$r->ok&&isset($GLOBALS['terms'][5681]),'source edit after preview invalidates token');
reset_fixture();merge_pair();$p=plan();$GLOBALS['terms'][7058]->slug='new-target';$r=delete_source($p);check(!$r->ok&&isset($GLOBALS['terms'][5681]),'target edit after preview invalidates token');
reset_fixture();merge_pair();$GLOBALS['options']['wpseo_taxonomy_meta']=['post_tag'=>[5681=>['wpseo_title'=>'Custom SEO']]];$p=plan();check(!$p['eligible']&&str_contains($p['reason'],'SEO'),'custom SEO source is skipped with explanation');
reset_fixture();merge_pair();$GLOBALS['terms'][5681]->description='Custom description';check(!plan()['eligible'],'source description is protected');
reset_fixture();merge_pair();$p=plan();$GLOBALS['term_meta'][5681]=['custom'=>['edited']];check(!delete_source($p)->ok&&isset($GLOBALS['terms'][5681]),'metadata edit after preview blocks deletion');
reset_fixture();merge_pair();$GLOBALS['options']['ogo_tm_redirects_v38']=[];check(!plan()['eligible'],'source without active redirect is skipped');
reset_fixture();merge_pair();$GLOBALS['options'][OGO_Tag_Manager::QUEUE][3]=['kind'=>'merge','target'=>5681];check(!plan()['eligible'],'target of unfinished migration is protected');
reset_fixture();merge_pair();$p=plan();$GLOBALS['denied_cap']='delete_term';check(!delete_source($p)->ok&&isset($GLOBALS['terms'][5681]),'per-term delete capability enforced');
reset_fixture();merge_pair();$p=plan();$GLOBALS['fail_save']='ogo_tm_deletions_v39';check(!delete_source($p)->ok&&isset($GLOBALS['terms'][5681]),'audit persistence failure blocks mutation');
reset_fixture();merge_pair();$p=plan();$GLOBALS['fail_delete']=true;$r=delete_source($p);check(!$r->ok&&isset($GLOBALS['terms'][5681])&&count(active_rules())===1,'WordPress deletion failure leaves term and redirect intact');
unset($GLOBALS['fail_delete']);check(delete_source(plan())->ok,'failed deletion can be retried safely');
reset_fixture();merge_pair();$p=plan();$GLOBALS['fail_after_delete']=true;$r=delete_source($p);check(!$r->ok&&!isset($GLOBALS['terms'][5681])&&get_option('ogo_tm_deletions_v39')[5681]['state']==='deleting','interrupted final audit retains prior durable intention');
unset($GLOBALS['fail_after_delete'],$GLOBALS['fail_save']);check(delete_source($p)->ok&&get_option('ogo_tm_deletions_v39')[5681]['state']==='deleted'&&count(active_rules())===1,'retry recovers interrupted deletion and retains redirect');
reset_fixture();merge_pair();merge_pair(7058,4);$p=plan(7058);check($p['eligible']&&delete_source($p)->ok,'completed intermediate merge can be cleaned up');
$p=plan(5681);check($p['eligible']&&delete_source($p)->ok&&count(active_rules())===2,'earlier sources can be deleted after intermediate target with direct final redirects');
reset_fixture();for($i=10000;$i<10030;$i++){$GLOBALS['terms'][$i]=(object)['term_id'=>$i,'term_taxonomy_id'=>$i,'name'=>'source'.$i,'slug'=>'source-'.$i];$GLOBALS['links'][$i]=[$i];if(!merge_pair($i,7058)->ok)throw new Exception('pagination fixture failed');}
check(count(get_option(OGO_Tag_Manager::STAGED))===30,'thirty-source fixture prepared');
$page=api('delete_scan');check(count($page->data['items'])===25&&$page->data['next']===10024,'preview scans bounded chunks of 25');
delete_source($page->data['items'][0]);$next=api('delete_scan',['after'=>$page->data['next']]);check(count($next->data['items'])===5&&$next->data['next']===null,'source deletion during scan does not shift cursor or skip remaining items');
echo "ALL $passed DELETION CHECKS PASSED\n";
