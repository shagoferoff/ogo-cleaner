<?php
if (!defined('ABSPATH')) exit;

/** Additive v3.7 workflow. Existing queue, staged records and journal keys are unchanged. */
trait OGO_Tag_Workbench {
 private static function workbench_init(){
  add_action('admin_enqueue_scripts',[__CLASS__,'workbench_assets']);
  foreach(['review'=>'review','external_target'=>'external_target','suggested_group'=>'suggested_group','batch_queue'=>'batch_queue','pair'=>'pair_decision','verify'=>'verify_stage'] as $action=>$method)
   add_action('wp_ajax_ogo_tag_manager_'.$action,[__CLASS__,$method]);
  add_action('wp_ajax_ogo_tm_workbench',[__CLASS__,'workbench_api']);
  add_action('admin_post_ogo_tm_slug_csv',[__CLASS__,'slug_csv']);
  foreach(['review','external_target','suggested_group','batch_queue','pair','verify','verify_batch','stage','package_execute','undo_stage','cleanup_execute','snapshot','package_confirm'] as $action){
   add_action('admin_post_ogo_tag_manager_'.$action,[__CLASS__,'workbench_guard'],1);
   add_action('wp_ajax_ogo_tag_manager_'.$action,[__CLASS__,'workbench_guard'],1);
  }
 }
 public static function workbench_guard(){
  if(!current_user_can('manage_options'))return;
  try{$lock=self::workbench_lock(900);}catch(Throwable $e){if(wp_doing_ajax())wp_send_json_error(['message'=>$e->getMessage()],409);wp_die(esc_html($e->getMessage()));return;}
  register_shutdown_function(static function()use($lock){self::workbench_unlock($lock);});
 }
 public static function workbench_assets($hook){
  if($hook!=='tools_page_'.self::SLUG)return;
  wp_enqueue_script('ogo-tm-workbench',plugins_url('assets/workbench.js',dirname(__DIR__).'/ogo-tag-manager.php'),[], '3.7.0',true);
  wp_localize_script('ogo-tm-workbench','OGO_TM',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ogo_tm_workbench'),'findNonce'=>wp_create_nonce('ogo_tag_find_targets')]);
  wp_enqueue_style('ogo-tm-workbench',plugins_url('assets/workbench.css',dirname(__DIR__).'/ogo-tag-manager.php'),[], '3.7.0');
 }
 private static function return_filter(){
  $v=sanitize_key(wp_unslash($_POST['return_filter']??$_GET['filter']??'all'));
  return in_array($v,['all','pending','queued'],true)?$v:'all';
 }
 private static function finish_action($url){
  if(wp_doing_ajax())wp_send_json_success(['url'=>$url]);
  wp_safe_redirect($url);
 }
 private static function slug_jobs(){ $v=get_option('ogo_tm_slug_jobs_v37',[]);return is_array($v)?$v:[]; }
 private static function save_slug_jobs($jobs){
  if(!update_option('ogo_tm_slug_jobs_v37',$jobs,false)&&get_option('ogo_tm_slug_jobs_v37',null)!==$jobs)
   throw new RuntimeException('Не вдалося зберегти журнал slug. Виконання зупинено.');
 }
 /** Serialize new workflow writes. The conditional stale delete cannot remove a newer lock. */
 private static function workbench_lock($ttl=120){
  global $wpdb;
  $key='ogo_tm_write_lock_v37';$old=get_option($key);
  if(is_array($old)&&isset($old['until'])&&(int)$old['until']<time()){
   $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$key,maybe_serialize($old)));
   wp_cache_delete($key,'options');
  }
  $lock=['token'=>wp_generate_uuid4(),'until'=>time()+$ttl];
  if(!add_option($key,$lock,'',false))throw new RuntimeException('Інша операція ще виконується. Спробуйте пізніше.');
  return $lock;
 }
 private static function workbench_unlock($lock){
  if(get_option('ogo_tm_write_lock_v37')===$lock)delete_option('ogo_tm_write_lock_v37');
 }
 public static function workbench_api(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Недостатньо прав.'],403);
  check_ajax_referer('ogo_tm_workbench','nonce');
  $op=sanitize_key(wp_unslash($_POST['op']??''));$lock=null;
  try{
   if(in_array($op,['prepare','apply','undo','verify','url_check'],true))$lock=self::workbench_lock();
   switch($op){
    case 'prepare':$result=self::slug_prepare();break;
    case 'apply':$result=self::slug_apply();break;
    case 'undo':$result=self::slug_undo();break;
    case 'verify':$result=self::workbench_verify();break;
    case 'verify_list':
     $ids=[];foreach(self::staged() as $id=>$entry)if(($entry['state']??'')==='staged'&&get_term((int)$id,'post_tag')&&!is_wp_error(get_term((int)$id,'post_tag')))$ids[]=(int)$id;
     $result=['ids'=>$ids];break;
    case 'url_check':$result=self::slug_url_check();break;
    default:throw new RuntimeException('Невідома операція.');
   }
  }catch(Throwable $e){
   if($lock)self::workbench_unlock($lock);
   wp_send_json_error(['message'=>$e->getMessage()],400);
  }
  if($lock)self::workbench_unlock($lock);
  wp_send_json_success($result);
 }
 private static function slug_prepare(){
  $items=json_decode(wp_unslash($_POST['items']??'[]'),true);
  if(!is_array($items)||!$items||count($items)>50)throw new RuntimeException('Оберіть від 1 до 50 тегів.');
  $queue=self::queue();$staged=self::staged();$entries=[];$slugs=[];$ids=[];
  foreach($items as $item){
   $id=absint($item['id']??0);$term=get_term($id,'post_tag');
   $desired=trim((string)($item['slug']??''));$errors=[];$conflict=null;
   if(!$id||isset($ids[$id])||!$term||is_wp_error($term))throw new RuntimeException('Некоректний або повторний ID тегу.');
   $ids[$id]=true;
   if($desired===''||strlen($desired)>200||!preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D',$desired)||sanitize_title($desired)!==$desired)$errors[]='Введіть латинський slug: малі літери, цифри, дефіс або підкреслення між частинами.';
   if($desired===$term->slug)$errors[]='Slug уже має це значення.';
   if(isset($staged[$id])||isset($queue[$id])&&($queue[$id]['kind']??'')==='merge')$errors[]='Тег має заплановане або виконане перенесення. Спершу завершіть його.';
   foreach($queue as $sid=>$decision)if(($decision['kind']??'')==='merge'&&(int)($decision['target']??0)===$id){$errors[]='Тег є ціллю запланованого об’єднання.';break;}
   $existing=$desired!==''?get_term_by('slug',$desired,'post_tag'):false;
   if($existing&&(int)$existing->term_id!==$id){$errors[]='Slug зайнятий: '.$existing->name.' (#'.$existing->term_id.'). Перевірте об’єднання дублікатів.';$conflict=(int)$existing->term_id;}
   if(isset($slugs[$desired])){$errors[]='Однаковий новий slug у цьому пакеті.';$entries[$slugs[$desired]]['errors'][]='Однаковий новий slug у цьому пакеті.';$entries[$slugs[$desired]]['state']='blocked';}
   $url=get_term_link($term);if(is_wp_error($url))$errors[]='Не вдалося отримати старий URL.';
   $entries[$id]=['id'=>$id,'name'=>$term->name,'old_slug'=>$term->slug,'desired'=>$desired,'old_url'=>is_wp_error($url)?'':$url,'new_url'=>'','state'=>$errors?'blocked':'prepared','errors'=>$errors,'conflict'=>$conflict];
   $slugs[$desired]=$id;
  }
  $batch=wp_generate_uuid4();$jobs=self::slug_jobs();
  $jobs[$batch]=['at'=>current_time('mysql'),'user'=>get_current_user_id(),'items'=>$entries];
  self::save_slug_jobs($jobs);
  return ['batch'=>$batch,'items'=>array_values($entries),'csv'=>self::slug_csv_link($batch)];
 }
 private static function slug_request_entry(){
  $batch=sanitize_text_field(wp_unslash($_POST['batch']??''));$id=absint($_POST['id']??0);$jobs=self::slug_jobs();
  if(!$batch||!isset($jobs[$batch]['items'][$id]))throw new RuntimeException('Запис пакета не знайдено.');
  return [$jobs,$batch,$id,$jobs[$batch]['items'][$id]];
 }
 private static function slug_apply(){
  if(($_POST['confirmed']??'')!=='1')throw new RuntimeException('Підтвердіть показаний пакет.');
  [$jobs,$batch,$id,$entry]=self::slug_request_entry();
  if($entry['state']==='applied')return $entry; // A retry never writes the same operation twice.
  if($entry['state']!=='prepared')throw new RuntimeException('Запис заблоковано або вже оброблено.');
  $term=get_term($id,'post_tag');
  if(!$term||is_wp_error($term)||$term->slug!==$entry['old_slug']||get_term_link($term)!==$entry['old_url'])throw new RuntimeException('Тег або його URL змінилися після перевірки. Створіть новий пакет.');
  $queue=self::queue();$staged=self::staged();
  if(isset($staged[$id])||isset($queue[$id])&&($queue[$id]['kind']??'')==='merge')throw new RuntimeException('Тег уже бере участь у перенесенні.');
  foreach($queue as $decision)if(($decision['kind']??'')==='merge'&&(int)($decision['target']??0)===$id)throw new RuntimeException('Тег став ціллю об’єднання.');
  $duplicate=get_term_by('slug',$entry['desired'],'post_tag');
  if($duplicate&&(int)$duplicate->term_id!==$id)throw new RuntimeException('Новий slug уже зайнятий. Потрібна повторна перевірка.');
  // Persist intention before touching a term. An interrupted write remains visible for review.
  $jobs[$batch]['items'][$id]['state']='running';self::save_slug_jobs($jobs);
  $result=wp_update_term($id,'post_tag',['slug'=>$entry['desired']]);
  if(is_wp_error($result)){
   $jobs[$batch]['items'][$id]['state']='prepared';self::save_slug_jobs($jobs);
   throw new RuntimeException($result->get_error_message());
  }
  $fresh=get_term($id,'post_tag');$new=$fresh&&!is_wp_error($fresh)?get_term_link($fresh):new WP_Error('missing','Тег не знайдено.');
  $entry['new_slug']=$fresh&&!is_wp_error($fresh)?$fresh->slug:'';
  $entry['new_url']=is_wp_error($new)?'':$new;
  $entry['state']=$entry['new_slug']===$entry['desired']&&$entry['new_url']!==''&&$entry['new_url']!==$entry['old_url']?'applied':'needs_review';
  $entry['applied_at']=current_time('mysql');$jobs[$batch]['items'][$id]=$entry;self::save_slug_jobs($jobs);
  self::journal('slug_change',$id,$id,['batch'=>$batch,'old_url'=>$entry['old_url'],'new_url'=>$entry['new_url'],'state'=>$entry['state']]);
  if($entry['state']!=='applied')throw new RuntimeException('Результат зміни відрізняється від плану. Перевірте журнал; CSV для цього рядка заблоковано.');
  if(isset($queue[$id])&&($queue[$id]['kind']??'')==='slug'){unset($queue[$id]);update_option(self::QUEUE,$queue,false);}
  return $entry;
 }
 private static function slug_undo(){
  if(($_POST['confirmed']??'')!=='1')throw new RuntimeException('Потрібне підтвердження відкату.');
  [$jobs,$batch,$id,$entry]=self::slug_request_entry();
  if($entry['state']!=='applied')throw new RuntimeException('Цю зміну не можна відкотити автоматично.');
  $term=get_term($id,'post_tag');$other=get_term_by('slug',$entry['old_slug'],'post_tag');
  if(!$term||is_wp_error($term)||$term->slug!==$entry['new_slug']||get_term_link($term)!==$entry['new_url']||$other&&(int)$other->term_id!==$id)throw new RuntimeException('Поточний стан змінився або старий slug зайнятий.');
  $q=self::queue();foreach($q as $sid=>$d)if(($d['kind']??'')==='merge'&&((int)$sid===$id||(int)($d['target']??0)===$id))throw new RuntimeException('Тег бере участь в об’єднанні.');
  foreach(self::staged() as $sid=>$d)if((int)$sid===$id||(int)($d['target']??0)===$id)throw new RuntimeException('Тег має запис про перенесення.');
  $jobs[$batch]['items'][$id]['state']='undo_running';self::save_slug_jobs($jobs);
  $r=wp_update_term($id,'post_tag',['slug'=>$entry['old_slug']]);
  if(is_wp_error($r)){$jobs[$batch]['items'][$id]['state']='applied';self::save_slug_jobs($jobs);throw new RuntimeException($r->get_error_message());}
  $fresh=get_term($id,'post_tag');
  $entry['state']=$fresh&&!is_wp_error($fresh)&&$fresh->slug===$entry['old_slug']?'undone':'needs_review';
  $entry['undo_at']=current_time('mysql');$jobs[$batch]['items'][$id]=$entry;self::save_slug_jobs($jobs);
  self::journal('slug_undo',$id,$id,['batch'=>$batch,'state'=>$entry['state']]);
  return $entry;
 }
 private static function workbench_verify(){
  $source=absint($_POST['id']??0);$staged=self::staged();$entry=$staged[$source]??null;
  if(!$entry||($entry['state']??'')!=='staged')throw new RuntimeException('Немає завершеного перенесення.');
  $target=(int)$entry['target'];$a=self::source_objects($source);$b=self::source_objects($target);
  if($a===null||$b===null){$ok=false;$result='Помилка читання зв’язків.';}
  else{
   $missing=count(array_diff($a,$b));$lost=count(array_diff(array_map('intval',$entry['objects']??[]),$a));$not_added=count(array_diff(array_map('intval',$entry['added']??[]),$b));
   $ok=!$missing&&!$lost&&!$not_added;
   $result=($ok?'OK: зв’язки збережені.':'УВАГА: виявлено відмінності.').' Джерело: '.count($a).'; ціль: '.count($b).'; відсутні в цілі: '.$missing.'; втрачені з джерела: '.$lost.'; додані модулем відсутні: '.$not_added.'.';
  }
  $staged[$source]['last_verify']=['at'=>current_time('mysql'),'result'=>$result,'hash'=>self::fingerprint($source,$target)];
  update_option(self::STAGED,$staged,false);self::journal('verify',$source,$target,['result'=>$result]);
  return ['id'=>$source,'ok'=>$ok,'result'=>$result];
 }
 private static function slug_csv_link($batch){return wp_nonce_url(add_query_arg(['action'=>'ogo_tm_slug_csv','batch'=>$batch],admin_url('admin-post.php')),'ogo_tm_slug_csv');}
 public static function slug_csv(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tm_slug_csv');
  $batch=sanitize_text_field(wp_unslash($_GET['batch']??''));$jobs=self::slug_jobs();$items=$jobs[$batch]['items']??[];$rows=[];$seen=[];
  foreach($items as $id=>$entry){
   if($entry['state']!=='applied')continue;
   $term=get_term((int)$id,'post_tag');
   if(!$term||is_wp_error($term)||$term->slug!==($entry['new_slug']??'')||get_term_link($term)!==$entry['new_url'])wp_die('Тег #'.(int)$id.' змінився після виконання. CSV заблоковано; перевірте журнал.');
   $old=wp_parse_url($entry['old_url'],PHP_URL_PATH);$new=wp_parse_url($entry['new_url'],PHP_URL_PATH);
   if(!is_string($old)||!is_string($new)||$old===''||$new===''||$old[0]!=='/'||$new[0]!=='/'||$old===$new)wp_die('Некоректні URL.');
   if(isset($seen[$old])&&$seen[$old]!==$new)wp_die('Конфлікт редиректів.');
   if(!isset($seen[$old])){$rows[]=[$old,$new,'0','301'];$seen[$old]=$new;}
  }
  if(!$rows)wp_die('У пакеті немає виконаних змін для експорту.');
  while(ob_get_level())ob_end_clean();nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ogo-tag-slugs-'.substr($batch,0,8).'.csv"');
  $out=fopen('php://output','w');foreach($rows as $row)fputcsv($out,$row);fclose($out);exit;
 }
 private static function slug_url_check(){
  [$jobs,$batch,$id,$entry]=self::slug_request_entry();
  if($entry['state']!=='applied')throw new RuntimeException('Спершу виконайте зміну slug.');
  $host=wp_parse_url(home_url(),PHP_URL_HOST);
  foreach(['old_url','new_url'] as $key)if(wp_parse_url($entry[$key],PHP_URL_HOST)!==$host)throw new RuntimeException('Дозволена перевірка лише адрес цього сайту.');
  $old=wp_safe_remote_get($entry['old_url'],['timeout'=>8,'redirection'=>0,'limit_response_size'=>1024]);
  if(is_wp_error($old))throw new RuntimeException('Старий URL: '.$old->get_error_message());
  $code=(int)wp_remote_retrieve_response_code($old);$location=wp_remote_retrieve_header($old,'location');
  $resolved=WP_Http::make_absolute_url((string)$location,$entry['old_url']);
  $new=wp_safe_remote_get($entry['new_url'],['timeout'=>8,'redirection'=>0,'limit_response_size'=>1024]);
  if(is_wp_error($new))throw new RuntimeException('Новий URL: '.$new->get_error_message());
  $new_code=(int)wp_remote_retrieve_response_code($new);
  $same=rawurldecode($resolved)===rawurldecode($entry['new_url']);$ok=$code===301&&$same&&$new_code===200;
  $result=['id'=>$id,'ok'=>$ok,'result'=>($ok?'OK: ':'Потрібна увага: ').'старий URL — HTTP '.$code.'; ціль — HTTP '.$new_code.'; прямий 301 на потрібну адресу — '.($code===301&&$same?'так':'ні').'.'];
  $jobs[$batch]['items'][$id]['url_check']=['at'=>current_time('mysql'),'ok'=>$ok,'result'=>$result['result']];self::save_slug_jobs($jobs);
  return $result;
 }
 private static function verify_toolbar(){
  echo '<div class="ogo-toolbar"><strong>Повторна перевірка зв’язків:</strong> <button type="button" class="button" data-ogo-verify="selected">Перевірити вибрані</button> <button type="button" class="button" data-ogo-verify="all">Перевірити всі перенесення</button> <button type="button" class="button ogo-stop" hidden>Зупинити після поточного</button><div class="ogo-progress" role="status" aria-live="polite"></div></div>';
 }
 private static function slug_page($bad,$queue){
  echo '<h2>Перевірка та зміна slug</h2><p>Кирилиця та інші символи позначені для перевірки формату. Це не означає, що адреса не працює. Пропозицію можна виправити. Якщо slug зайнятий іншим тегом, перевірте об’єднання.</p>';
  echo '<div class="ogo-toolbar"><button type="button" class="button" data-ogo-slug-select="all">Вибрати всі на сторінці</button> <button type="button" class="button" data-ogo-slug-select="none">Зняти вибір</button> <button type="button" class="button button-primary" id="ogo-slug-prepare">Перевірити вибрані</button></div>';
  $visible=self::paged($bad);
  echo '<table class="widefat striped"><thead><tr><th>Вибір</th><th>Тег / ID</th><th>Поточний slug</th><th>Новий slug</th><th>Статей</th><th>Стан</th></tr></thead><tbody>';
  foreach($visible as $r){$id=(int)$r['term_id'];$blocked=isset(self::staged()[$id])||isset($queue[$id])&&($queue[$id]['kind']??'')==='merge';
   echo '<tr class="ogo-slug-row" data-ogo-row="'.$id.'"><td><input type="checkbox" class="ogo-slug-choice" value="'.$id.'"'.($blocked?' disabled':'').' aria-label="Вибрати тег '.esc_attr($r['name']).'"></td><td>'.esc_html($r['name']).' <small>#'.$id.'</small></td><td><code>'.esc_html($r['slug']).'</code></td><td><input class="ogo-slug-input" value="'.esc_attr(self::suggestion(rawurldecode($r['slug']))).'" aria-label="Новий slug для '.esc_attr($r['name']).'"'.($blocked?' disabled':'').'></td><td>'.(int)$r['post_count'].'</td><td class="ogo-slug-status">'.($blocked?'Спершу завершіть перенесення':esc_html(self::status($queue[$id]??null))).'</td></tr>';
  }
  if(!$visible)echo '<tr><td colspan="6">Немає slug, позначених для перевірки.</td></tr>';
  echo '</tbody></table><div id="ogo-slug-preview" aria-live="polite"></div>';
  $jobs=array_reverse(self::slug_jobs(),true);
  echo '<h2>Пакети змін slug</h2><p>CSV містить лише успішно змінені адреси вибраного пакета. Імпортуйте його в Redirection, потім перевірте URL. Після відкату потрібно окремо прибрати або змінити імпортоване правило.</p>';
  if(!$jobs)echo '<p>Ще немає пакетів.</p>';
  foreach($jobs as $batch=>$job){
   $applied=0;$pending=0;foreach($job['items'] as $entry){$applied+=(int)($entry['state']==='applied');$pending+=(int)($entry['state']==='prepared');}
   echo '<details class="ogo-slug-job" data-batch="'.esc_attr($batch).'"><summary>'.esc_html($job['at']).' · '.count($job['items']).' тегів · виконано '.$applied.' · очікує '.$pending.'</summary><p>';
   if($applied)echo '<a class="button" href="'.esc_url(self::slug_csv_link($batch)).'">CSV для Redirection: '.$applied.' змін</a> <button type="button" class="button" data-ogo-check-job="'.esc_attr($batch).'">Перевірити URL пакета</button> ';
   if($pending)echo '<button type="button" class="button" data-ogo-resume="'.esc_attr($batch).'">Переглянути та продовжити</button> ';
   echo '<button type="button" class="button ogo-stop" hidden>Зупинити після поточного</button></p><div class="ogo-progress" role="status" aria-live="polite"></div><table class="widefat striped"><thead><tr><th>Тег</th><th>Стара адреса / slug</th><th>Нова адреса / slug</th><th>Стан / дії</th></tr></thead><tbody>';
   $states=['prepared'=>'Підготовлено','blocked'=>'Заблоковано','running'=>'Перервано під час запису — перевірте вручну','applied'=>'Змінено; потрібен імпорт / перевірка 301','undone'=>'Відкочено','undo_running'=>'Перервано під час відкату — перевірте вручну','needs_review'=>'Потрібна ручна перевірка'];
   foreach($job['items'] as $id=>$entry){
    echo '<tr data-job-id="'.(int)$id.'" data-state="'.esc_attr($entry['state']).'" data-name="'.esc_attr($entry['name']).'" data-desired="'.esc_attr($entry['desired']).'"><td>'.esc_html($entry['name']).' #'.(int)$id.'</td><td><code>'.esc_html($entry['old_url']?:$entry['old_slug']).'</code></td><td><code>'.esc_html($entry['new_url']?:$entry['desired']).'</code></td><td class="ogo-job-status">'.esc_html($states[$entry['state']]??$entry['state']);
    foreach($entry['errors'] as $error)echo '<div>'.esc_html($error).'</div>';
    if($entry['conflict'])echo '<a href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=duplicates')).'">Перевірити дублікати</a>';
    if(!empty($entry['url_check']))echo '<div class="ogo-check-result">'.esc_html($entry['url_check']['at'].' — '.$entry['url_check']['result']).'</div>';
    if($entry['state']==='applied')echo ' <button type="button" class="button button-small" data-ogo-undo="'.(int)$id.'">Відкотити slug</button>';
    echo '</td></tr>';
   }
   echo '</tbody></table></details>';
  }
 }
}
