<?php
define('OGO_TEST_BOOTSTRAP_ONLY',true);
putenv('OGO_TEST_PLUGIN_ROOT='.(is_dir(__DIR__.'/../ogo-tag-manager-3.11.0/ogo-tag-manager')?__DIR__.'/../ogo-tag-manager-3.11.0/ogo-tag-manager':__DIR__.'/../ogo-tag-manager'));
require __DIR__.'/tag-manager-3.8-test.php';$passed=0;
function large_fixture(){reset_fixture();$GLOBALS['links'][5681]=range(1,578);$GLOBALS['links'][7058]=[12,13,900];}
function start_run($kind){$r=api('export_start',['kind'=>$kind]);if(!$r->ok)throw new Exception(json_encode($r->data));return $r->data['export_batch'];}
function run_to_end($args){for($i=0;$i<20;$i++){$r=api('merge',$args);if(!$r->ok||($r->data['complete']??true))return $r;}throw new Exception('too many chunks');}
reset_fixture();$before=$GLOBALS['terms'];$r=api('skip',['source'=>5681,'target'=>7058]);check($r->ok&&count(api('pairs')->data['items'])===1,'skip hides only the exact suggested pair');
check($GLOBALS['terms']===$before,'skip never creates or deletes tags');
check(!api('preview',['source'=>7058,'target'=>5681])->ok,'skip applies to the unordered pair in both directions');
$r=api('skipped');check(count($r->data['items'])===1,'skipped decision persists and is available for review');
api('skip',['source'=>5681,'target'=>7058,'restore'=>'1']);check(count(api('pairs')->data['items'])===2,'restore returns the skipped pair');
reset_fixture();$GLOBALS['terms'][5]->slug='bezpilotnyk';$GLOBALS['terms'][5]->name='Безпілотник';
$r=api('slugs');$conflicts=array_filter($r->data['items'],fn($item)=>$item['id']===3);check(array_values($conflicts)[0]['conflict']['id']===5,'Cyrillic list identifies the existing slug owner');
$r=api('slug_preview',['id'=>3,'old_slug'=>'безпілотник','slug'=>'bezpilotnyk']);check($r->ok&&$r->data['action']==='merge'&&$r->data['target']===5,'occupied slug becomes a concrete merge plan');
$run=start_run('mixed');$p=$r->data;$r=api('merge',['source'=>$p['source'],'target'=>$p['target'],'hash'=>$p['hash'],'export_batch'=>$run]);check($r->ok&&in_array(14,$GLOBALS['links'][5],true)&&count(call_private('export_csv_rows','pending'))===1,'section-two conflict can transfer articles and create a pending CSV rule');
check(!array_filter(api('slugs')->data['items'],fn($row)=>$row['id']===3),'completed merge source is not offered for slug processing again');
reset_fixture();$GLOBALS['terms'][5]->slug='bezpilotnyk';api('skip',['source'=>3,'target'=>5]);check(!array_filter(api('slugs')->data['items'],fn($row)=>$row['id']===3),'skipped conflict is hidden from Cyrillic list');
reset_fixture();$r=api('slug_preview',['id'=>3,'old_slug'=>'безпілотник','slug'=>'bezpilotnyk']);check($r->ok&&$r->data['action']==='slug','free slug retains the rename workflow');
check(call_private('suggestion','кар’єра')==='karyera','apostrophe variant produces existing canonical slug');
check(call_private('suggestion','полум&#700;я')==='polumya','HTML apostrophe entity is decoded before transliteration');
check(call_private('suggestion','Случ')==='sluch','capitalized Cyrillic produces canonical lower-case slug');
large_fixture();$p=api('preview',['source'=>5681,'target'=>7058]);check($p->ok&&$p->data['added']===576,'large pair is accepted without the old 500 limit');
$run=start_run('merge');$args=['source'=>5681,'target'=>7058,'hash'=>$p->data['hash'],'export_batch'=>$run];$r=api('merge',$args);
check($r->ok&&!$r->data['complete']&&$r->data['moved']===100&&$GLOBALS['link_add_calls']===100,'one request writes no more than 100 new links');
check(!active_rules()&&!call_private('export_csv_rows','pending'),'partial operation never activates an incomplete redirect');
check(array_filter(api('pairs')->data['items'],fn($row)=>!empty($row['running'])&&$row['source']===5681),'partial operation reappears as resumable after reload');
check(!api('skip',['source'=>5681,'target'=>7058])->ok,'partially migrated pair cannot be hidden as skipped');
$resumed=start_run('merge');$args['export_batch']=$resumed;$r=run_to_end($args);
check($r->ok&&$r->data['complete']&&count($GLOBALS['links'][7058])===579&&$GLOBALS['links'][5681]===range(1,578),'resumed chunks preserve all source and target articles');
check(!call_private('export_csv_rows','batch',$run)&&count(call_private('export_csv_rows','batch',$resumed))===1,'resumed redirect belongs to one final export batch, not two');
$before=$GLOBALS['links'];$r=api('merge',$args);check($r->ok&&$before===$GLOBALS['links'],'completed chunk retry is idempotent');
large_fixture();$p=api('preview',['source'=>5681,'target'=>7058]);$args=['source'=>5681,'target'=>7058,'hash'=>$p->data['hash']];api('merge',$args);$GLOBALS['fail_add']=111;$r=api('merge',$args);
check(!$r->ok&&get_option(OGO_Tag_Manager::STAGED)[5681]['state']==='running'&&isset($GLOBALS['terms'][5681]),'partial write error retains recoverable intent and source tag');
unset($GLOBALS['fail_add']);$r=run_to_end($args);check($r->ok&&count($GLOBALS['links'][7058])===579,'retry resumes a partially failed chunk without losing or duplicating links');
large_fixture();$p=api('preview',['source'=>5681,'target'=>7058]);$args=['source'=>5681,'target'=>7058,'hash'=>$p->data['hash']];$GLOBALS['after_add_save_fail_at']=1;$r=api('merge',$args);
check(!$r->ok&&count($GLOBALS['links'][7058])===103&&!get_option(OGO_Tag_Manager::STAGED)[5681]['added'],'crash after link writes retains durable pre-write intent');
unset($GLOBALS['after_add_save_fail_at'],$GLOBALS['fail_save']);$r=run_to_end($args);check($r->ok&&count($GLOBALS['links'][7058])===579,'durable intent reconciles writes after interrupted progress save');
large_fixture();$p=api('preview',['source'=>5681,'target'=>7058]);$args=['source'=>5681,'target'=>7058,'hash'=>$p->data['hash']];api('merge',$args);$GLOBALS['links'][5681][]=1000;check(!api('merge',$args)->ok&&!active_rules(),'source edit stops chunked migration and leaves redirect inactive');
large_fixture();$p=api('preview',['source'=>5681,'target'=>7058]);$args=['source'=>5681,'target'=>7058,'hash'=>$p->data['hash']];api('merge',$args);$GLOBALS['links'][7058][]=1000;check(!api('merge',$args)->ok,'unexpected target edits stop chunked migration');
large_fixture();$p=api('preview',['source'=>5681,'target'=>7058]);$args=['source'=>5681,'target'=>7058,'hash'=>$p->data['hash']];api('merge',$args);$GLOBALS['terms'][7058]->slug='edited';check(!api('merge',$args)->ok,'target slug edit stops chunked migration');
large_fixture();$p=api('preview',['source'=>5681,'target'=>7058]);$args=['source'=>5681,'target'=>7058,'hash'=>$p->data['hash']];api('merge',$args);$before=$GLOBALS['links'];check(!api('merge',array_replace($args,['target'=>4]))->ok&&$before===$GLOBALS['links'],'resume with a different target is rejected without writes');
large_fixture();$p=api('preview',['source'=>5681,'target'=>7058]);$args=['source'=>5681,'target'=>7058,'hash'=>$p->data['hash']];$GLOBALS['fail_verify_save']=true;$r=run_to_end($args);
check(!$r->ok&&array_filter(api('pairs')->data['items'],fn($row)=>$row['source']===5681&&!empty($row['running'])),'interrupted final verification remains visible for continuation');
$writes=$GLOBALS['link_add_calls'];unset($GLOBALS['fail_verify_save']);$r=api('merge',$args);check($r->ok&&$GLOBALS['link_add_calls']===$writes&&count(active_rules())===1,'final verification can resume without repeating relationship writes');
echo "ALL $passed GUIDED / CHUNK CHECKS PASSED\n";
