<?php
if (!defined('ABSPATH')) exit;
/** Read-only Redirection integration. Never creates/imports/deletes external rules. */
trait OGO_Tag_Pending {
 private static function pending_checks(){ $v=get_option('ogo_tm_redirect_checks_v311',[]);return is_array($v)?$v:[]; }
 private static function pending_group(){return absint(get_option('ogo_tm_redirection_group_v311',0));}
 private static function pending_signature($rule,$url){return hash('sha256',wp_json_encode([$rule,$url,self::pending_group()]));}
 private static function pending_absolute($url){
  if(!is_string($url)||$url==='')return '';
  if($url[0]==='/'&&substr($url,0,2)!=='//'){
   $home=wp_parse_url(home_url());return ($home['scheme']??'https').'://'.$home['host'].(isset($home['port'])?':'.$home['port']:'').$url;
  }return $url;
 }
 private static function pending_equal($a,$b){
  $a=wp_parse_url(self::pending_absolute($a));$b=wp_parse_url(self::pending_absolute($b));
  if(!is_array($a)||!is_array($b))return false;
  foreach(['scheme','host','port','query','fragment'] as $key)if(($a[$key]??'')!==($b[$key]??''))return false;
  return self::simple_path($a['path']??'')===self::simple_path($b['path']??'');
 }
 private static function pending_red_match($rule,$target){
  if(!class_exists('Red_Item')||!class_exists('Red_Group')||!method_exists('Red_Item','get_for_url'))return null;
  if(function_exists('red_is_disabled')&&red_is_disabled())return null;
  $items=Red_Item::get_for_url($rule['path']);if(!is_array($items))throw new RuntimeException('Невідомий формат відповіді Redirection.');
  foreach($items as $item){
   if(!is_object($item)||!method_exists($item,'to_json'))continue;$data=$item->to_json();
   $enabled=isset($data['enabled'])?(bool)$data['enabled']:(($data['status']??'')==='enabled');
   if(!$enabled||!empty($data['regex'])||($data['match_type']??'')!=='url'||($data['action_type']??'')!=='url'||(int)($data['action_code']??0)!==301)continue;
   if(!self::pending_equal((string)($data['url']??''),self::pending_absolute($rule['path'])))continue;
   $to=is_array($data['action_data']??null)?($data['action_data']['url']??''):($data['action_data']??'');
   if(!self::pending_equal($to,$target))continue;
   $gid=(int)($data['group_id']??0);if(!$gid||(self::pending_group()&&$gid!==self::pending_group()))continue;
   $group=Red_Group::get($gid,true);
   if(!$group||!method_exists($group,'is_enabled')||!$group->is_enabled())continue;
   return ['id'=>(int)$data['id'],'group_id'=>$gid];
  }return null;
 }
 private static function pending_done($key,$rule,$url){
  $check=self::pending_checks()[$key]??null;
  if(!$check||empty($check['ok'])||!hash_equals((string)$check['signature'],self::pending_signature($rule,$url)))return false;
  try{$match=self::pending_red_match($rule,$url);return $match&&(int)$match['id']===(int)$check['redirection_id'];}catch(Throwable $e){return false;}
 }
 private static function pending_rules(){
  $out=[];foreach(self::simple_rules() as $key=>$rule){$url=self::simple_destination($rule);if(!$url||self::pending_done($key,$rule,$url))continue;
   $check=self::pending_checks()[$key]??null;
   $reason=$check&&($check['signature']??'')===self::pending_signature($rule,$url)?(!empty($check['ok'])?'Раніше перевірено, але правило Redirection зараз відсутнє, змінене або вимкнене. Перевірте повторно.':$check['result']):'Ще не перевірено в Redirection.';
   $out[$key]=['key'=>$key,'source'=>$rule['path'],'target'=>$url,'result'=>$reason];
  }return $out;
 }
 private static function pending_probe($path){$at=(string)time();return $at.'.'.wp_hash('ogo_tm_probe|'.$at.'|'.$path);}
 private static function pending_probe_valid($path){
  $token=(string)($_SERVER['HTTP_X_OGO_REDIRECT_PROBE']??'');$parts=explode('.',$token,2);
  if(count($parts)!==2||!ctype_digit($parts[0])||abs(time()-(int)$parts[0])>60)return false;
  return hash_equals(wp_hash('ogo_tm_probe|'.$parts[0].'|'.$path),$parts[1]);
 }
 private static function pending_verify(){
  $key=sanitize_text_field(wp_unslash($_POST['key']??''));$rule=self::simple_rules()[$key]??null;
  if(!$rule)throw new RuntimeException('Правило не знайдено.');$target=self::simple_destination($rule);
  if(!$target)throw new RuntimeException('Внутрішнє правило вже не активне. Оновіть список.');
  $signature=self::pending_signature($rule,$target);$match=null;$ok=false;
  try{
   $match=self::pending_red_match($rule,$target);
   if(!$match)throw new RuntimeException('У вибраній групі Redirection немає відповідного активного прямого правила 301. Імпортуйте CSV або виправте правило.');
   $source=self::pending_absolute($rule['path']);
   if(wp_parse_url($source,PHP_URL_HOST)!==wp_parse_url(home_url(),PHP_URL_HOST))throw new RuntimeException('Перевіряються лише адреси цього сайту.');
   $old=wp_safe_remote_get($source,['timeout'=>8,'redirection'=>0,'limit_response_size'=>1024,'headers'=>['X-OGO-Redirect-Probe'=>self::pending_probe($rule['path']),'Cache-Control'=>'no-cache, no-store','Pragma'=>'no-cache']]);
   if(is_wp_error($old))throw new RuntimeException('Старий URL: '.$old->get_error_message());
   $code=(int)wp_remote_retrieve_response_code($old);$location=(string)wp_remote_retrieve_header($old,'location');
   $agent=(string)wp_remote_retrieve_header($old,'x-redirect-by');
   if(stripos($agent,'OGO Tag Manager')!==false)throw new RuntimeException('Відповідь надав OGO Tag Manager або його кеш. Перевірка Redirection не зарахована. Очистіть кеш і повторіть.');
   $provider=strtolower(trim($agent.' '.(string)wp_remote_retrieve_header($old,'x-redirect-agent')));
   if(!preg_match('/(?:^|[\s,])redirection(?:$|[\s,])/',$provider))throw new RuntimeException('Сервер не підтвердив, що перенаправлення виконав Redirection. Правило залишено для перевірки; перевірте кеш і налаштування сервера.');
   if($code!==301||!self::pending_equal($location,$target))throw new RuntimeException('Старий URL: HTTP '.$code.'. Потрібен прямий 301 на '.$target);
   $new=wp_safe_remote_get($target,['timeout'=>8,'redirection'=>0,'limit_response_size'=>1024,'headers'=>['Cache-Control'=>'no-cache']]);
   if(is_wp_error($new))throw new RuntimeException('Ціль: '.$new->get_error_message());
   $newCode=(int)wp_remote_retrieve_response_code($new);if($newCode!==200)throw new RuntimeException('Ціль повернула HTTP '.$newCode.' замість 200.');
   $ok=true;$result='Перевірено: активне правило Redirection #'.$match['id'].', прямий 301 і HTTP 200 цілі.';
  }catch(Throwable $e){$result=$e->getMessage();}
  $checks=self::pending_checks();$checks[$key]=['signature'=>$signature,'ok'=>$ok,'at'=>current_time('mysql'),'redirection_id'=>$match?(int)$match['id']:0,'result'=>$result];
  self::simple_save('ogo_tm_redirect_checks_v311',$checks);
  return ['key'=>$key,'ok'=>$ok,'result'=>$result];
 }
 private static function pending_group_save(){
  $id=absint($_POST['group']??0);
  if($id){if(!class_exists('Red_Group'))throw new RuntimeException('Redirection не доступний.');$group=Red_Group::get($id,true);if(!$group||!$group->is_enabled())throw new RuntimeException('Група відсутня або вимкнена.');}
  self::simple_save('ogo_tm_redirection_group_v311',$id);return ['message'=>'Групу збережено. Правила перевірятимуться у вибраній групі.'];
 }
 private static function pending_page(){
  $pending=self::pending_rules();
  echo '<h2 id="ogo-pending-heading">4. Неперевірені редиректи</h2><p>Експортуйте ці правила в CSV та імпортуйте у свою групу Redirection. Потім натисніть «Перевірити редиректи». Успішно перевірені правила зникнуть із цього списку та наступного експорту.</p><p><label>Група Redirection: <select id="ogo-pending-group"><option value="0">Усі активні групи</option>';
  if(class_exists('Red_Group')&&method_exists('Red_Group','get_all'))foreach(Red_Group::get_all() as $group){
   if(!is_array($group)||empty($group['enabled']))continue;
   echo '<option value="'.(int)$group['id'].'"'.((int)$group['id']===self::pending_group()?' selected':'').'>'.esc_html($group['name']).'</option>';
  }
  echo '</select></label> <button type="button" class="button" id="ogo-pending-group-save">Зберегти групу</button></p><p><a class="button button-primary" id="ogo-pending-csv" href="'.esc_url(self::export_link('pending')).'"'.(!$pending?' hidden':'').'>Експортувати неперевірені в CSV ('.count($pending).')</a> <button type="button" class="button" id="ogo-pending-check">Перевірити редиректи</button> <button type="button" class="button" id="ogo-pending-refresh">Оновити список</button> <button type="button" class="button" id="ogo-pending-stop" hidden>Зупинити після поточного</button></p><p>За наявності вибраних рядків перевіряються вони; без вибору — усі неперевірені. Завантаження CSV саме по собі нічого не приховує. Тимчасовий 301 OGO залишається страховкою до успішної перевірки.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" id="ogo-export-selected"><input type="hidden" name="action" value="ogo_tm_simple_csv"><input type="hidden" name="scope" value="selected">';wp_nonce_field('ogo_tm_simple_csv');
  echo '<div id="ogo-pending-list"><table class="widefat striped"><thead><tr><th>Вибір</th><th>Старий URL</th><th>Ціль</th><th>Перевірка</th></tr></thead><tbody>';
  foreach($pending as $item)echo '<tr data-pending-key="'.esc_attr($item['key']).'"><td><input type="checkbox" name="keys[]" value="'.esc_attr($item['key']).'" aria-label="Вибрати '.esc_attr(rawurldecode($item['source'])).'"></td><td>'.esc_html(rawurldecode($item['source'])).'</td><td>'.esc_html($item['target']).'</td><td>'.esc_html($item['result']).'</td></tr>';
  if(!$pending)echo '<tr><td colspan="4">Неперевірених редиректів немає.</td></tr>';
  echo '</tbody></table></div><p id="ogo-pending-count">Неперевірених: '.count($pending).'</p><p><button type="submit" class="button" data-ogo-pending-selected'.(!$pending?' hidden':'').'>CSV вибраних неперевірених</button></p></form><div id="ogo-pending-progress" role="status" aria-live="polite"></div>';
 }
}
