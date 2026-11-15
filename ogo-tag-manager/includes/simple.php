<?php
if (!defined('ABSPATH')) exit;

/** Guided operations share the v3.7 journal, migration snapshots and write lock. */
trait OGO_Tag_Simple {
 private static function simple_init(){
  add_action('admin_enqueue_scripts',[__CLASS__,'simple_assets']);
  add_action('wp_ajax_ogo_tm_simple',[__CLASS__,'simple_api']);
  add_action('admin_post_ogo_tm_simple_csv',[__CLASS__,'simple_csv']);
  add_action('template_redirect',[__CLASS__,'simple_redirect'],0);
 }
 public static function simple_assets($hook){
  if($hook!=='tools_page_'.self::SLUG||isset($_GET['tab']))return;
  wp_enqueue_script('ogo-tm-simple',plugins_url('assets/simple.js',dirname(__DIR__).'/ogo-tag-manager.php'),[],'3.11.0',true);
  wp_localize_script('ogo-tm-simple','OGO_SIMPLE',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ogo_tm_workbench')]);
  wp_enqueue_style('ogo-tm-simple',plugins_url('assets/simple.css',dirname(__DIR__).'/ogo-tag-manager.php'),[],'3.11.0');
 }
 private static function simple_save($key,$value){
  if(!update_option($key,$value,false)&&get_option($key,null)!==$value)throw new RuntimeException('Не вдалося зберегти дані. Операцію зупинено.');
 }
 private static function simple_rules(){ $v=get_option('ogo_tm_redirects_v38',[]);return is_array($v)?$v:[]; }
 private static function simple_path($url){
  $path=wp_parse_url($url,PHP_URL_PATH);
  if(!is_string($path)||$path===''||$path[0]!=='/')return '';
  // Decode once, then encode per segment: encoded Cyrillic and literal Cyrillic match.
  return implode('/',array_map('rawurlencode',explode('/',rawurldecode($path))));
 }
 private static function simple_rule($url,$id,$kind,$old_slug,$source=0){
  if(wp_parse_url($url,PHP_URL_HOST)!==wp_parse_url(home_url(),PHP_URL_HOST))throw new RuntimeException('Стара адреса має належати цьому сайту.');
  $path=self::simple_path($url);if(!$path||$path==='/')throw new RuntimeException('Некоректна адреса редиректу.');
  $rules=self::simple_rules();$key=hash('sha256',$path);
  $entry=['path'=>$path,'target'=>(int)$id,'kind'=>$kind,'old_slug'=>$old_slug,'source'=>(int)$source];
  if(isset($rules[$key])&&$rules[$key]!==$entry&&self::simple_destination($rules[$key]))throw new RuntimeException('Для старої адреси вже існує інше правило. Перевірте список редиректів.');
  self::export_capture($key,$entry,$rules[$key]??null);
  $rules[$key]=$entry;self::simple_save('ogo_tm_redirects_v38',$rules);
 }
 /** Follow completed merges by ID, so later target slug changes never create chains. */
 private static function simple_target($id){
  $seen=[];$staged=self::staged();
  for($i=0;$i<20;$i++){
   if(isset($seen[$id]))return false;$seen[$id]=true;
   if(isset($staged[$id])&&($staged[$id]['state']??'')==='staged'){$id=(int)$staged[$id]['target'];continue;}
   $term=get_term($id,'post_tag');return $term&&!is_wp_error($term)?$term:false;
  }
  return false;
 }
 private static function simple_destination($rule){
  if($rule['kind']==='merge'){
   $op=self::staged()[$rule['source']]??null;
   $deleted=self::simple_deletions()[$rule['source']]??null;
   $proof=$deleted&&($deleted['state']??'')==='deleted'&&($deleted['rule']??null)===$rule;
   if(!$op||($op['state']??'')!=='staged'||(int)$op['target']!==(int)$rule['target']||(!$proof&&strpos((string)($op['last_verify']['result']??''),'OK:')!==0))return false;
   $source=get_term((int)$rule['source'],'post_tag');
   if($source&&!is_wp_error($source)&&$source->slug!==$rule['old_slug'])return false;
  }else{
   $original=get_term((int)$rule['target'],'post_tag');
   if($original&&!is_wp_error($original)&&$original->slug===$rule['old_slug'])return false;
  }
  $term=self::simple_target((int)$rule['target']);if(!$term)return false;
  $url=get_term_link($term);
  if(is_wp_error($url)||wp_parse_url($url,PHP_URL_HOST)!==wp_parse_url(home_url(),PHP_URL_HOST)||self::simple_path($url)===$rule['path'])return false;
  // Do not steal a URL subsequently assigned to another live tag.
  $slug=rawurldecode(basename(rtrim($rule['path'],'/')));$owner=get_term_by('slug',$slug,'post_tag');
  $allowed=$rule['kind']==='merge'?(int)$rule['source']:(int)$rule['target'];
  if($owner&&(int)$owner->term_id!==$allowed)return false;
  return $url;
 }
 public static function simple_redirect(){
  if(is_admin()||!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true))return;
  $path=self::simple_path(wp_unslash($_SERVER['REQUEST_URI']??''));$rules=self::simple_rules();
  $rule=$rules[hash('sha256',$path)]??null;if(!$rule)return;
  $url=self::simple_destination($rule);if(!$url)return;
  if(self::pending_probe_valid($path)||self::pending_done(hash('sha256',$path),$rule,$url))return;
  nocache_headers();if(wp_safe_redirect($url,301,'OGO Tag Manager'))exit;
 }
 private static function simple_plural_names($name){
  $name=mb_strtolower(trim($name),'UTF-8');$out=[$name.'и',$name.'і'];
  if(preg_match('/а$/u',$name))$out[]=preg_replace('/а$/u','и',$name);
  if(preg_match('/[яь]$/u',$name))$out[]=preg_replace('/[яь]$/u','і',$name);
  if(preg_match('/й$/u',$name))$out[]=preg_replace('/й$/u','ї',$name);
  return array_unique($out);
 }
 private static function simple_pairs(){
  $rows=self::rows();$by=[];$out=[];
  foreach($rows as $r)$by[mb_strtolower(trim($r['name']),'UTF-8')][]=$r;
  foreach($rows as $a)foreach(self::simple_plural_names($a['name']) as $plural)foreach($by[$plural]??[] as $b){
   if((int)$a['term_id']===(int)$b['term_id']||isset(self::staged()[(int)$a['term_id']])||self::pair_skipped((int)$a['term_id'],(int)$b['term_id']))continue;
   $out[]=['source'=>(int)$a['term_id'],'target'=>(int)$b['term_id'],'name'=>$a['name'],'target_name'=>$b['name'],'count'=>(int)$a['post_count']];
  }
  foreach(self::staged() as $id=>$entry){
   if(!( !empty($entry['chunked_v311'])&&($entry['state']??'')==='running')&&!(($entry['state']??'')==='staged'&&strpos((string)($entry['last_verify']['result']??''),'OK:')!==0))continue;
   $term=get_term((int)$id,'post_tag');$target=get_term((int)$entry['target'],'post_tag');
   if($term&&!is_wp_error($term)&&$target&&!is_wp_error($target))$out[]=['source'=>(int)$id,'target'=>(int)$entry['target'],'name'=>$term->name,'target_name'=>$target->name,'count'=>count($entry['objects']),'running'=>true];
  }
  return $out;
 }
 public static function simple_api(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Недостатньо прав.'],403);
  check_ajax_referer('ogo_tm_workbench','nonce');$lock=null;
  try{
   $op=sanitize_key(wp_unslash($_POST['op']??''));
   if(in_array($op,['merge','slug','delete','export_start','skip','pending_verify','pending_group'],true))$lock=self::workbench_lock(900);
   if(in_array($op,['merge','slug'],true))self::export_validate_request($op);
   switch($op){
    case 'skip':$result=self::guided_skip();break;
    case 'skipped':$result=self::guided_skipped_list();break;
    case 'slug_preview':$result=self::guided_slug_preview();break;
    case 'pending_list':$result=['items'=>array_values(self::pending_rules())];break;
    case 'pending_verify':$result=self::pending_verify();break;
    case 'pending_group':$result=self::pending_group_save();break;
    case 'export_start':$result=self::export_start();break;
    case 'export_info':$result=self::export_info(sanitize_text_field(wp_unslash($_POST['export_batch']??'')));break;
    case 'delete_scan':$result=self::simple_delete_scan();break;
    case 'delete':$result=self::simple_delete_source();break;
    case 'pairs':$result=['items'=>self::simple_pairs()];break;
    case 'slugs':$result=self::guided_slugs();break;
    case 'preview':$result=self::simple_preview();break;
    case 'merge':$result=self::simple_merge();break;
    case 'slug':
     $id=absint($_POST['id']??0);$term=get_term($id,'post_tag');
     if(!$term||is_wp_error($term)||$term->slug!==wp_unslash($_POST['old_slug']??''))throw new RuntimeException('Slug змінився після завантаження списку. Оновіть список.');
     $_POST['items']=wp_slash(wp_json_encode([['id'=>$id,'slug'=>wp_unslash($_POST['slug']??'')]]));
     $plan=self::slug_prepare();$entry=$plan['items'][0];
     if($entry['state']!=='prepared')throw new RuntimeException(implode(' ',$entry['errors']));
     $_POST['batch']=$plan['batch'];$_POST['confirmed']='1';$result=self::slug_apply();break;
    default:throw new RuntimeException('Невідома операція.');
   }
   if(in_array($op,['merge','slug'],true)&&!empty($_POST['export_batch']))$result['export']=self::export_info(sanitize_text_field(wp_unslash($_POST['export_batch'])));
  }catch(Throwable $e){if($lock)self::workbench_unlock($lock);wp_send_json_error(['message'=>$e->getMessage()],400);return;}
  if($lock)self::workbench_unlock($lock);wp_send_json_success($result);
 }
 private static function simple_preview(){
  $a=absint($_POST['source']??0);$b=absint($_POST['target']??0);$queue=self::queue();
  if(self::pair_skipped($a,$b))throw new RuntimeException('Цю пару пропущено як різні поняття. Спочатку поверніть її до підказок.');
  if(isset($queue[$a])&&(($queue[$a]['kind']??'')!=='merge'||(int)$queue[$a]['target']!==$b))throw new RuntimeException('Джерело має інше рішення в черзі. Спершу скасуйте його.');
  if(isset(self::staged()[$b]))throw new RuntimeException('Ціль сама бере участь у перенесенні. Виберіть кінцеву позначку.');
  $staged=self::staged();foreach($queue as $id=>$d)if((int)$id!==$a&&($d['kind']??'')==='merge'&&(int)($d['target']??0)===$a&&(!isset($staged[$id])||($staged[$id]['state']??'')!=='staged'))throw new RuntimeException('Джерело є ціллю іншого об’єднання. Спершу завершіть його.');
  $check=self::preflight($a,$b,$queue,self::robots_rules());if($check['errors'])throw new RuntimeException(implode(' ',$check['errors']));
  $from=self::source_objects($a);$to=self::source_objects($b);
  if($from===null||$to===null)throw new RuntimeException('Не вдалося прочитати зв’язки статей.');
  $added=count(array_diff($from,$to));$source=get_term($a,'post_tag');$target=get_term($b,'post_tag');sort($from,SORT_NUMERIC);
  return ['source'=>$a,'target'=>$b,'source_hash'=>hash('sha256',wp_json_encode([$a,$source->name,$source->slug,$from])),'target_slug'=>$target->slug,'hash'=>self::fingerprint($a,$b),'count'=>count($from),'added'=>$added,'old_url'=>$check['from'],'new_url'=>$check['to'],'warnings'=>array_values(array_filter($check['warnings'],static function($w){return strpos($w,'301-редирект ще не створений')===false;}))];
 }
 private static function simple_merge(){
  $a=absint($_POST['source']??0);$b=absint($_POST['target']??0);
  $staged=self::staged();
  if(!empty($staged[$a]['chunked_v311']))return self::chunk_merge();
  if(!isset($staged[$a])){$src=self::source_objects($a);$dst=self::source_objects($b);if($src!==null&&$dst!==null&&(count(array_diff($src,$dst))>500||count($src)>20000))return self::chunk_merge();}
  if(isset($staged[$a])){
   if(($staged[$a]['state']??'')!=='staged'||(int)$staged[$a]['target']!==$b)throw new RuntimeException('Перенесення потребує перевірки у розширеній панелі.');
  }else{
   $plan=self::simple_preview();$expected=(string)wp_unslash($_POST['hash']??'');
   if(!$expected||!is_string($plan['hash'])||!hash_equals($expected,$plan['hash']))throw new RuntimeException('Дані змінилися після перевірки. Перевірте пару повторно.');
   $source=get_term($a,'post_tag');
   // Rule is durable before mutation, but activates only after a completed migration.
   self::simple_rule($plan['old_url'],$b,'merge',$source->slug,$a);
   $queue=self::queue();$queue[$a]=['kind'=>'merge','target'=>$b,'at'=>current_time('mysql'),'user'=>get_current_user_id()];self::simple_save(self::QUEUE,$queue);
   $snap=self::snapshots();$snap[$a]=['target'=>$b,'hash'=>self::fingerprint($a,$b),'at'=>current_time('mysql'),'source_objects'=>self::source_objects($a),'target_objects'=>self::source_objects($b),'source_slug'=>$source->slug,'target_slug'=>get_term($b,'post_tag')->slug];self::simple_save(self::SNAP,$snap);
   $done=self::execute_one($a,$b,true);if($done!==true)throw new RuntimeException($done);
  }
  $_POST['id']=$a;$verified=self::workbench_verify();if(!$verified['ok'])throw new RuntimeException($verified['result']);
  $op=self::staged()[$a];self::simple_rule($op['source_url'],$b,'merge',$op['source_slug'],$a);
  return ['complete'=>true,'message'=>'Статті додано до цільової позначки. 301 активний. Джерело готове до масового видалення у розділі 3.','verified'=>$verified];
 }
 public static function simple_page(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');
  $advanced=admin_url('tools.php?page='.self::SLUG.'&tab=workflow');
  $csv=self::export_link('all');
  echo '<div class="wrap ogo-simple"><h1>OGO Tag Manager 3.11.0 — швидкі дії</h1><p><a class="button" href="'.esc_url($advanced).'">Розширена панель і відкат</a> <a class="button" href="#ogo-pending-heading">Неперевірені редиректи ↓</a></p><p>Нові 301 тимчасово працюють через OGO Tag Manager. Експортуйте неперевірені правила у свою групу Redirection і перевірте їх кнопкою нижче. Старі джерела можна масово видалити нижче після перевірки перенесення. Власні SEO-налаштування джерела автоматично не переносяться.</p>';
  echo '<h2>1. Однина → множина</h2><p>Пари шукаються лише серед наявних позначок. Нові позначки не створюються. Перегляньте пари й виберіть потрібні. Якщо це різні поняття (наприклад, Шахтар — клуб і шахтарі — професія), натисніть «Пропустити пару». Великі перенесення обробляються порціями з можливістю продовження.</p><p><button type="button" class="button" id="ogo-simple-pairs">Знайти пари</button> Або за ID: <label>джерело <input type="number" min="1" id="ogo-simple-source" style="width:100px"></label> → <label>ціль <input type="number" min="1" id="ogo-simple-target" style="width:100px"></label> <button type="button" class="button" id="ogo-simple-pair-add">Додати пару</button></p><div id="ogo-simple-pair-list"></div><details><summary>Пропущені пари</summary><p><button type="button" class="button" id="ogo-skipped-load">Показати пропущені пари</button></p><div id="ogo-skipped-list"></div></details><p><button type="button" class="button button-primary" id="ogo-simple-merge">Перевірити й об’єднати вибрані</button></p>';
  echo '<h2>2. Кириличні slug → латиниця</h2><p>Назви позначок і статті зберігаються. Нові slug можна виправити перед запуском. Якщо адреса зайнята іншою позначкою, буде запропоновано об’єднання з перенесенням статей і редиректом. Перед виконанням показується план.</p><p><button type="button" class="button" id="ogo-simple-slugs">Знайти всі кириличні slug</button> <button type="button" class="button" id="ogo-simple-select">Вибрати всі / зняти вибір</button></p><div id="ogo-simple-slug-list"></div><p><button type="button" class="button button-primary" id="ogo-simple-slug-run">Застосувати slug та об’єднати дублікати</button></p><p><button type="button" class="button" id="ogo-simple-stop" hidden>Зупинити після поточного</button></p><div id="ogo-simple-progress" role="status" aria-live="polite"></div>';
  self::simple_delete_page();
  self::pending_page();
  echo '<details><summary>Журнал пакетів CSV та резервна копія</summary>';
  self::export_history_page();
  echo '<p><a class="button" href="'.esc_url($csv).'">Повний CSV усіх правил — резервна копія</a></p></details>';
  echo '</div>';
 }
}
