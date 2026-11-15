<?php
if (!defined('ABSPATH')) exit;

/** A user action owns an explicit set of redirect sources, never the whole registry. */
trait OGO_Tag_Exports {
 private static function export_batches(){
  $batches=get_option('ogo_tm_export_batches_v310',[]);return is_array($batches)?$batches:[];
 }
 private static function export_batch($id){
  $batches=self::export_batches();$batch=$batches[$id]??null;
  if(!$batch||(int)$batch['user']!==(int)get_current_user_id())throw new RuntimeException('Пакет CSV не знайдено або належить іншому користувачу.');
  return $batch;
 }
 private static function export_start(){
  $kind=sanitize_key(wp_unslash($_POST['kind']??''));
  if(!in_array($kind,['merge','slug','mixed'],true))throw new RuntimeException('Некоректний тип пакета CSV.');
  $id=wp_generate_uuid4();$batches=self::export_batches();
  $batches[$id]=['user'=>get_current_user_id(),'kind'=>$kind,'at'=>current_time('mysql'),'rules'=>[]];
  self::simple_save('ogo_tm_export_batches_v310',$batches);
  return self::export_info($id);
 }
 private static function export_validate_request($kind){
  $id=sanitize_text_field(wp_unslash($_POST['export_batch']??''));if(!$id)return;
  $batch=self::export_batch($id);
  if($batch['kind']!==$kind&&$batch['kind']!=='mixed')throw new RuntimeException('Пакет CSV має інший тип операції.');
 }
 private static function export_capture($key,$rule,$previous){
  $id=sanitize_text_field(wp_unslash($_POST['export_batch']??''));if(!$id)return;
  $batch=self::export_batch($id);
  if($batch['kind']!==$rule['kind']&&$batch['kind']!=='mixed')throw new RuntimeException('Пакет CSV не відповідає правилу.');
  if(isset($batch['rules'][$key])){
   if($batch['rules'][$key]!==$rule)throw new RuntimeException('Адреса вже має інше правило в цьому пакеті.');
   return;
  }
  // Retrying a completed migration does not make its existing rule new again.
  if($previous===$rule&&self::simple_destination($previous))return;
  $batches=self::export_batches();
  foreach($batches as $other=>$old)if($other!==$id&&isset($old['rules'][$key])&&$old['rules'][$key]===$rule)unset($batches[$other]['rules'][$key]);
  $batches[$id]['rules'][$key]=$rule;
  // Persist membership before the rule/term write, so an interrupted request is recoverable.
  self::simple_save('ogo_tm_export_batches_v310',$batches);
 }
 private static function export_batch_rows($id){
  $batch=self::export_batch($id);$rules=self::simple_rules();$rows=[];$excluded=0;
  foreach($batch['rules'] as $key=>$snapshot){
   if(!isset($rules[$key])||$rules[$key]!==$snapshot){$excluded++;continue;}
   $url=self::simple_destination($rules[$key]);if(!$url||self::pending_done($key,$rules[$key],$url)){$excluded++;continue;}
   $rows[]=[$snapshot['path'],wp_parse_url($url,PHP_URL_PATH),'0','301'];
  }
  return ['rows'=>$rows,'excluded'=>$excluded];
 }
 private static function export_link($scope,$batch=''){
  $args=['action'=>'ogo_tm_simple_csv','scope'=>$scope];if($batch!=='')$args['batch']=$batch;
  return wp_nonce_url(add_query_arg($args,admin_url('admin-post.php')),'ogo_tm_simple_csv');
 }
 private static function export_info($id){
  $data=self::export_batch_rows($id);
  return ['export_batch'=>$id,'csv'=>self::export_link('batch',$id),'count'=>count($data['rows']),'excluded'=>$data['excluded']];
 }
 private static function export_latest(){
  foreach(array_reverse(self::export_batches(),true) as $id=>$batch)if((int)$batch['user']===(int)get_current_user_id())return $id;
  return '';
 }
 private static function export_csv_rows($scope,$id='',$keys=[]){
  if($scope==='pending'){$rows=[];foreach(self::pending_rules() as $item)$rows[]=[$item['source'],wp_parse_url($item['target'],PHP_URL_PATH),'0','301'];return $rows;}
  if($scope==='latest'){$id=self::export_latest();if(!$id)throw new RuntimeException('Ще немає пакетів CSV. Для попередніх змін виберіть потрібні рядки у списку редиректів.');$scope='batch';}
  if($scope==='batch')return self::export_batch_rows($id)['rows'];
  if(!in_array($scope,['all','selected'],true))throw new RuntimeException('Невідомий режим експорту CSV.');
  $rules=self::simple_rules();
  if($scope==='selected'){
   if(!$keys)throw new RuntimeException('Виберіть редиректи, які потрібно експортувати.');
   $selected=[];foreach(array_unique($keys) as $key){
    if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!isset($rules[$key]))throw new RuntimeException('Некоректний рядок експорту. Оновіть список.');
    $selected[$key]=$rules[$key];
   }$rules=$selected;
  }
  $rows=[];foreach($rules as $key=>$rule){$url=self::simple_destination($rule);
   if($scope==='selected'&&$url&&self::pending_done($key,$rule,$url))continue;
   if(!$url){if($scope==='selected')throw new RuntimeException('Вибране правило вже не активне. Оновіть список.');continue;}
   $rows[]=[$rule['path'],wp_parse_url($url,PHP_URL_PATH),'0','301'];
  }return $rows;
 }
 public static function simple_csv(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tm_simple_csv');
  $scope=sanitize_key(wp_unslash($_POST['scope']??$_GET['scope']??'latest'));
  $id=sanitize_text_field(wp_unslash($_POST['batch']??$_GET['batch']??''));
  try{$rows=self::export_csv_rows($scope,$id,(array)wp_unslash($_POST['keys']??[]));}
  catch(Throwable $e){wp_die(esc_html($e->getMessage()));return;}
  if(!$rows&&$scope!=='all')wp_die('У цьому пакеті немає нових активних редиректів для експорту.');
  if($scope==='latest')$id=self::export_latest();
  $suffix=in_array($scope,['latest','batch'],true)?'batch-'.substr($id,0,8):$scope;
  while(ob_get_level())ob_end_clean();nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ogo-tag-redirects-'.$suffix.'.csv"');
  $out=fopen('php://output','w');foreach($rows as $row)fputcsv($out,$row);fclose($out);exit;
 }
 private static function export_latest_button(){
  $id=self::export_latest();$info=$id?self::export_info($id):null;
  echo '<a class="button button-primary" data-ogo-export-latest href="'.esc_url($info?$info['csv']:'#').'"'.(!$info||!$info['count']?' hidden':'').'>CSV останнього пакета'.($info?' ('.$info['count'].')':'').'</a> ';
 }
 private static function export_history_page(){
  echo '<h2>Пакети CSV</h2><p>Кожен запуск об’єднання або зміни slug має окремий CSV: лише нові активні правила цього запуску. Неактивні правила й відкочені зміни не експортуються. Повторне завантаження того самого пакета дає той самий набір джерел; це не підтвердження імпорту в Redirection.</p><div id="ogo-export-current" aria-live="polite"></div>';
  $shown=0;foreach(array_reverse(self::export_batches(),true) as $id=>$batch){
   if((int)$batch['user']!==(int)get_current_user_id())continue;
   if($shown++>=20)break;$info=self::export_info($id);
   echo '<p>'.esc_html($batch['at'].' · '.($batch['kind']==='merge'?'Об’єднання':($batch['kind']==='mixed'?'Slug і дублікати':'Зміна slug')).' · редиректів: '.$info['count']);
   if($info['excluded'])echo ' · неактивних / змінених: '.$info['excluded'];
   if($info['count'])echo ' <a class="button" href="'.esc_url($info['csv']).'">CSV цього пакета ('.$info['count'].')</a>';
   echo '</p>';
  }
  if(!$shown)echo '<p>Для змін, зроблених до 3.10.0, виберіть потрібні рядки у списку нижче: попередні версії не зберігали групування запусків.</p>';
 }
 private static function export_rules_page(){
  echo '<h2>Список активних редиректів</h2><p>';self::export_latest_button();
  echo '<a class="button" href="'.esc_url(self::export_link('all')).'">Повний CSV усіх правил — резервна копія</a></p><p>Для вже виконаних змін можна вибрати тільки потрібні рядки й завантажити CSV вибраних. Повний CSV містить усі накопичені правила.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" id="ogo-export-selected"><input type="hidden" name="action" value="ogo_tm_simple_csv"><input type="hidden" name="scope" value="selected">';wp_nonce_field('ogo_tm_simple_csv');
  echo '<p><button class="button" type="submit">CSV вибраних редиректів</button></p><table class="widefat striped"><thead><tr><th>Вибір</th><th>Звідки</th><th>Куди (301)</th></tr></thead><tbody>';
  $count=0;foreach(self::simple_rules() as $key=>$rule){$url=self::simple_destination($rule);if(!$url)continue;$count++;
   echo '<tr><td><input type="checkbox" name="keys[]" value="'.esc_attr($key).'" aria-label="Вибрати '.esc_attr(rawurldecode($rule['path'])).'"></td><td>'.esc_html(rawurldecode($rule['path'])).'</td><td><a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($url).'</a></td></tr>';
  }
  if(!$count)echo '<tr><td colspan="3">Ще немає активних редиректів.</td></tr>';
  echo '</tbody></table><p><button class="button" type="submit">CSV вибраних редиректів</button></p></form>';
 }
}
