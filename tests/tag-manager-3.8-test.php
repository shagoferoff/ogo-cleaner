<?php
// Isolated WordPress API fixtures. No site or external database is contacted.
define('ABSPATH',__DIR__.'/no-wordpress/');define('ARRAY_A','ARRAY_A');
class WP_Error {function get_error_message(){return 'simulated WP failure';}}
class JsonResult extends Exception {public $data;public $ok;function __construct($d,$ok){$this->data=$d;$this->ok=$ok;}}
function add_action(...$x){} function add_filter(...$x){}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function update_option($k,$v,$autoload=null){if(($GLOBALS['fail_save']??'')===$k)return false;$changed=get_option($k,null)!==$v;$GLOBALS['options'][$k]=$v;return $changed;}
function add_option($k,$v,...$x){if(isset($GLOBALS['options'][$k]))return false;$GLOBALS['options'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['options'][$k]);}
function get_term($id,$tax){return isset($GLOBALS['terms'][$id])?clone $GLOBALS['terms'][$id]:false;}
function get_term_meta($id){return $GLOBALS['term_meta'][$id]??[];}
function wp_hash($payload){return hash_hmac('sha256',$payload,'isolated-fixture-secret');}
function wp_delete_term($id,$taxonomy){if($GLOBALS['fail_delete']??false)return new WP_Error;if(!isset($GLOBALS['terms'][$id]))return false;unset($GLOBALS['terms'][$id],$GLOBALS['links'][$id]);if($GLOBALS['fail_after_delete']??false)$GLOBALS['fail_save']='ogo_tm_deletions_v39';return true;}
function get_term_by($by,$value,$tax){foreach($GLOBALS['terms'] as $t)if($t->$by===$value||rawurldecode($t->$by)===$value)return clone $t;return false;}
function get_term_link($t,$tax=null){if(is_int($t))$t=get_term($t,'post_tag');return $t?'https://ogo.test/tag/'.$t->slug.'/':new WP_Error;}
function wp_update_term($id,$tax,$args){if($GLOBALS['fail_update']??false)return new WP_Error;$GLOBALS['terms'][$id]->slug=$args['slug'];return ['term_id'=>$id];}
function wp_add_object_terms($object,$term,$tax){if(($GLOBALS['fail_add']??0)===$object)return new WP_Error;$GLOBALS['links'][$term]=array_values(array_unique(array_merge($GLOBALS['links'][$term],[$object])));return [$term];}
function wp_remove_object_terms($object,$term,$tax){if($GLOBALS['fail_rollback']??false)return new WP_Error;$GLOBALS['links'][$term]=array_values(array_diff($GLOBALS['links'][$term],[$object]));return true;}
function wp_parse_url($u,$component=-1){return parse_url($u,$component);}
function home_url(){return 'https://ogo.test';}
function admin_url($s=''){return 'https://ogo.test/wp-admin/'.$s;}
function wp_nonce_url($url,$action){return $url.'&_wpnonce=nonce';}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function sanitize_text_field($s){return trim(strip_tags($s));}
function sanitize_title($s){$s=mb_strtolower($s);return preg_match('/^[a-z0-9_-]+$/D',$s)?$s:rawurlencode($s);}
function remove_accents($s){return $s;}
function wp_strip_all_tags($s){return strip_tags($s);}
function wp_unslash($s){return is_array($s)?array_map('wp_unslash',$s):stripslashes($s);}
function wp_slash($s){return addslashes($s);}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE);}
function absint($x){return abs((int)$x);}
function current_time($x){return '2026-11-15 12:00:00';}
function get_current_user_id(){return 1;}
function current_user_can($x,...$args){return ($GLOBALS['authorized']??true)&&($GLOBALS['denied_cap']??'')!==$x;}
function check_ajax_referer($x,$y){if(($_POST[$y]??'')!=='nonce')throw new Exception('bad nonce');}
function wp_generate_uuid4(){return 'test-'.++$GLOBALS['uuid'];}
function wp_send_json_error($d,$status=400){throw new JsonResult($d,false);}
function wp_send_json_success($d){throw new JsonResult($d,true);}
class FakeDB {
 public $terms='terms',$term_taxonomy='taxonomy',$term_relationships='relationships',$options='options';
 function prepare($q,...$args){foreach($args as $arg)$q=preg_replace('/%[ds]/',(string)$arg,$q,1);return $q;}
 function get_col($q){preg_match('/term_taxonomy_id=(\d+)/',$q,$m);return $GLOBALS['links'][(int)$m[1]]??[];}
 function get_var($q){
  if(preg_match('/term_id=(\d+)/',$q,$m))return isset($GLOBALS['terms'][(int)$m[1]])?(int)$m[1]:0;
  preg_match_all('/term_taxonomy_id=(\d+)/',$q,$m);$ids=array_map('intval',$m[1]);
  if(count($ids)===2)return count(array_intersect($GLOBALS['links'][$ids[0]],$GLOBALS['links'][$ids[1]]));
  return count($GLOBALS['links'][$ids[0]]??[]);
 }
 function get_results($q,$format){$out=[];foreach($GLOBALS['terms'] as $t)$out[]=['term_id'=>$t->term_id,'name'=>$t->name,'slug'=>$t->slug,'post_count'=>count($GLOBALS['links'][$t->term_id])];return $out;}
}
$wpdb=new FakeDB;
define('OGO_TEST_PLUGIN_ROOT',getenv('OGO_TEST_PLUGIN_ROOT')?: (is_dir(__DIR__.'/../ogo-tag-manager-3.8.0/ogo-tag-manager')?__DIR__.'/../ogo-tag-manager-3.8.0/ogo-tag-manager':__DIR__.'/../ogo-tag-manager'));
require OGO_TEST_PLUGIN_ROOT.'/ogo-tag-manager.php';
function call_private($method,...$args){$r=new ReflectionMethod('OGO_Tag_Manager',$method);return $r->invoke(null,...$args);}
function reset_fixture(){
 $GLOBALS['options']=[];$GLOBALS['terms']=[];$GLOBALS['uuid']=0;$GLOBALS['links']=[5681=>[11,12],7058=>[12,13],3=>[14],4=>[],5=>[]];
 foreach([[5681,'адвокат','advokat'],[7058,'адвокати','%d0%b0%d0%b4%d0%b2%d0%be%d0%ba%d0%b0%d1%82%d0%b8'],[3,'безпілотник','безпілотник'],[4,'безпілотники','bezpilotnyky'],[5,'Інше','occupied']] as [$id,$name,$slug])$GLOBALS['terms'][$id]=(object)['term_id'=>$id,'term_taxonomy_id'=>$id,'name'=>$name,'slug'=>$slug];
 unset($GLOBALS['denied_cap'],$GLOBALS['term_meta'],$GLOBALS['fail_delete'],$GLOBALS['fail_after_delete'],$GLOBALS['fail_save'],$GLOBALS['fail_add'],$GLOBALS['fail_rollback'],$GLOBALS['fail_update'],$GLOBALS['authorized']);$_POST=[];
}
function api($op,$data=[]){$_POST=['op'=>$op,'nonce'=>'nonce']+$data;try{OGO_Tag_Manager::simple_api();}catch(JsonResult $r){return $r;}throw new Exception('missing JSON result');}
function merge_pair($source=5681,$target=7058){$p=api('preview',compact('source','target'));if(!$p->ok)throw new Exception(json_encode($p->data));return api('merge',['source'=>$source,'target'=>$target,'hash'=>$p->data['hash']]);}
function active_rules(){return array_filter(array_map(fn($r)=>call_private('simple_destination',$r),call_private('simple_rules')));}
if(defined('OGO_TEST_BOOTSTRAP_ONLY'))return;
$passed=0;
function check($condition,$message){global $passed;if(!$condition)throw new Exception('FAILED: '.$message);$passed++;echo "PASS $message\n";}
reset_fixture();$r=api('pairs');check($r->ok&&count($r->data['items'])===2,'discovers both requested singular/plural examples');
$r=api('slugs');check($r->ok&&count($r->data['items'])===2,'finds encoded and literal Cyrillic slugs');
check(call_private('suggestion','безпілотники')==='bezpilotnyky','transliterates Ukrainian');
check(call_private('simple_path','/tag/адвокати/')===call_private('simple_path','/tag/%D0%B0%D0%B4%D0%B2%D0%BE%D0%BA%D0%B0%D1%82%D0%B8/'),'normalizes encoded request paths');
$r=merge_pair();check($r->ok,'merge executes');
check($GLOBALS['links'][5681]===[11,12]&&$GLOBALS['links'][7058]===[12,13,11],'preserves source links and target links without duplicates');
check(isset($GLOBALS['terms'][5681]),'source remains for manual deletion');
check(count(active_rules())===1,'verified merge enables redirect');
$before=$GLOBALS['links'];$r=api('merge',['source'=>5681,'target'=>7058,'hash'=>'old']);check($r->ok&&$before===$GLOBALS['links'],'merge retry does not duplicate relationships');
$old=$GLOBALS['terms'][7058]->slug;$r=api('slug',['id'=>7058,'old_slug'=>$old,'slug'=>'advokaty']);check($r->ok,'target slug can change after completed merge');
check(count(active_rules())===2&&count(array_unique(active_rules()))===1&&str_ends_with(array_values(active_rules())[0],'/advokaty/'),'both old addresses resolve directly to final target');
unset($GLOBALS['terms'][5681]);check(count(active_rules())===2,'merge redirect survives manual source deletion');
$GLOBALS['terms'][5]->slug='advokat';check(count(active_rules())===1,'reused old address is not hijacked');
reset_fixture();$old=$GLOBALS['terms'][3]->slug;$before=$GLOBALS['links'];$r=api('slug',['id'=>3,'old_slug'=>$old,'slug'=>'bezpilotnyk']);check($r->ok&&$before===$GLOBALS['links'],'slug change preserves all relationships');
check(count(active_rules())===1,'slug redirect activates');
$_POST=['batch'=>$r->data['batch']??array_key_first(get_option('ogo_tm_slug_jobs_v37')),'id'=>3,'confirmed'=>'1'];call_private('slug_undo');check($GLOBALS['terms'][3]->slug===$old&&!active_rules(),'slug undo disables obsolete redirect');
$r=api('slug',['id'=>3,'old_slug'=>$old,'slug'=>'occupied']);check(!$r->ok&&$GLOBALS['terms'][3]->slug===$old,'occupied slug is rejected without mutation');
$r=api('slug',['id'=>3,'old_slug'=>'stale','slug'=>'new']);check(!$r->ok,'stale slug preview is rejected');
$r=api('slug',['id'=>3,'old_slug'=>$old,'slug'=>'']);check(!$r->ok,'empty slug is rejected');
$r=api('slug',['id'=>3,'old_slug'=>$old,'slug'=>'кирилиця']);check(!$r->ok,'non-Latin replacement is rejected');
reset_fixture();$p=api('preview',['source'=>5681,'target'=>7058]);$GLOBALS['links'][5681][]=99;$before=$GLOBALS['links'];$r=api('merge',['source'=>5681,'target'=>7058,'hash'=>$p->data['hash']]);check(!$r->ok&&$before===$GLOBALS['links'],'stale merge preview cannot write');
reset_fixture();$GLOBALS['fail_save']='ogo_tm_redirects_v38';$before=$GLOBALS['links'];$r=merge_pair();check(!$r->ok&&$before===$GLOBALS['links'],'redirect persistence failure prevents relationship mutation');
reset_fixture();$GLOBALS['fail_save']=OGO_Tag_Manager::STAGED;$before=$GLOBALS['links'];$r=merge_pair();check(!$r->ok&&$before===$GLOBALS['links']&&!active_rules(),'snapshot persistence failure prevents relationship mutation');
reset_fixture();$GLOBALS['fail_update']=true;$old=$GLOBALS['terms'][3]->slug;$r=api('slug',['id'=>3,'old_slug'=>$old,'slug'=>'bezpilotnyk']);check(!$r->ok&&$GLOBALS['terms'][3]->slug===$old&&!active_rules(),'failed term update leaves old URL active');
reset_fixture();$GLOBALS['links'][5681]=[11,12,15];$GLOBALS['fail_add']=15;$before=$GLOBALS['links'];$r=merge_pair();check(!$r->ok&&$GLOBALS['links']===$before&&!active_rules(),'failed merge rolls back only newly added links');
reset_fixture();$GLOBALS['links'][5681]=[11,12,15];$GLOBALS['fail_add']=15;$GLOBALS['fail_rollback']=true;$r=merge_pair();$staged=get_option(OGO_Tag_Manager::STAGED);check(!$r->ok&&$staged[5681]['state']==='needs_review'&&!active_rules(),'rollback failure retains recovery snapshot and disables redirect');
reset_fixture();$GLOBALS['options'][OGO_Tag_Manager::QUEUE]=[5681=>['kind'=>'merge','target'=>3]];$r=api('preview',['source'=>5681,'target'=>7058]);check(!$r->ok,'existing queue decision cannot be silently overwritten');
reset_fixture();$r=api('preview',['source'=>5681,'target'=>5681]);check(!$r->ok,'self merge rejected');
reset_fixture();$GLOBALS['links'][5681]=range(1,502);$r=api('preview',['source'=>5681,'target'=>4]);check(!$r->ok,'large merge is directed to advanced workflow');
reset_fixture();$GLOBALS['authorized']=false;$r=api('slugs');check(!$r->ok,'admin capability enforced');
reset_fixture();$_POST=['op'=>'slugs','nonce'=>'wrong'];try{OGO_Tag_Manager::simple_api();check(false,'nonce');}catch(Exception $e){check($e->getMessage()==='bad nonce','nonce enforced');}
reset_fixture();$lock=call_private('workbench_lock',900);$r=api('slug',['id'=>3,'old_slug'=>'безпілотник','slug'=>'bezpilotnyk']);check(!$r->ok&&isset($GLOBALS['options']['ogo_tm_write_lock_v37']),'concurrent writer blocked without releasing another owner lock');call_private('workbench_unlock',$lock);
reset_fixture();merge_pair();$GLOBALS['links'][5681][]=99;$_POST=['id'=>5681];call_private('workbench_verify');check(!active_rules(),'failed re-verification disables merge redirect');
reset_fixture();merge_pair();$GLOBALS['terms'][7058]->slug='advokaty';$r=merge_pair(7058,4);check($r->ok&&count(array_unique(active_rules()))===1,'subsequent merge resolves old URLs without a redirect chain');
reset_fixture();merge_pair();$GLOBALS['options'][OGO_Tag_Manager::STAGED]=[];check(!active_rules(),'merge undo removes redirect activation');
reset_fixture();call_private('simple_rule','https://ogo.test/tag/old/',3,'slug','before');$GLOBALS['options'][OGO_Tag_Manager::STAGED]=[3=>['state'=>'staged','target'=>4],4=>['state'=>'staged','target'=>3]];check(!active_rules(),'redirect resolver rejects merge cycles');
echo "ALL $passed CHECKS PASSED\n";
