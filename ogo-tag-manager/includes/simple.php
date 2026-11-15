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
  wp_enqueue_script('ogo-tm-simple',plugins_url('assets/simple.js',dirname(__DIR__).'/ogo-tag-manager.php'),[],'3.8.0',true);
  wp_localize_script('ogo-tm-simple','OGO_SIMPLE',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ogo_tm_workbench')]);
  wp_enqueue_style('ogo-tm-simple',plugins_url('assets/simple.css',dirname(__DIR__).'/ogo-tag-manager.php'),[],'3.8.0');
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
   if(!$op||($op['state']??'')!=='staged'||(int)$op['target']!==(int)$rule['target']||strpos((string)($op['last_verify']['result']??''),'OK:')!==0)return false;
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
   if((int)$a['term_id']===(int)$b['term_id']||isset(self::staged()[(int)$a['term_id']]))continue;
   $out[]=['source'=>(int)$a['term_id'],'target'=>(int)$b['term_id'],'name'=>$a['name'],'target_name'=>$b['name'],'count'=>(int)$a['post_count']];
  }
  return $out;
 }
 public static function simple_api(){
  if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Недостатньо прав.'],403);
  check_ajax_referer('ogo_tm_workbench','nonce');$lock=null;
  try{
   $op=sanitize_key(wp_unslash($_POST['op']??''));
   if(in_array($op,['merge','slug'],true))$lock=self::workbench_lock(900);
   switch($op){
    case 'pairs':$result=['items'=>self::simple_pairs()];break;
    case 'slugs':
     $items=[];foreach(self::rows() as $r){
      if(!preg_match('/\p{Cyrillic}/u',rawurldecode($r['slug'])))continue;
      $items[]=['id'=>(int)$r['term_id'],'name'=>$r['name'],'old_slug'=>$r['slug'],'slug'=>self::suggestion(rawurldecode($r['slug'])),'count'=>(int)$r['post_count']];
     }$result=['items'=>$items];break;
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
  }catch(Throwable $e){if($lock)self::workbench_unlock($lock);wp_send_json_error(['message'=>$e->getMessage()],400);return;}
  if($lock)self::workbench_unlock($lock);wp_send_json_success($result);
 }
 private static function simple_preview(){
  $a=absint($_POST['source']??0);$b=absint($_POST['target']??0);$queue=self::queue();
  if(isset($queue[$a])&&(($queue[$a]['kind']??'')!=='merge'||(int)$queue[$a]['target']!==$b))throw new RuntimeException('Джерело має інше рішення в черзі. Спершу скасуйте його.');
  if(isset(self::staged()[$b]))throw new RuntimeException('Ціль сама бере участь у перенесенні. Виберіть кінцеву позначку.');
  $staged=self::staged();foreach($queue as $id=>$d)if((int)$id!==$a&&($d['kind']??'')==='merge'&&(int)($d['target']??0)===$a&&(!isset($staged[$id])||($staged[$id]['state']??'')!=='staged'))throw new RuntimeException('Джерело є ціллю іншого об’єднання. Спершу завершіть його.');
  $check=self::preflight($a,$b,$queue,self::robots_rules());if($check['errors'])throw new RuntimeException(implode(' ',$check['errors']));
  $from=self::source_objects($a);$to=self::source_objects($b);
  if($from===null||$to===null)throw new RuntimeException('Не вдалося прочитати зв’язки статей.');
  $added=count(array_diff($from,$to));if($added>500)throw new RuntimeException('Понад 500 нових зв’язків. Скористайтеся розширеною панеллю для цієї пари.');
  return ['source'=>$a,'target'=>$b,'hash'=>self::fingerprint($a,$b),'count'=>count($from),'added'=>$added,'old_url'=>$check['from'],'new_url'=>$check['to'],'warnings'=>array_values(array_filter($check['warnings'],static function($w){return strpos($w,'301-редирект ще не створений')===false;}))];
 }
 private static function simple_merge(){
  $a=absint($_POST['source']??0);$b=absint($_POST['target']??0);
  $staged=self::staged();
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
  return ['message'=>'Статті додано до цільової позначки. 301 активний. Стару позначку можна видалити після перевірки.','verified'=>$verified];
 }
 public static function simple_csv(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tm_simple_csv');
  $rows=[];foreach(self::simple_rules() as $rule){$url=self::simple_destination($rule);if($url)$rows[]=[$rule['path'],wp_parse_url($url,PHP_URL_PATH),'0','301'];}
  while(ob_get_level())ob_end_clean();nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ogo-tag-redirects-3.8.csv"');
  $out=fopen('php://output','w');foreach($rows as $row)fputcsv($out,$row);fclose($out);exit;
 }
 public static function simple_page(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');
  $advanced=admin_url('tools.php?page='.self::SLUG.'&tab=workflow');
  $csv=wp_nonce_url(admin_url('admin-post.php?action=ogo_tm_simple_csv'),'ogo_tm_simple_csv');
  echo '<div class="wrap ogo-simple"><h1>OGO Tag Manager 3.8 — швидкі дії</h1><p><a class="button" href="'.esc_url($advanced).'">Розширена панель і відкат</a> <a class="button" href="'.esc_url($csv).'">Завантажити список редиректів (CSV)</a></p><p>301 працюють автоматично, поки OGO Tag Manager активний. Імпорт CSV не потрібний. Старі позначки видаляєте ви після перевірки статей. Власні SEO-налаштування джерела автоматично не переносяться.</p>';
  echo '<h2>1. Однина → множина</h2><p>Перегляньте запропоновані пари та виберіть потрібні. Це мовні підказки: зміст кожної пари перевіряєте ви.</p><p><button type="button" class="button" id="ogo-simple-pairs">Знайти пари</button> Або за ID: <label>джерело <input type="number" min="1" id="ogo-simple-source" style="width:100px"></label> → <label>ціль <input type="number" min="1" id="ogo-simple-target" style="width:100px"></label> <button type="button" class="button" id="ogo-simple-pair-add">Додати пару</button></p><div id="ogo-simple-pair-list"></div><p><button type="button" class="button button-primary" id="ogo-simple-merge">Перевірити й об’єднати вибрані</button></p>';
  echo '<h2>2. Кириличні slug → латиниця</h2><p>Назви позначок і статті зберігаються. Нові slug можна виправити перед запуском. Зайняті адреси пропускаються з поясненням.</p><p><button type="button" class="button" id="ogo-simple-slugs">Знайти всі кириличні slug</button> <button type="button" class="button" id="ogo-simple-select">Вибрати всі / зняти вибір</button></p><div id="ogo-simple-slug-list"></div><p><button type="button" class="button button-primary" id="ogo-simple-slug-run">Змінити вибрані slug та ввімкнути 301</button></p><p><button type="button" class="button" id="ogo-simple-stop" hidden>Зупинити після поточного</button></p><div id="ogo-simple-progress" role="status" aria-live="polite"></div>';
  echo '<h2>Список активних редиректів</h2><p>Після виконання оновіть сторінку. CSV містить ті самі активні правила. Якщо змінити slug цілі, правила автоматично ведуть на її нову адресу.</p><table class="widefat striped"><thead><tr><th>Звідки</th><th>Куди (301)</th></tr></thead><tbody>';
  $count=0;foreach(self::simple_rules() as $rule){$url=self::simple_destination($rule);if(!$url)continue;$count++;echo '<tr><td>'.esc_html(rawurldecode($rule['path'])).'</td><td><a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($url).'</a></td></tr>';}
  if(!$count)echo '<tr><td colspan="2">Ще немає активних редиректів.</td></tr>';
  echo '</tbody></table></div>';
 }
}
