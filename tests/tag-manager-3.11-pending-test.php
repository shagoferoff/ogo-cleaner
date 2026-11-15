<?php
define('OGO_TEST_BOOTSTRAP_ONLY',true);
putenv('OGO_TEST_PLUGIN_ROOT='.(is_dir(__DIR__.'/../ogo-tag-manager-3.11.0/ogo-tag-manager')?__DIR__.'/../ogo-tag-manager-3.11.0/ogo-tag-manager':__DIR__.'/../ogo-tag-manager'));
require __DIR__.'/tag-manager-3.8-test.php';$passed=0;
require __DIR__.'/redirection-fixture.php';
$key=setup_one();check(count(api('pending_list')->data['items'])===1&&count(call_private('export_csv_rows','pending'))===1,'old rules automatically appear as pending after upgrade');
$r=api('pending_verify',['key'=>$key]);check($r->ok&&!$r->data['ok']&&count(call_private('pending_rules'))===1&&!$GLOBALS['http_calls'],'missing external rule is not masked by internal redirect and makes no HTTP request');
[$rule,$target]=enable_rule($key);$r=api('pending_verify',['key'=>$key]);check($r->ok&&$r->data['ok'],'active exact Redirection rule plus 301 and target 200 passes');
check(!call_private('pending_rules')&&!call_private('export_csv_rows','pending'),'successful rule disappears from pending list and next CSV');
check(call_private('pending_done',$key,$rule,$target),'successful verification survives subsequent requests');
$header=$GLOBALS['http_calls'][0][1]['headers']['X-OGO-Redirect-Probe'];$_SERVER['HTTP_X_OGO_REDIRECT_PROBE']=$header;
check(call_private('pending_probe_valid',$rule['path'])&&!call_private('pending_probe_valid','/tag/other/'),'signed HTTP probe bypass is bound to exact source path');
$_SERVER['HTTP_X_OGO_REDIRECT_PROBE']='forged';check(!call_private('pending_probe_valid',$rule['path']),'visitor cannot bypass fallback with forged probe');
$at=time()-61;$_SERVER['HTTP_X_OGO_REDIRECT_PROBE']=$at.'.'.wp_hash('ogo_tm_probe|'.$at.'|'.$rule['path']);check(!call_private('pending_probe_valid',$rule['path']),'expired probe is rejected');
$GLOBALS['mock_red_rows'][99]['enabled']=false;check(count(call_private('pending_rules'))===1&&count(call_private('export_csv_rows','pending'))===1,'disabled external rule returns to pending and fallback remains available');
check(str_contains(array_values(call_private('pending_rules'))[0]['result'],'Раніше перевірено'),'returned rule explains why it needs attention again');
$key=setup_one();[$rule,$target]=enable_rule($key);$source=call_private('pending_absolute',$rule['path']);$GLOBALS['mock_http'][$source]['headers']['x-redirect-by']='OGO Tag Manager';$r=api('pending_verify',['key'=>$key]);check(!$r->data['ok']&&count(call_private('pending_rules'))===1,'cached OGO 301 cannot masquerade as verified Redirection');
foreach(['WordPress',''] as $agent){$key=setup_one();[$rule,$target]=enable_rule($key);$source=call_private('pending_absolute',$rule['path']);$GLOBALS['mock_http'][$source]['headers']['x-redirect-by']=$agent;check(!api('pending_verify',['key'=>$key])->data['ok'],'unconfirmed redirect provider remains pending: '.($agent?:'missing header'));}
$key=setup_one();[$rule,$target]=enable_rule($key);$source=call_private('pending_absolute',$rule['path']);$GLOBALS['mock_http'][$source]['headers']=['location'=>$target,'x-redirect-agent'=>'redirection'];check(api('pending_verify',['key'=>$key])->data['ok'],'legacy Redirection provider header is accepted');
$key=setup_one();[$rule,$target]=enable_rule($key);$source=call_private('pending_absolute',$rule['path']);$GLOBALS['mock_http'][$source]['response']['code']=302;check(!api('pending_verify',['key'=>$key])->data['ok'],'302 does not pass a permanent redirect check');
$key=setup_one();[$rule,$target]=enable_rule($key);$source=call_private('pending_absolute',$rule['path']);$GLOBALS['mock_http'][$source]['headers']['location']='https://ogo.test/tag/intermediate/';check(!api('pending_verify',['key'=>$key])->data['ok']&&count($GLOBALS['http_calls'])===1,'redirect chain or wrong target is rejected without following it');
$key=setup_one();[$rule,$target]=enable_rule($key);$GLOBALS['mock_http'][$target]['response']['code']=404;check(!api('pending_verify',['key'=>$key])->data['ok'],'target 404 remains pending');
$key=setup_one();[$rule,$target]=enable_rule($key);$GLOBALS['mock_http'][call_private('pending_absolute',$rule['path'])]=new WP_Error;check(!api('pending_verify',['key'=>$key])->data['ok'],'network failure remains pending');
foreach(['regex'=>true,'match_type'=>'login','action_type'=>'pass','action_code'=>302,'url'=>'/unrelated/'] as $field=>$value){$key=setup_one();enable_rule($key);$GLOBALS['mock_red_rows'][99][$field]=$value;check(!api('pending_verify',['key'=>$key])->data['ok'],'unsupported external rule does not pass: '.$field);}
$key=setup_one();enable_rule($key);$GLOBALS['mock_red_groups'][7]['enabled']=false;check(!api('pending_verify',['key'=>$key])->data['ok'],'disabled Redirection group is rejected');
$key=setup_one();enable_rule($key);$GLOBALS['mock_red_disabled']=true;check(!api('pending_verify',['key'=>$key])->data['ok'],'globally disabled Redirection cannot be certified');
$key=setup_one();enable_rule($key);api('pending_group',['group'=>8]);check(!api('pending_verify',['key'=>$key])->data['ok'],'rule in a different group does not pass selected-group verification');
api('pending_group',['group'=>7]);check(api('pending_verify',['key'=>$key])->data['ok'],'matching configured group passes verification');
check(!api('pending_group',['group'=>999])->ok,'unknown group preference is rejected');
$key=setup_one();[$rule,$target]=enable_rule($key);$GLOBALS['fail_save']='ogo_tm_redirect_checks_v311';$r=api('pending_verify',['key'=>$key]);check(!$r->ok&&count(call_private('pending_rules'))===1,'failed proof persistence never hides a row');
$key=setup_one();[$rule,$target]=enable_rule($key);api('pending_verify',['key'=>$key]);$GLOBALS['terms'][7058]->slug='new-target';check(count(call_private('pending_rules'))===1,'changed live destination invalidates old verification');
$key=setup_one();[$rule,$target]=enable_rule($key);api('pending_verify',['key'=>$key]);$candidate=api('delete_scan')->data['items'][0];$r=api('delete',['id'=>$candidate['id'],'token'=>$candidate['token'],'confirmed'=>'1']);check($r->ok&&!call_private('pending_rules'),'bulk source deletion retains verified external handoff');
fresh_pending();$batch=api('export_start',['kind'=>'merge'])->data['export_batch'];$p=api('preview',['source'=>5681,'target'=>7058]);api('merge',['source'=>5681,'target'=>7058,'hash'=>$p->data['hash'],'export_batch'=>$batch]);$key=array_key_first(call_private('simple_rules'));enable_rule($key);api('pending_verify',['key'=>$key]);check(!call_private('export_csv_rows','batch',$batch)&&!call_private('export_csv_rows','selected','',[$key])&&count(call_private('export_csv_rows','all'))===1,'verified rules are excluded from batch and selected exports while full backup remains complete');
check(!api('pending_verify',['key'=>'missing'])->ok,'unknown source verification is rejected');
echo "ALL $passed REDIRECTION VERIFICATION CHECKS PASSED\n";
