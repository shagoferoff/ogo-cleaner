<?php
// Simulated Redirection API for isolated localhost tests only.
class Red_Item {
 private $data;function __construct($data){$this->data=$data;}
 static function get_for_url($url){return array_map(fn($row)=>new self($row),array_values($GLOBALS['mock_red_rows']??[]));}
 function to_json(){return $this->data;}
}
class Red_Group {
 private $id;function __construct($id){$this->id=$id;}
 static function get($id,$clear=false){return isset($GLOBALS['mock_red_groups'][$id])?new self($id):false;}
 function is_enabled(){return !empty($GLOBALS['mock_red_groups'][$this->id]['enabled']);}
 static function get_all(){return array_values($GLOBALS['mock_red_groups']??[]);}
}
function red_is_disabled(){return $GLOBALS['mock_red_disabled']??false;}
function wp_safe_remote_get($url,$args){$GLOBALS['http_calls'][]=[$url,$args];return $GLOBALS['mock_http'][$url]??new WP_Error;}
function wp_remote_retrieve_response_code($r){return $r['response']['code']??0;}
function wp_remote_retrieve_header($r,$key){return $r['headers'][strtolower($key)]??'';}
function fresh_pending(){reset_fixture();$GLOBALS['mock_red_rows']=[];$GLOBALS['mock_red_groups']=[7=>['id'=>7,'name'=>'OGO Tags','enabled'=>true],8=>['id'=>8,'name'=>'Other','enabled'=>true]];$GLOBALS['mock_http']=[];$GLOBALS['http_calls']=[];unset($GLOBALS['mock_red_disabled']);$_SERVER['HTTP_X_OGO_REDIRECT_PROBE']='';}
function enable_rule($key,$redId=99){
 $rule=call_private('simple_rules')[$key];$target=call_private('simple_destination',$rule);
 $GLOBALS['mock_red_rows'][$redId]=['id'=>$redId,'url'=>$rule['path'],'action_data'=>['url'=>$target],'enabled'=>true,'regex'=>false,'match_type'=>'url','action_type'=>'url','action_code'=>301,'group_id'=>7];
 $GLOBALS['mock_http'][call_private('pending_absolute',$rule['path'])]=['response'=>['code'=>301],'headers'=>['location'=>$target,'x-redirect-by'=>'Redirection']];
 $GLOBALS['mock_http'][$target]=['response'=>['code'=>200],'headers'=>[]];return [$rule,$target];
}
function setup_one(){fresh_pending();merge_pair();return array_key_first(call_private('simple_rules'));}
