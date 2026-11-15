<?php
define('OGO_TEST_BOOTSTRAP_ONLY',true);
putenv('OGO_TEST_PLUGIN_ROOT='.(is_dir(__DIR__.'/../ogo-tag-manager-3.10.0/ogo-tag-manager')?__DIR__.'/../ogo-tag-manager-3.10.0/ogo-tag-manager':__DIR__.'/../ogo-tag-manager'));
require __DIR__.'/tag-manager-3.8-test.php';
$passed=0;
function start_export($kind){$r=api('export_start',['kind'=>$kind]);if(!$r->ok)throw new Exception(json_encode($r->data));return $r->data['export_batch'];}
function add_slug_fixture($id){$GLOBALS['terms'][$id]=(object)['term_id'=>$id,'term_taxonomy_id'=>$id,'name'=>'Позначка '.$id,'slug'=>'позначка-'.$id];$GLOBALS['links'][$id]=[$id];}
function change_slug($id,$batch='',$slug=''){return api('slug',['id'=>$id,'old_slug'=>$GLOBALS['terms'][$id]->slug,'slug'=>$slug?:'tag-'.$id,'export_batch'=>$batch]);}
function export_rows($id){return call_private('export_csv_rows','batch',$id);}
reset_fixture();merge_pair();change_slug(3,'','bezpilotnyk');
$run=start_export('slug');$keys=[];
for($i=100;$i<105;$i++){add_slug_fixture($i);$r=change_slug($i,$run);check($r->ok&&$r->data['export']['count']===$i-99,'successful slug is counted in current run '.$i);$keys[]=hash('sha256',call_private('simple_path','https://ogo.test/tag/позначка-'.$i.'/'));}
check(count(call_private('export_csv_rows','all'))===7,'full registry retains older and current rules');
check(count(export_rows($run))===5,'five new changes export exactly five redirects, excluding two older rules');
check(count(call_private('export_csv_rows','latest'))===5,'default latest export is scoped to current action');
$snapshot=$GLOBALS['options'];$first=export_rows($run);$second=export_rows($run);check($first===$second&&$snapshot===$GLOBALS['options'],'download is repeatable and never marks rules as imported');
check(count(call_private('export_csv_rows','selected','',$keys))===5,'five legacy rows can be exported without old batch metadata');
check(count(call_private('export_csv_rows','selected','',[$keys[0],$keys[0]]))===1,'selected duplicate source keys produce one CSV row');
try{call_private('export_csv_rows','selected','',[]);check(false,'empty selection');}catch(RuntimeException $e){check(true,'empty selection never falls back to full registry');}
try{call_private('export_csv_rows','selected','',['unknown']);check(false,'invalid key');}catch(RuntimeException $e){check(true,'unknown selected key is rejected');}
try{call_private('export_csv_rows','batch','missing');check(false,'unknown batch');}catch(RuntimeException $e){check(true,'unknown batch never falls back to all');}
$empty=start_export('slug');check(!call_private('export_csv_rows','latest'),'new empty action never exports previous rules');
$batches=get_option('ogo_tm_export_batches_v310');$batches[$run]['user']=999;$GLOBALS['options']['ogo_tm_export_batches_v310']=$batches;
try{export_rows($run);check(false,'ownership');}catch(RuntimeException $e){check(true,'another user cannot download the batch');}
reset_fixture();$run=start_export('slug');$a=change_slug(3,$run,'occupied');check(!$a->ok&&!export_rows($run),'occupied slug contributes no CSV row');
add_slug_fixture(100);add_slug_fixture(101);change_slug(100,$run);change_slug(101,$run);check(count(export_rows($run))===2,'partial failure exports only actual active changes');
$jobs=get_option('ogo_tm_slug_jobs_v37');foreach($jobs as $id=>$job)if(isset($job['items'][100])&&$job['items'][100]['state']==='applied')$undoBatch=$id;
$_POST=['batch'=>$undoBatch,'id'=>100,'confirmed'=>'1'];call_private('slug_undo');check(count(export_rows($run))===1,'undone change is excluded from batch');
reset_fixture();$run=start_export('slug');$GLOBALS['fail_update']=true;$r=change_slug(3,$run,'bezpilotnyk');check(!$r->ok&&!export_rows($run),'failed mutation with durable intention is not exported');
reset_fixture();$run=start_export('slug');$GLOBALS['fail_save']='ogo_tm_export_batches_v310';$old=$GLOBALS['terms'][3]->slug;$r=change_slug(3,$run,'bezpilotnyk');check(!$r->ok&&$GLOBALS['terms'][3]->slug===$old,'batch membership persistence failure prevents slug mutation');
reset_fixture();$run=start_export('slug');$GLOBALS['fail_save']='ogo_tm_redirects_v38';$r=change_slug(3,$run,'bezpilotnyk');check(!$r->ok&&!export_rows($run),'failed rule save creates no stale export row');
reset_fixture();$run=start_export('merge');$r=change_slug(3,$run,'bezpilotnyk');check(!$r->ok&&$GLOBALS['terms'][3]->slug==='безпілотник','wrong batch kind rejected before mutation');
$r=api('slug',['id'=>3,'old_slug'=>'безпілотник','slug'=>'bezpilotnyk','export_batch'=>'forged']);check(!$r->ok&&$GLOBALS['terms'][3]->slug==='безпілотник','forged batch rejected before mutation');
reset_fixture();$run=start_export('merge');$p=api('preview',['source'=>5681,'target'=>7058]);$args=['source'=>5681,'target'=>7058,'hash'=>$p->data['hash'],'export_batch'=>$run];$r=api('merge',$args);check($r->ok&&count(export_rows($run))===1,'merge batch captures source redirect once');
$r=api('merge',$args);check($r->ok&&count(export_rows($run))===1,'retry in same batch does not duplicate row');
$other=start_export('merge');$args['export_batch']=$other;$r=api('merge',$args);check($r->ok&&!export_rows($other),'retry of existing merge in a new action does not re-export old rule');
$scan=api('delete_scan');$source=$scan->data['items'][0];$r=api('delete',['id'=>$source['id'],'token'=>$source['token'],'confirmed'=>'1']);check($r->ok&&count(export_rows($run))===1,'source cleanup does not erase original batch CSV');
check(call_private('export_info',$run)['count']===1,'batch remains recoverable after other actions and reload');
reset_fixture();try{call_private('export_csv_rows','latest');check(false,'legacy default');}catch(RuntimeException $e){check(true,'no historical grouping means explicit selection is required, never automatic full export');}
echo "ALL $passed EXPORT CHECKS PASSED\n";
