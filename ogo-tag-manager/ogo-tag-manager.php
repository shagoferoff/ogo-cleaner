<?php
/**
 * Plugin Name: OGO Tag Manager
 * Description: Post tag audit, staged reversible relationship migration and CSV redirect plan.
 * Version: 3.7.0
 * Author: OGO
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/includes/workbench.php';
final class OGO_Tag_Manager {
 use OGO_Tag_Workbench;
 const SNAP='ogo_tag_manager_preflight_v20'; const REV='ogo_tag_manager_queue_revision_v21'; const QSNAP='ogo_tag_manager_queue_snapshot_v21'; const JOURNAL='ogo_tag_manager_journal_v18'; const PAIRS='ogo_tag_manager_pair_decisions_v13'; const STAGED='ogo_tag_manager_staged_v1'; const SLUG='ogo-tag-manager'; const NONCE='ogo_tag_manager_export'; const QUEUE='ogo_tag_manager_review_v03';
 public static function init(){self::workbench_init();add_action('admin_post_ogo_tag_manager_cleanup_confirm',[__CLASS__,'cleanup_confirm']);add_action('admin_post_ogo_tag_manager_cleanup_redirect_csv',[__CLASS__,'cleanup_redirect_csv']);add_action('admin_post_ogo_tag_manager_cleanup_execute',[__CLASS__,'cleanup_execute']);add_action('update_option_ogo_tag_manager_review_v03',[__CLASS__,'queue_changed'],10,2);add_action('admin_post_ogo_tag_manager_package_csv',[__CLASS__,'package_csv']);add_action('admin_post_ogo_tag_manager_package_confirm',[__CLASS__,'package_confirm']);add_action('admin_post_ogo_tag_manager_package_execute',[__CLASS__,'package_execute']);add_action('admin_post_ogo_tag_manager_stage_confirm',[__CLASS__,'stage_confirm']);add_action('admin_menu',[__CLASS__,'menu']);add_action('add_meta_boxes_post',[__CLASS__,'author_box']);add_action('wp_ajax_ogo_tag_suggest',[__CLASS__,'author_suggest']);add_action('admin_post_ogo_tag_manager_export',[__CLASS__,'export']);add_action('admin_post_ogo_tag_manager_plan_csv',[__CLASS__,'plan_csv']);add_action('admin_post_ogo_tag_manager_snapshot',[__CLASS__,'snapshot']);add_action('admin_post_ogo_tag_manager_control_csv',[__CLASS__,'control_csv']);add_action('admin_post_ogo_tag_manager_review',[__CLASS__,'review']);add_action('admin_post_ogo_tag_manager_stage',[__CLASS__,'stage']);add_action('admin_post_ogo_tag_manager_undo_stage',[__CLASS__,'undo_stage']);add_action('admin_post_ogo_tag_manager_redirect_csv',[__CLASS__,'redirect_csv']);add_action('admin_post_ogo_tag_manager_verify',[__CLASS__,'verify_stage']);add_action('admin_post_ogo_tag_manager_verify_batch',[__CLASS__,'verify_batch']);add_action('admin_post_ogo_tag_manager_pair',[__CLASS__,'pair_decision']);add_action('admin_post_ogo_tag_manager_batch_queue',[__CLASS__,'batch_queue']);add_action('admin_post_ogo_tag_manager_external_target',[__CLASS__,'external_target']);add_action('admin_post_ogo_tag_manager_suggested_group',[__CLASS__,'suggested_group']);add_action('wp_ajax_ogo_tag_find_targets',[__CLASS__,'find_targets']);}
 public static function menu(){add_management_page('OGO Tag Manager','OGO Tag Manager','manage_options',self::SLUG,[__CLASS__,'page']);}
 private static function rows(){global $wpdb;return $wpdb->get_results("SELECT t.term_id,t.name,t.slug,tt.count AS post_count FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id WHERE tt.taxonomy='post_tag' ORDER BY t.term_id",ARRAY_A);}
 private static function normalized($s){$s=remove_accents(mb_strtolower(trim(wp_strip_all_tags($s)),'UTF-8'));return preg_replace('/[^\p{L}\p{N}]+/u','',$s);}
 private static function bad_slug($slug){return !preg_match('/^[a-z0-9_-]+$/D',$slug);}
 private static function groups($rows){$by=[];foreach($rows as $r){$k=self::normalized($r['name']);if($k!=='')$by[$k][]=$r;} $out=array_values(array_filter($by,static function($g){return count($g)>1;}));usort($out,static function($a,$b){return count($b)<=>count($a);});return $out;}
 private static function robots_rules(){// Read a physical robots.txt only. Never infer actual Google indexing.
  $file=ABSPATH.'robots.txt'; if(!is_readable($file))return null; $txt=file_get_contents($file);if($txt===false)return null;
  $rules=[];$agents=[];foreach(preg_split('/\r\n|\n|\r/',$txt) as $line){$line=trim(preg_replace('/\s*#.*$/','',$line));if($line===''){$agents=[];continue;}if(preg_match('/^User-agent:\s*(.*)$/i',$line,$m)){$agents[]=trim($m[1]);continue;}if(preg_match('/^(Allow|Disallow):\s*(.*)$/i',$line,$m) && in_array('*',$agents,true))$rules[]=['type'=>strtolower($m[1]),'path'=>trim($m[2])];}return $rules;
 }
 private static function robots_status($url,$rules){if($rules===null)return 'Невідомо (динамічний robots.txt або файл недоступний)';$path=wp_parse_url($url,PHP_URL_PATH);$best=-1;$allowed=true;foreach($rules as $rule){$pattern=$rule['path'];if($pattern==='')continue;$regex=preg_quote($pattern,'~');$regex=str_replace('\\*','.*',$regex);if(substr($regex,-2)==='\\$')$regex=substr($regex,0,-2).'$';$regex='~^'.$regex.'~u';if(@preg_match($regex,$path)===1){$len=strlen(str_replace('*','',$pattern));if($len>$best||($len===$best&&$rule['type']==='allow')){$best=$len;$allowed=$rule['type']==='allow';}}}return $allowed?'Дозволено robots.txt':'Заборонено robots.txt';}
 private static function seo($id){$meta=get_option('wpseo_taxonomy_meta',[]);$entry=(is_array($meta)&&isset($meta['post_tag'][$id])&&is_array($meta['post_tag'][$id]))?$meta['post_tag'][$id]:[];$title=isset($entry['wpseo_title'])?(string)$entry['wpseo_title']:'';$desc=isset($entry['wpseo_desc'])?(string)$entry['wpseo_desc']:'';$noindex=isset($entry['wpseo_noindex'])?(string)$entry['wpseo_noindex']:'';return [$title,$desc,$noindex];}
 private static function link($r){return get_term_link((int)$r['term_id'],'post_tag');}
 private static function table($rows,$rules,$with_group=false){echo '<table class="widefat striped" style="margin-top:12px"><thead><tr>';foreach(array_merge($with_group?['Група']:[],['ID','Позначка','Частина посилання (slug)','Статей','URL','Robots (локальний файл)','Yoast: власні метадані']) as $h)echo '<th>'.esc_html($h).'</th>';echo '</tr></thead><tbody>';foreach($rows as $r){$url=self::link($r);$valid=!is_wp_error($url);echo '<tr>';if($with_group)echo '<td>'.esc_html($r['_group']).'</td>';echo '<td>'.(int)$r['term_id'].'</td><td>'.esc_html($r['name']).'</td><td><code>'.esc_html($r['slug']).'</code>'.(self::bad_slug($r['slug'])?' <strong style="color:#b32d2e">!</strong>':'').'</td><td>'.(int)$r['post_count'].'</td><td>'.($valid?'<a href="'.esc_url($url).'" target="_blank" rel="noopener">Відкрити</a>':'—').'</td><td>'.esc_html($valid?self::robots_status($url,$rules):'Невідомо').'</td>';[$t,$d,$n]=self::seo((int)$r['term_id']);echo '<td>'.esc_html(($t!==''?'Title ':'').($d!==''?'Description ':'').($n!==''?'Noindex='.$n:'')?:'Немає власних значень').'</td></tr>';}echo '</tbody></table>';}
 private static function suggestion($slug){
  $latin=remove_accents($slug);$latin=sanitize_title($latin);
  if(!self::bad_slug($latin) && $latin!=='' && $latin!==$slug)return $latin;
  $map=['а'=>'a','б'=>'b','в'=>'v','г'=>'h','ґ'=>'g','д'=>'d','е'=>'e','є'=>'ye','ж'=>'zh','з'=>'z','и'=>'y','і'=>'i','ї'=>'yi','й'=>'j','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'cz','ч'=>'ch','ш'=>'sh','щ'=>'shh','ь'=>'','ю'=>'yu','я'=>'ya','ы'=>'y','э'=>'e','ё'=>'yo','ъ'=>''];
  $v=mb_strtolower($slug,'UTF-8');$v=strtr($v,$map);$v=preg_replace('/[^a-z0-9_-]+/','-',$v);return trim($v,'-');
 }
 private static function queue(){ $q=get_option(self::QUEUE,[]);return is_array($q)?$q:[]; }
 private static function status($entry){if(!$entry)return 'Неопрацьовано';return ['merge'=>'У черзі на об’єднання','slug'=>'На перевірці URL','reject'=>'Не об’єднувати'][$entry['kind']??'']??'У черзі';}
 private static function review_button($type,$source,$target,$label,$return_tab='duplicates',$return_page=1,$extra=[]){
  $form='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:2px">';
  $fields=array_merge(['action'=>'ogo_tag_manager_review','kind'=>$type,'source'=>$source,'target'=>$target,'return_tab'=>$return_tab,'return_page'=>$return_page,'return_filter'=>self::return_filter()],$extra);
  foreach($fields as $key=>$value)$form.='<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
  $form.=wp_nonce_field($type==='merge'?'ogo_tag_review_merge_'.$source:'ogo_tag_review_'.$type.'_'.$source.'_'.$target,'_wpnonce',true,false);
  return $form.'<button type="submit" class="button button-small">'.esc_html($label).'</button></form>';
 }
 private static function group_form($group,$main,$page){
  $ids=array_values(array_diff(array_map(static function($r){return (int)$r['term_id'];},$group),[(int)$main]));
  if(!$ids)return '';
  $form='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block">';
  foreach(['action'=>'ogo_tag_manager_review','kind'=>'group','source'=>0,'target'=>$main,'return_tab'=>'duplicates','return_page'=>$page,'return_filter'=>self::return_filter(),'group_ids'=>implode(',',$ids)] as $k=>$v)$form.='<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
  $form.=wp_nonce_field('ogo_tag_review_group_0_'.$main,'_wpnonce',true,false);
  return $form.'<button class="button button-small" onclick="return confirm(\'Додати всі інші теги групи до черги? Це НЕ виконує об’єднання.\')">Усю групу в чергу</button></form>';
 }
 public static function review(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');
  $kind=isset($_POST['kind'])?sanitize_key(wp_unslash($_POST['kind'])):'';
  $source=isset($_POST['source'])?absint($_POST['source']):0;$target=isset($_POST['target'])?absint($_POST['target']):0;
  $tab=isset($_POST['return_tab'])?sanitize_key(wp_unslash($_POST['return_tab'])):'queue';
  if(!in_array($tab,['duplicates','slugs','queue'],true))$tab='queue';
  $page=isset($_POST['return_page'])?max(1,absint($_POST['return_page'])):1;
  if(!in_array($kind,['merge','slug','reject','remove','group'],true)||(!$source&&$kind!=='group'))wp_die('Некоректні дані.');
  check_admin_referer($kind==='merge'?'ogo_tag_review_merge_'.$source:'ogo_tag_review_'.$kind.'_'.$source.'_'.$target);
  $queue=self::queue();
  if($kind==='group'){
   $raw=isset($_POST['group_ids'])?sanitize_text_field(wp_unslash($_POST['group_ids'])):'';
   $ids=array_unique(array_filter(array_map('absint',explode(',',$raw))));
   if(count($ids)>100||!$target||!$ids)wp_die('Некоректна група.');
   $other=get_term($target,'post_tag');if(!$other||is_wp_error($other))wp_die('Основний тег не знайдено.');
   $all=self::groups(self::rows());$valid=false;
   foreach($all as $g){$members=array_map(static function($r){return (int)$r['term_id'];},$g);if(in_array($target,$members,true)&&!array_diff($ids,$members)&&!in_array($target,$ids,true)){$valid=true;break;}}
   if(!$valid)wp_die('Група змінилася. Оновіть сторінку.');
   if(isset($queue[$target])&&($queue[$target]['kind']??'')==='merge')wp_die('Ціль уже запланована до об’єднання.');foreach($ids as $id){if(isset($queue[$id])||isset(self::staged()[$id]))wp_die('Частина тегів уже має рішення або перенесення. Використайте індивідуальні кнопки.');$term=get_term($id,'post_tag');if(!$term||is_wp_error($term))continue;$queue[$id]=['kind'=>'merge','target'=>$target,'at'=>current_time('mysql'),'user'=>get_current_user_id(),'name'=>$term->name,'slug'=>$term->slug];}
  }else{
   $term=get_term($source,'post_tag');if(!$term||is_wp_error($term))wp_die('Позначку не знайдено.');
   if($kind==='merge'){$other=get_term($target,'post_tag');if(!$other||is_wp_error($other)||$source===$target)wp_die('Неприпустима пара.');if(isset($queue[$target])&&($queue[$target]['kind']??'')==='merge')wp_die('Ціль уже запланована до об’єднання. Спочатку змініть її рішення.');}
   if($kind==='remove'&&isset(self::staged()[$source]))wp_die('Спочатку відкотіть перенесення.');if($kind==='remove')unset($queue[$source]);else $queue[$source]=['kind'=>$kind,'target'=>$kind==='merge'?$target:0,'at'=>current_time('mysql'),'user'=>get_current_user_id(),'name'=>$term->name,'slug'=>$term->slug];
  }
  update_option(self::QUEUE,$queue,false);
  self::finish_action(add_query_arg(['page'=>self::SLUG,'tab'=>$tab,'paged'=>$page,'updated'=>'1','filter'=>self::return_filter()],admin_url('tools.php')));exit;
 }
 private static function stem($name){
  $v=mb_strtolower(trim($name),'UTF-8');$v=strtr($v,['’'=>"'",'ʼ'=>"'",'`'=>"'",'ї'=>'і','є'=>'е','ґ'=>'г']);
  $v=preg_replace('/[^\p{L}\p{N}]+/u','',$v);
  // A short prefix is a discovery hint only; never an automatic merge decision.
  $n=mb_strlen($v,'UTF-8');return $n>=7?mb_substr($v,0,$n>=11?6:5,'UTF-8'):$v;
 }
 public static function find_targets(){
  if(!current_user_can('manage_options'))wp_send_json_error('Недостатньо прав.',403);
  check_ajax_referer('ogo_tag_find_targets','nonce');
  $ids=isset($_POST['ids'])?array_slice(array_unique(array_filter(array_map('absint',(array)$_POST['ids']))),0,20):[];
  $query=isset($_POST['q'])?sanitize_text_field(wp_unslash($_POST['q'])):'';
  if(!$ids)wp_send_json_error('Оберіть групу.',400);
  $rows=self::rows();$map=[];foreach($rows as $r)$map[(int)$r['term_id']]=$r;
  $sources=[];foreach($ids as $id)if(isset($map[$id]))$sources[]=$map[$id];
  if(!$sources)wp_send_json_error('Групу не знайдено.',404);
  $norm=[];$stems=[];foreach($sources as $r){$norm[]=self::normalized($r['name']);$stems[]=self::stem($r['name']);}
  $qnorm=self::normalized($query);$queue=self::queue();$staged=self::staged();$matches=[];
  foreach($rows as $r){$id=(int)$r['term_id'];if(in_array($id,$ids,true))continue;
   $n=self::normalized($r['name']);$stem=self::stem($r['name']);$score=0;$why='';
   if($qnorm!==''){
    if($n===$qnorm){$score=100;$why='Точний пошук';}
    elseif(mb_strpos($n,$qnorm,0,'UTF-8')!==false){$score=80;$why='Збіг назви';}
    elseif(mb_strlen($qnorm,'UTF-8')>=4 && mb_strpos($qnorm,$n,0,'UTF-8')!==false){$score=45;$why='Частковий збіг';}
   }else{
    foreach($norm as $i=>$src){
     if($n===$src){$score=max($score,100);$why='Однакова нормалізована назва';}
     elseif(mb_strlen($stem,'UTF-8')>=4 && $stem===$stems[$i]){$score=max($score,65);$why='Спільна основа слова — перевірити значення';}
     elseif(mb_strlen($n,'UTF-8')>=5 && (mb_strpos($n,$src,0,'UTF-8')===0 || mb_strpos($src,$n,0,'UTF-8')===0)){$score=max($score,35);$why='Схожий початок — перевірити значення';}
    }
   }
   if(!$score)continue;
   if(!self::bad_slug($r['slug']))$score+=8;
   $count=(int)$r['post_count'];$score+=min(10,(int)floor(log(1+$count)));
   $blocked=isset($queue[$id])||isset($staged[$id]);
   $matches[]=['id'=>$id,'name'=>$r['name'],'slug'=>$r['slug'],'count'=>$count,'reason'=>$why,'score'=>$score,'blocked'=>$blocked];
  }
  usort($matches,static function($a,$b){return ($b['score']<=>$a['score'])?:($b['count']<=>$a['count']);});
  wp_send_json_success(array_slice($matches,0,12));
 }
 public static function suggested_group(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');
  check_admin_referer('ogo_tag_suggested_group');
  $ids=isset($_POST['sources'])?array_slice(array_unique(array_filter(array_map('absint',explode(',',sanitize_text_field(wp_unslash($_POST['sources'])))))),0,20):[];
  $target=isset($_POST['target'])?absint($_POST['target']):0;
  $page=isset($_POST['return_page'])?max(1,absint($_POST['return_page'])):1;
  if(!$ids||!$target||in_array($target,$ids,true))wp_die('Некоректна ціль або джерела.');
  $all=self::groups(self::rows());$valid=false;
  foreach($all as $g){$members=array_map(static function($r){return (int)$r['term_id'];},$g);if(!array_diff($ids,$members)){$valid=true;break;}}
  if(!$valid)wp_die('Склад групи змінився. Оновіть сторінку.');
  $dst=get_term($target,'post_tag');if(!$dst||is_wp_error($dst))wp_die('Ціль не знайдено.');
  $queue=self::queue();$staged=self::staged();
  if(isset($queue[$target])||isset($staged[$target]))wp_die('Ціль уже має рішення або перенесення.');
  foreach($ids as $id){if(isset($queue[$id])||isset($staged[$id]))wp_die('Одне з джерел уже має рішення або перенесення.');$src=get_term($id,'post_tag');if(!$src||is_wp_error($src))wp_die('Джерело не знайдено.');}
  foreach($ids as $id){$src=get_term($id,'post_tag');$queue[$id]=['kind'=>'merge','target'=>$target,'at'=>current_time('mysql'),'user'=>get_current_user_id(),'name'=>$src->name,'slug'=>$src->slug];}
  update_option(self::QUEUE,$queue,false);
  self::finish_action(add_query_arg(['page'=>self::SLUG,'tab'=>'duplicates','paged'=>$page,'updated'=>'1','filter'=>self::return_filter()],admin_url('tools.php')));exit;
 }
 public static function external_target(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');
  check_admin_referer('ogo_tag_manager_external_target');
  $source=isset($_POST['source'])?absint($_POST['source']):0;
  $target=isset($_POST['target'])?absint($_POST['target']):0;
  $page=isset($_POST['return_page'])?max(1,absint($_POST['return_page'])):1;
  $src=get_term($source,'post_tag');$dst=get_term($target,'post_tag');
  if(!$source||!$target||$source===$target||!$src||is_wp_error($src)||!$dst||is_wp_error($dst))wp_die('Неправильні ID. Обидва теги повинні існувати.');
  $queue=self::queue();$staged=self::staged();
  if(isset($staged[$source])||isset($staged[$target]))wp_die('Для одного з тегів уже перенесено зв’язки. Спочатку завершіть або відкотіть операцію.');
  if(isset($queue[$source]))wp_die('Для джерела вже є рішення. Спочатку скасуйте його в черзі.');
  if(isset($queue[$target]))wp_die('Ціль уже має рішення в черзі. Оберіть кінцевий основний тег без запланованих змін.');
  $queue[$source]=['kind'=>'merge','target'=>$target,'at'=>current_time('mysql'),'user'=>get_current_user_id(),'name'=>$src->name,'slug'=>$src->slug];
  update_option(self::QUEUE,$queue,false);
  self::finish_action(add_query_arg(['page'=>self::SLUG,'tab'=>'duplicates','paged'=>$page,'updated'=>'1','filter'=>self::return_filter()],admin_url('tools.php')));exit;
 }
 public static function batch_queue(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');
  check_admin_referer('ogo_tag_manager_batch_queue');
  $raw=isset($_POST['group_keys'])?(array)wp_unslash($_POST['group_keys']):[];
  $keys=array_values(array_unique(array_filter(array_map('sanitize_text_field',$raw))));
  if(!$keys||count($keys)>25)wp_die('Оберіть від 1 до 25 груп за один раз.');
  $queue=self::queue();$staged=self::staged();$groups=self::groups(self::rows());$lookup=[];
  foreach($groups as $group){usort($group,static function($a,$b){return (int)$b['post_count']<=>(int)$a['post_count']?:((int)$a['term_id']<=>(int)$b['term_id']);});$ids=array_map(static function($r){return (int)$r['term_id'];},$group);sort($ids,SORT_NUMERIC);$key=implode('-',$ids);$lookup[$key]=$group;}
  $new=[];$skipped=0;$chosen=isset($_POST['group_targets'])?(array)wp_unslash($_POST['group_targets']):[];
  foreach($keys as $key){if(!isset($lookup[$key]))wp_die('Склад груп змінився. Оновіть сторінку й повторіть вибір.');$group=$lookup[$key];$main=isset($chosen[$key])?absint($chosen[$key]):(int)$group[0]['term_id'];$members=array_map(static function($r){return (int)$r['term_id'];},$group);if(!in_array($main,$members,true))wp_die('Основний тег не належить групі.');if(isset($queue[$main])||isset($staged[$main])){$skipped++;continue;}
   foreach($group as $term){$id=(int)$term['term_id'];if($id===$main)continue;if(isset($queue[$id])||isset($staged[$id])){$skipped++;continue;}$new[$id]=['kind'=>'merge','target'=>$main,'at'=>current_time('mysql'),'user'=>get_current_user_id(),'name'=>$term['name'],'slug'=>$term['slug']];}
  }
  if($new){$queue=array_replace($queue,$new);update_option(self::QUEUE,$queue,false);}
  self::finish_action(add_query_arg(['page'=>self::SLUG,'tab'=>'duplicates','batch_added'=>count($new),'batch_skipped'=>$skipped,'paged'=>max(1,absint($_POST['return_page']??1)),'filter'=>self::return_filter()],admin_url('tools.php')));exit;
 }
 private static function preview($source,$target){
  global $wpdb;
  $tax=$wpdb->term_taxonomy;
  $a=(int)$wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$tax} WHERE term_id=%d AND taxonomy='post_tag'",$source));
  $b=(int)$wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$tax} WHERE term_id=%d AND taxonomy='post_tag'",$target));
  if(!$a||!$b)return null;
  $rels=$wpdb->term_relationships;
  $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$rels} WHERE term_taxonomy_id=%d",$a));
  $overlap=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$rels} x INNER JOIN {$rels} y ON x.object_id=y.object_id AND y.term_taxonomy_id=%d WHERE x.term_taxonomy_id=%d",$b,$a));
  return ['total'=>$total,'overlap'=>$overlap,'new'=>max(0,$total-$overlap)];
 }
 private static function preflight($source,$target,$queue,$rules){
  $errors=[];$warnings=[];
  $a=get_term($source,'post_tag');$b=get_term($target,'post_tag');
  if(!$a||is_wp_error($a)||!$b||is_wp_error($b)){$errors[]='Тег-джерело або ціль не існує.';return compact('errors','warnings');}
  if($source===$target)$errors[]='Джерело дорівнює цілі.';
  if(isset($queue[$target])&&($queue[$target]['kind']??'')==='merge')$errors[]='Ціль сама перебуває в черзі на об’єднання.';
  $seen=[$source=>true];$node=$target;
  while(isset($queue[$node])&&($queue[$node]['kind']??'')==='merge'){
   if(isset($seen[$node])){$errors[]='Виявлено цикл об’єднань.';break;}
   $seen[$node]=true;$node=(int)($queue[$node]['target']??0);
  }
  $p=self::preview($source,$target);
  if(!$p)$errors[]='Не вдалося перевірити зв’язки зі статтями.';
  [$at,$ad,$an]=self::seo($source);[$bt,$bd,$bn]=self::seo($target);
  if($at!==''||$ad!==''||$an!=='')$warnings[]='У джерела є власні SEO-налаштування Yoast — потрібне рішення щодо перенесення.';
  if($bt!==''||$bd!==''||$bn!=='')$warnings[]='У цілі є власні SEO-налаштування Yoast — не перезаписувати без перевірки.';
  $from=get_term_link($a);$to=get_term_link($b);
  if(is_wp_error($from)||is_wp_error($to))$errors[]='Не вдалося отримати URL тегів.';
  else {
   if($from===$to)$errors[]='URL джерела і цілі збігаються.';
   if(self::robots_status($from,$rules)==='Дозволено robots.txt')$warnings[]='Старий URL дозволений robots.txt — перед зміною перевірити індексацію та посилання.';
   if(self::robots_status($to,$rules)==='Заборонено robots.txt')$warnings[]='Цільовий URL заборонений robots.txt.';
  }
  if($a->slug!==sanitize_title($a->slug))$warnings[]='Slug джерела містить нестандартні символи.';
  $warnings[]='301-редирект ще не створений; перевірте наявні правила перенаправлення, Yoast і кеш.';
  return ['errors'=>$errors,'warnings'=>$warnings,'preview'=>$p,'from'=>is_wp_error($from)?'':$from,'to'=>is_wp_error($to)?'':$to];
 }

 // Read-only, point-in-time inventory. Never writes terms, relationships or options.
 private static function migration_plan($source,$target,$queue,$rules){
  $check=self::preflight($source,$target,$queue,$rules);
  $src=self::source_objects($source);$dst=self::source_objects($target);
  $rows=[];
  if($src===null||$dst===null){$check['errors'][]='Не вдалося прочитати зв’язки для детального плану.';return ['check'=>$check,'rows'=>$rows,'total'=>0,'existing'=>0,'new'=>0];}
  $dst_map=array_fill_keys($dst,true);
  foreach($src as $post_id){
   $post=get_post($post_id);$already=isset($dst_map[$post_id]);
   $rows[]=['id'=>$post_id,'title'=>$post?get_the_title($post_id):'[Запис не знайдено]','type'=>$post?$post->post_type:'—','status'=>$post?$post->post_status:'—','action'=>$already?'already':'add'];
  }
  usort($rows,static function($a,$b){return ($a['action']==='add'?0:1)<=>($b['action']==='add'?0:1) ?: $a['id']<=>$b['id'];});
  $existing=count(array_filter($rows,static function($r){return $r['action']==='already';}));
  return ['check'=>$check,'rows'=>$rows,'total'=>count($rows),'existing'=>$existing,'new'=>count($rows)-$existing];
 }
 public static function plan_csv(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_plan_csv');
  $queue=self::queue();$staged=self::staged();$rules=self::robots_rules();
  $source=absint($_GET['source']??0);$selected=[];
  foreach($queue as $id=>$entry){if(($entry['kind']??'')!=='merge'||!empty($staged[$id]))continue;if($source&&(int)$id!==$source)continue;$selected[(int)$id]=(int)($entry['target']??0);}
  if($source&&!isset($selected[$source]))wp_die('Немає такого активного рішення в черзі.');
  while(ob_get_level())ob_end_clean();nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ogo-tag-plan-'.gmdate('Y-m-d').'.csv"');
  // Redirection CSV: no header, no BOM; four columns supported by Redirection 5.x.
  $out=fopen('php://output','w');$seen=[];
  foreach($staged as $op){
   if(($op['state']??'')!=='staged')continue;
   $old=wp_parse_url($op['source_url']??'',PHP_URL_PATH);
   $new=wp_parse_url($op['target_url']??'',PHP_URL_PATH);
   if(!is_string($old)||!is_string($new)||$old===''||$new===''||$old===$new)continue;
   if($old[0]!=='/'||$new[0]!=='/'||isset($seen[$old]))continue;
   $seen[$old]=true;
   fputcsv($out,[$old,$new,'0','301']);
  }
  fclose($out);exit;
 }

 // Stable fingerprints of exact source and target relationship sets, not only their counts.
 private static function fingerprint($source,$target){
  $a=self::source_objects($source);$b=self::source_objects($target);
  if($a===null||$b===null)return null;
  sort($a,SORT_NUMERIC);sort($b,SORT_NUMERIC);
  $st=get_term($source,'post_tag');$tt=get_term($target,'post_tag');
  if(!$st||is_wp_error($st)||!$tt||is_wp_error($tt))return null;
  return hash('sha256',wp_json_encode([$source,$target,$st->slug,$tt->slug,$a,$b]));
 }
 public static function queue_changed($old,$new){
  if($old!==$new)update_option(self::REV,(int)get_option(self::REV,0)+1,false);
 }
 private static function queue_signature($queue){
  $items=[];foreach($queue as $id=>$e){$items[(int)$id]=['kind'=>(string)($e['kind']??''),'target'=>(int)($e['target']??0)];}ksort($items,SORT_NUMERIC);return $items;
 }
 private static function queue_state($queue){
  $saved=get_option(self::QSNAP,null);$now=self::queue_signature($queue);$rev=(int)get_option(self::REV,0);
  if(!is_array($saved)||!isset($saved['items']))return ['ok'=>false,'message'=>'Немає знімка історії черги v2.1. Створіть новий контрольний знімок.'];
  $was=$saved['items'];$changes=[];
  foreach($now as $id=>$item){if(!isset($was[$id]))$changes[]='Додано рішення #'.$id;elseif($was[$id]!==$item)$changes[]='Змінено рішення або ціль #'.$id;}
  foreach($was as $id=>$item){if(!isset($now[$id]))$changes[]='Вилучено рішення #'.$id;}
  if((int)($saved['revision']??-1)!==$rev){
   if(!$changes)$changes[]='Черга змінювалася після знімка, навіть якщо тепер її склад знову збігається.';
  }
  return ['ok'=>!$changes,'message'=>$changes?implode('; ',$changes):'Черга не змінювалася після знімка.'];
 }
 private static function link_differences($source,$target,$saved){
  $a=self::source_objects($source);$b=self::source_objects($target);
  if($a===null||$b===null)return 'Не вдалося прочитати зв’язки.';
  $oldA=array_map('intval',$saved['source_objects']??[]);$oldB=array_map('intval',$saved['target_objects']??[]);
  if(!isset($saved['source_objects'],$saved['target_objects']))return 'Старий знімок не містить списків ID. Створіть новий.';
  $notes=[];
  foreach([['джерела',$oldA,$a],['цілі',$oldB,$b]] as $set){$added=array_values(array_diff($set[2],$set[1]));$removed=array_values(array_diff($set[1],$set[2]));if($added)$notes[]='Нові ID у '.$set[0].': '.implode(', ',array_slice($added,0,25));if($removed)$notes[]='Вилучені ID у '.$set[0].': '.implode(', ',array_slice($removed,0,25));}
  $st=get_term($source,'post_tag');$tt=get_term($target,'post_tag');if($st&&!is_wp_error($st)&&$st->slug!==($saved['source_slug']??''))$notes[]='Змінився slug джерела.';if($tt&&!is_wp_error($tt)&&$tt->slug!==($saved['target_slug']??''))$notes[]='Змінився slug цілі.';
  return $notes?implode('; ',$notes):'Поточні зв’язки і slug збігаються.';
 }
 private static function snapshots(){ $v=get_option(self::SNAP,[]);return is_array($v)?$v:[]; }
 public static function snapshot(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_snapshot');
  $queue=self::queue();$staged=self::staged();$all=[];$rules=self::robots_rules();
  foreach($queue as $id=>$entry){$id=(int)$id;$target=(int)($entry['target']??0);
   if(($entry['kind']??'')!=='merge'||isset($staged[$id]))continue;
   $check=self::preflight($id,$target,$queue,$rules);$fp=self::fingerprint($id,$target);
   if($fp!==null&&!$check['errors'])$all[$id]=['target'=>$target,'hash'=>$fp,'at'=>current_time('mysql'),'source_objects'=>self::source_objects($id),'target_objects'=>self::source_objects($target),'source_slug'=>get_term($id,'post_tag')->slug,'target_slug'=>get_term($target,'post_tag')->slug];
  }
  update_option(self::SNAP,$all,false);update_option(self::QSNAP,['revision'=>(int)get_option(self::REV,0),'items'=>self::queue_signature($queue),'at'=>current_time('mysql')],false);
  $return=isset($_POST['return_tab'])&&sanitize_key(wp_unslash($_POST['return_tab']))==='workflow'?'workflow':'plan';wp_safe_redirect(admin_url('tools.php?page='.self::SLUG.'&tab='.$return.'&snapshot_saved=1'));exit;
 }
 private static function redirect_diagnostics($from,$to){
  $notes=[];if(!$from||!$to)return ['Неможливо перевірити URL.'];
  $f=wp_parse_url($from,PHP_URL_PATH);$t=wp_parse_url($to,PHP_URL_PATH);
  if(!is_string($f)||!is_string($t))return ['Некоректні шляхи URL.'];
  if(rawurldecode(rtrim($f,'/'))===rawurldecode(rtrim($t,'/')))$notes[]='КОНФЛІКТ: однакові шляхи після декодування.';
  if(wp_parse_url($from,PHP_URL_HOST)!==wp_parse_url($to,PHP_URL_HOST))$notes[]='УВАГА: різні домени.';
  // wp_old_slug_redirect and Redirection rules may be dynamic. We do not claim a full conflict check.
  $notes[]='Наявні правила Redirection, серверні rewrite, Yoast та кеш автоматично НЕ перевірені. Перевірити вручну перед імпортом.';
  return $notes;
 }
 public static function control_csv(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_control_csv');
  $queue=self::queue();$snap=self::snapshots();$rules=self::robots_rules();$staged=self::staged();
  while(ob_get_level())ob_end_clean();nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ogo-tag-control-'.gmdate('Y-m-d').'.csv"');
  $o=fopen('php://output','w');fwrite($o,"\xEF\xBB\xBF");fputcsv($o,['source_id','source_name','target_id','target_name','source_count','already_target','new_links','snapshot_state','preflight','source_url','target_url','url_check','snapshot_time']);
  foreach($queue as $id=>$entry){$id=(int)$id;$target=(int)($entry['target']??0);if(($entry['kind']??'')!=='merge')continue;
   $plan=self::migration_plan($id,$target,$queue,$rules);$check=$plan['check'];$hash=self::fingerprint($id,$target);
   $saved=$snap[$id]??null;$state=isset($staged[$id])?'STAGED':(!$saved?'NOT_SAVED':(($saved['target']===$target&&$hash!==null&&hash_equals($saved['hash'],$hash))?'UNCHANGED':'CHANGED'));
   $a=get_term($id,'post_tag');$b=get_term($target,'post_tag');$diag=implode(' | ',self::redirect_diagnostics($check['from']??'',$check['to']??''));
   $values=[$id,$a&&!is_wp_error($a)?$a->name:'',$target,$b&&!is_wp_error($b)?$b->name:'',$plan['total'],$plan['existing'],$plan['new'],$state,$check['errors']?'BLOCKED':'REVIEW',$check['from']??'',$check['to']??'',$diag,$saved['at']??''];
   $values=array_map(static function($v){$v=(string)$v;return preg_match('/^[\s\x00-\x1F]*[=+@-]/u',$v)?"'".$v:$v;},$values);fputcsv($o,$values);
  }
  fclose($o);exit;
 }
 private static function paged($rows,$per=50){$page=isset($_GET['paged'])?max(1,absint($_GET['paged'])):1;$total=count($rows);$pages=max(1,(int)ceil($total/$per));$page=min($page,$pages);$base=remove_query_arg('paged');echo '<p>Показано '.esc_html(min($total,($page-1)*$per+1)).'–'.esc_html(min($total,$page*$per)).' із '.esc_html($total).'</p>';echo '<div class="tablenav"><div class="tablenav-pages">';if($page>1)echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page-1,$base)).'">← Назад</a> ';echo esc_html($page.' / '.$pages);if($page<$pages)echo ' <a class="button" href="'.esc_url(add_query_arg('paged',$page+1,$base)).'">Далі →</a>';echo '</div></div>';return array_slice($rows,($page-1)*$per,$per);}
 private static function redirection_rest($method,$route,$params=[]){
  if(!function_exists('rest_do_request'))return new WP_Error('ogo_no_rest','WordPress REST недоступний.');
  $req=new WP_REST_Request($method,$route);
  foreach($params as $key=>$value)$req->set_param($key,$value);
  return rest_do_request($req);
 }
 private static function test_log($messages){set_transient('ogo_redirection_test_'.get_current_user_id(),$messages,10*MINUTE_IN_SECONDS);}
 // Search nested Redirection response structures without mistaking unrelated IDs for redirect IDs.
 private static function find_test_ids($data,$source,$depth=0){
  if($depth>8||!is_array($data))return [];
  $found=[];
  if(isset($data['url'])&&is_string($data['url'])&&$data['url']===$source&&isset($data['id'])&&absint($data['id']))$found[]=absint($data['id']);
  foreach($data as $value)if(is_array($value))$found=array_merge($found,self::find_test_ids($value,$source,$depth+1));
  return array_values(array_unique($found));
 }
 // Read Redirection entries without filterBy: v5.10.1 requires an array for that parameter.
 // Fail closed if pagination or response shape cannot be verified.
 private static function test_rule_ids($source){
  $found=[];$per=200;$max_pages=100;
  for($page=1;$page<=$max_pages;$page++){
   $res=self::redirection_rest('GET','/redirection/v1/redirect',['page'=>$page,'per_page'=>$per]);
   if(is_wp_error($res))return $res;
   if($res->is_error())return new WP_Error('ogo_read_failed','Помилка читання Redirection: '.wp_json_encode($res->get_data(),JSON_UNESCAPED_UNICODE));
   $data=$res->get_data();
   if(!is_array($data))return new WP_Error('ogo_bad_response','Невідомий формат відповіді Redirection.');
   if(isset($data['items'])&&is_array($data['items']))$items=$data['items'];
   elseif(isset($data['redirects'])&&is_array($data['redirects']))$items=$data['redirects'];
   elseif(isset($data['results'])&&is_array($data['results']))$items=$data['results'];
   elseif((array_keys($data)===range(0,count($data)-1)))$items=$data;
   else return new WP_Error('ogo_bad_response','Не вдалося знайти список правил у відповіді Redirection.');
   $found=array_merge($found,self::find_test_ids($items,$source));
   $total=isset($data['total'])&&is_numeric($data['total'])?(int)$data['total']:null;
   $pages=isset($data['pages'])&&is_numeric($data['pages'])?(int)$data['pages']:null;
   if($pages!==null&&$page>=$pages)return array_values(array_unique($found));
   if($total!==null&&$page*$per>=$total)return array_values(array_unique($found));
   if(count($items)<$per)return array_values(array_unique($found));
  }
  return new WP_Error('ogo_too_many_pages','Не вдалося перевірити всі сторінки правил Redirection (ліміт 100). Операцію зупинено.');
 }
 private static function test_pending_key(){return 'ogo_redirection_pending_'.get_current_user_id();}
 public static function redirection_test(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');
  check_admin_referer('ogo_redirection_test');
  $back=add_query_arg(['page'=>self::SLUG,'tab'=>'integration'],admin_url('tools.php'));
  $messages=[];
  if(!class_exists('Red_Item',false)||!class_exists('Red_Group',false)){$messages[]='ПОМИЛКА: Redirection не активний.';self::test_log($messages);wp_safe_redirect($back);exit;}
  $pending=get_option(self::test_pending_key());
  if(is_array($pending)&&!empty($pending['source'])){
   $source=$pending['source'];$messages[]='Попередній тест: '.$source;
   $ids=self::test_rule_ids($source);
   if(is_wp_error($ids)){$messages[]='ЗУПИНЕНО: не вдалося перевірити попередній тест: '.$ids->get_error_message();self::test_log($messages);wp_safe_redirect($back);exit;}
   if($ids){$messages[]='ЗУПИНЕНО: попереднє тестове правило ще існує (ID: '.implode(', ',$ids).'). Видаліть його вручну в Redirection і повторіть тест.';self::test_log($messages);wp_safe_redirect($back);exit;}
   delete_option(self::test_pending_key());$messages[]='Попереднє правило не знайдено, можна створювати новий тест.';
  }
  $groups=self::redirection_rest('GET','/redirection/v1/group');
  if(is_wp_error($groups)||$groups->is_error()){$messages[]='ПОМИЛКА: немає доступу до груп Redirection.';self::test_log($messages);wp_safe_redirect($back);exit;}
  $data=$groups->get_data();$groupId=0;$candidates=[];
  if(is_array($data)){
   if(isset($data['items'])&&is_array($data['items']))$candidates=$data['items'];
   elseif(isset($data['groups'])&&is_array($data['groups']))$candidates=$data['groups'];
   elseif(array_keys($data)===range(0,count($data)-1))$candidates=$data;
  }
  foreach($candidates as $candidate){if(!is_array($candidate))continue;$id=absint($candidate['id']??0);$status=$candidate['status']??'enabled';if($id&&$status!=='disabled'){$groupId=$id;break;}}
  if(!$groupId){$messages[]='ЗУПИНЕНО: не вдалося визначити активну групу Redirection.';self::test_log($messages);wp_safe_redirect($back);exit;}
  $token=wp_generate_password(24,false,false);
  $source='/__ogo-tag-manager-test-'.$token.'/';$target='/__ogo-tag-manager-test-target-'.$token.'/';
  // Persist the source BEFORE making a write: interrupted requests remain discoverable.
  update_option(self::test_pending_key(),['source'=>$source,'created'=>time()],false);
  $messages[]='Тестова адреса: '.$source;
  $created=self::redirection_rest('POST','/redirection/v1/redirect',[
   'url'=>$source,'action_type'=>'url','action_code'=>301,'action_data'=>['url'=>$target],
   'match_type'=>'url','match_data'=>['source'=>['flag_query'=>'ignore','flag_case'=>false,'flag_trailing'=>false]],
   'group_id'=>$groupId,'title'=>'OGO Tag Manager temporary integration test'
  ]);
  if(is_wp_error($created)||$created->is_error()){
   $messages[]='Створення не підтверджене: '.(is_wp_error($created)?$created->get_error_message():wp_json_encode($created->get_data(),JSON_UNESCAPED_UNICODE));
   $messages[]='Перевірте Redirection за адресою '.$source.'. Новий тест заблокований, поки залишок не перевірено.';
   self::test_log($messages);wp_safe_redirect($back);exit;
  }
  $messages[]='API прийняв запит створення.';
  $ids=self::test_rule_ids($source);
  if(is_wp_error($ids)||!$ids){
   $messages[]='УВАГА: правило не вдалося знайти у списку за точною адресою. Можливо, API використовує іншу структуру відповіді або фільтр. Перевірте вручну: '.$source;
   self::test_log($messages);wp_safe_redirect($back);exit;
  }
  if(count($ids)!==1){$messages[]='УВАГА: знайдено '.count($ids).' правил з однаковою адресою ('.implode(', ',$ids).'). Автовидалення скасовано.';self::test_log($messages);wp_safe_redirect($back);exit;}
  $id=$ids[0];$messages[]='Підтверджено існування тестового правила #'.$id.'.';
  $deleted=self::redirection_rest('POST','/redirection/v1/bulk/redirect/delete',['items'=>[$id]]);
  if(is_wp_error($deleted)||$deleted->is_error()){
   $messages[]='УВАГА: команда видалення завершилася помилкою: '.(is_wp_error($deleted)?$deleted->get_error_message():wp_json_encode($deleted->get_data(),JSON_UNESCAPED_UNICODE));
   self::test_log($messages);wp_safe_redirect($back);exit;
  }
  $remaining=self::test_rule_ids($source);
  if(is_wp_error($remaining))$messages[]='УВАГА: видалення запитано, але повторна перевірка не вдалася: '.$remaining->get_error_message();
  elseif($remaining)$messages[]='УВАГА: правило досі присутнє (ID: '.implode(', ',$remaining).'). Потрібне ручне видалення.';
  else{$messages[]='УСПІХ: правило створено, знайдено за ID, видалено та перевірено його відсутність.';delete_option(self::test_pending_key());}
  self::test_log($messages);wp_safe_redirect($back);exit;
 }

 public static function author_box(){
  if(!current_user_can('edit_posts'))return;
  add_meta_box('ogo-tag-author-helper','OGO — Підібрати теги',[__CLASS__,'author_box_html'],'post','side','default');
 }
 public static function author_box_html($post){
  if(!current_user_can('edit_post',$post->ID))return;
  echo '<p>Підказки серед <strong>наявних</strong> позначок. Нові теги не створюються автоматично.</p><p><button type="button" class="button" id="ogo-tag-suggest-btn">Підібрати теги</button></p><div id="ogo-tag-suggest-status" aria-live="polite"></div><div id="ogo-tag-suggest-results"></div>';
  $config=['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ogo_tag_suggest'),'post'=>(int)$post->ID];
  echo '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.getElementById("ogo-tag-suggest-btn"),status=document.getElementById("ogo-tag-suggest-status"),results=document.getElementById("ogo-tag-suggest-results");if(!b)return;var cfg='.wp_json_encode($config).';function text(el,s){el.textContent=s;}b.addEventListener("click",function(){b.disabled=true;text(status,"Шукаємо відповідні теги…");results.replaceChildren();var title=document.getElementById("title"),content=document.getElementById("content"),body=new URLSearchParams();body.set("action","ogo_tag_suggest");body.set("nonce",cfg.nonce);body.set("post_id",cfg.post);body.set("title",title?title.value:"");var editor=window.tinymce&&window.tinymce.get("content");body.set("content",editor&&!editor.isHidden()?editor.getContent({format:"text"}):(content?content.value:""));fetch(cfg.ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()}).then(function(r){return r.json()}).then(function(data){if(!data.success)throw Error((data.data&&data.data.message)||"Помилка запиту");var items=data.data.items||[];text(status,items.length?"Перші 3 — пріоритетні. Інші — додаткові варіанти. Обирайте лише доречні теми:":"Точних тематичних збігів не знайдено. Можна додати тег вручну.");items.forEach(function(item,i){var wrap=document.createElement("div");wrap.style.margin="7px 0";var add=document.createElement("button");add.type="button";add.className="button button-small";text(add,"+ "+item.name);add.title="Статей із тегом: "+item.count;add.addEventListener("click",function(){var field=document.getElementById("new-tag-post_tag"),box=document.getElementById("tagsdiv-post_tag");if(!field||!box){text(status,"Не знайдено стандартне поле тегів Classic Editor.");return;}field.value=item.name;var submit=box.querySelector("input.tagadd,button.tagadd");if(!submit){text(status,"Не знайдено кнопку додавання тегу.");return;}submit.click();add.disabled=true;add.textContent="Додано: "+item.name;});wrap.appendChild(add);var count=document.createElement("small");text(count," · "+item.count+" статей · "+item.reason+(item.canonical?" · основний тег":"")+(i<3?" · рекомендовано":""));wrap.appendChild(count);results.appendChild(wrap);});}).catch(function(e){text(status,e.message||"Помилка");}).finally(function(){b.disabled=false;});});});</script>';
 }
 public static function author_suggest(){
  check_ajax_referer('ogo_tag_suggest','nonce');
  $post=absint($_POST['post_id']??0);
  if(!$post||!current_user_can('edit_post',$post))wp_send_json_error(['message'=>'Недостатньо прав.'],403);
  $title=mb_strtolower(mb_substr(sanitize_text_field(wp_unslash($_POST['title']??'')),0,250,'UTF-8'),'UTF-8');
  $content=mb_strtolower(mb_substr(wp_strip_all_tags(wp_unslash($_POST['content']??'')),0,15000,'UTF-8'),'UTF-8');
  $normalize=static function($v){$v=mb_strtolower((string)$v,'UTF-8');$v=str_replace(['’','‘','ʼ','`','´','\u{02BC}'],"'",$v);return preg_replace('/[^\p{L}\p{N}]+/u',' ',trim($v));};
  $t=' '.$normalize($title).' ';$c=' '.$normalize($content).' ';
  $queue=self::queue();$terms=get_terms(['taxonomy'=>'post_tag','hide_empty'=>true,'number'=>0]);
  if(is_wp_error($terms))wp_send_json_error(['message'=>'Не вдалося отримати теги.'],500);
  $byid=[];foreach($terms as $term)$byid[(int)$term->term_id]=$term;
  $existing=wp_get_post_terms($post,'post_tag',['fields'=>'ids']);$existing=is_wp_error($existing)?[]:array_map('intval',$existing);
  $ranked=[];
  foreach($terms as $term){
   $source=(int)$term->term_id;$q=$queue[$source]??null;
   // A rejected merge does not disable this tag in author suggestions.
   $target=$source;
   if($q&&($q['kind']??'')==='merge'){$target=(int)($q['target']??0);if(!$target||!isset($byid[$target])||isset($queue[$target])&&($queue[$target]['kind']??'')==='merge')continue;}
   if(in_array($target,$existing,true))continue;
   $name=' '.$normalize($term->name).' ';$len=mb_strlen(trim($name),'UTF-8');
   if($len<5)continue;
   // Match whole phrases, never substrings inside unrelated words (e.g. тест -> протест).
   $in_title=mb_strpos($t,$name)!==false;$in_body=mb_strpos($c,$name)!==false;
   if(!$in_title&&!$in_body)continue;
   $score=($in_title?30:0)+($in_body?10:0)+min(10,(int)floor($len/4));
   if(!isset($ranked[$target])||$score>$ranked[$target]['score'])$ranked[$target]=['id'=>$target,'name'=>$byid[$target]->name,'count'=>(int)$byid[$target]->count,'score'=>$score,'reason'=>$in_title?'Точна фраза в заголовку':'Точна фраза в тексті','canonical'=>$source!==$target];
  }
  uasort($ranked,static function($a,$b){return $b['score']<=>$a['score']?:$b['count']<=>$a['count'];});
  $items=[];foreach(array_slice(array_values($ranked),0,10) as $r)$items[]=$r;
  wp_send_json_success(['items'=>$items,'recommended'=>min(3,count($items))]);
 }
 private static function journal($event,$source,$target,$details=[]){
  $rows=get_option(self::JOURNAL,[]);if(!is_array($rows))$rows=[];
  $rows[]=['time'=>current_time('mysql'),'user'=>get_current_user_id(),'event'=>$event,'source'=>(int)$source,'target'=>(int)$target,'details'=>$details];
  if(count($rows)>500)$rows=array_slice($rows,-500);
  update_option(self::JOURNAL,$rows,false);
 }
 public static function verify_stage(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_verify');
  $source=absint($_POST['source']??0);$staged=self::staged();$op=$staged[$source]??null;
  if(!$op||($op['state']??'')!=='staged')wp_die('Немає завершеного перенесення.');
  $target=(int)$op['target'];$src=self::source_objects($source);$dst=self::source_objects($target);
  if($src===null||$dst===null)$result='Помилка читання зв’язків.';
  else{
   $missing=array_diff($src,$dst);$lost=array_diff(array_map('intval',$op['objects']??[]),$src);
   $not_added=array_diff(array_map('intval',$op['added']??[]),$dst);
   $result=(!$missing&&!$lost&&!$not_added?'OK: зв’язки збережені.':'УВАГА: виявлено відмінності.');
   $result.=' Джерело: '.count($src).'; ціль: '.count($dst).'; відсутні в цілі: '.count($missing).'; втрачені з джерела: '.count($lost).'; додані модулем відсутні: '.count($not_added).'.';
  }
  $staged[$source]['last_verify']=['at'=>current_time('mysql'),'result'=>$result];update_option(self::STAGED,$staged,false);
  self::journal('verify',$source,$target,['result'=>$result]);
  self::finish_action(admin_url('tools.php?page='.self::SLUG.'&tab=queue&verified=1'));exit;
 }
 public static function verify_batch(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_verify_batch');
  $staged=self::staged();$processed=0;$ok=0;$failed=0;$skipped=0;
  foreach($staged as $source=>$op){
   if($processed>=50)break;
   if(($op['state']??'')!=='staged'||strpos((string)($op['last_verify']['result']??''),'OK:')===0)continue;
   $source=(int)$source;$target=(int)($op['target']??0);
   $src=self::source_objects($source);$dst=self::source_objects($target);
   if($src===null||$dst===null){$result='Помилка читання зв’язків.';}
   else{
    $missing=array_diff($src,$dst);$lost=array_diff(array_map('intval',$op['objects']??[]),$src);
    $not_added=array_diff(array_map('intval',$op['added']??[]),$dst);
    $result=(!$missing&&!$lost&&!$not_added?'OK: зв’язки збережені.':'УВАГА: виявлено відмінності.');
    $result.=' Джерело: '.count($src).'; ціль: '.count($dst).'; відсутні в цілі: '.count($missing).'; втрачені з джерела: '.count($lost).'; додані модулем відсутні: '.count($not_added).'.';
   }
   $staged[$source]['last_verify']=['at'=>current_time('mysql'),'result'=>$result];
   self::journal('verify',$source,$target,['result'=>$result]);
   $processed++;if(strpos($result,'OK:')===0)$ok++;else $failed++;
  }
  update_option(self::STAGED,$staged,false);
  $return=isset($_POST['return_tab'])&&sanitize_key(wp_unslash($_POST['return_tab']))==='workflow'?'workflow':'queue';wp_safe_redirect(add_query_arg(['page'=>self::SLUG,'tab'=>$return,'batch_verified'=>$ok,'batch_failed'=>$failed],admin_url('tools.php')));exit;
 }
 private static function packages($queue){
  $packs=[];foreach($queue as $sid=>$e){if(($e['kind']??'')!=='merge')continue;$tid=(int)($e['target']??0);if($tid>0)$packs[$tid][]=(int)$sid;}
  ksort($packs,SORT_NUMERIC);foreach($packs as &$sources)sort($sources,SORT_NUMERIC);unset($sources);return $packs;
 }
 public static function package_csv(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_package_csv');
  $tid=absint($_GET['target']??0);$queue=self::queue();$packs=self::packages($queue);if(!$tid||empty($packs[$tid]))wp_die('Пакет не знайдено.');
  $staged=self::staged();$snap=self::snapshots();$qstate=self::queue_state($queue);$rules=self::robots_rules();
  while(ob_get_level())ob_end_clean();nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ogo-tag-package-'.$tid.'-'.gmdate('Ymd').'.csv"');
  $f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['source_id','source_name','target_id','target_name','source_links','missing_links','snapshot_current','queue_current','migration_state','source_url','target_url','preflight_errors','preflight_warnings']);
  foreach($packs[$tid] as $sid){$a=get_term($sid,'post_tag');$b=get_term($tid,'post_tag');$ids=self::source_objects($sid);$dst=self::source_objects($tid);$saved=$snap[$sid]??null;$fp=self::fingerprint($sid,$tid);$ok=$saved&&$fp!==null&&(int)$saved['target']===$tid&&hash_equals((string)$saved['hash'],$fp);$check=self::preflight($sid,$tid,$queue,$rules);$op=$staged[$sid]??null;
   fputcsv($f,[$sid,$a&&!is_wp_error($a)?$a->name:'',$tid,$b&&!is_wp_error($b)?$b->name:'',$ids===null?'':count($ids),$ids===null||$dst===null?'':count(array_diff($ids,$dst)),$ok?'yes':'no',$qstate['ok']?'yes':'no',$op['state']??'not_started',$check['from']??'',$check['to']??'',implode('; ',$check['errors']),implode('; ',$check['warnings'])]);
  }fclose($f);exit;
 }
 private static function staged(){ $s=get_option(self::STAGED,[]);return is_array($s)?$s:[]; }
 private static function source_objects($id){global $wpdb;$tt=get_term($id,'post_tag');if(!$tt||is_wp_error($tt))return null;return array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id=%d",(int)$tt->term_taxonomy_id)));}
 public static function stage_confirm(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_stage_confirm');
  $source=absint($_POST['source']??0);$target=absint($_POST['target']??0);$queue=self::queue();$snap=self::snapshots();
  $qs=self::queue_state($queue);$hash=self::fingerprint($source,$target);
  if(!$qs['ok']||!isset($queue[$source])||($queue[$source]['kind']??'')!=='merge'||(int)$queue[$source]['target']!==$target||!isset($snap[$source])||$hash===null||!hash_equals((string)$snap[$source]['hash'],$hash))wp_die('План застарів. Поверніться до плану та створіть новий знімок.');
  $check=self::preflight($source,$target,$queue,self::robots_rules());if($check['errors'])wp_die('Попередня перевірка має блокування.');
  $a=get_term($source,'post_tag');$b=get_term($target,'post_tag');$preview=self::preview($source,$target);
  echo '<!doctype html><html lang="uk"><head><meta charset="utf-8"><title>Підтвердження перенесення — OGO</title>';wp_admin_css('wp-admin',true);echo '</head><body class="wp-admin"><div class="wrap" style="max-width:780px;margin:40px auto;padding:25px;background:#fff"><h1>Останнє підтвердження перенесення</h1>';
  echo '<p><strong>'.esc_html($a->name).' (#'.$source.') → '.esc_html($b->name).' (#'.$target.')</strong></p>';
  echo '<p>Нових зв’язків: <strong>'.(int)($preview['new']??0).'</strong>. Джерело не видаляється, 301 не створюється.</p><p><strong>Це зміна робочої бази.</strong> Потрібна перевірена тестова копія й резервна копія.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ogo_tag_manager_stage"><input type="hidden" name="source" value="'.$source.'"><input type="hidden" name="target" value="'.$target.'">';wp_nonce_field('ogo_tag_stage');
  echo '<p><label><input type="checkbox" name="confirm_backup" value="1" required> Я перевірив резервну копію та тестове відновлення.</label></p><p><label>Введіть <strong>ПЕРЕНЕСТИ</strong>: <input name="confirm_word" required autocomplete="off"></label></p><button class="button button-primary" type="submit">Підтвердити перенесення</button> <a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=queue')).'">Скасувати</a></form></div></body></html>';exit;
 }
 public static function stage(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_stage');if(empty($_POST['confirm_backup'])||sanitize_text_field(wp_unslash($_POST['confirm_word']??''))!=='ПЕРЕНЕСТИ')wp_die('Потрібне окреме підтвердження операції.');
  $source=absint($_POST['source']??0);$target=absint($_POST['target']??0);
  $result=self::execute_one($source,$target);
  if($result!==true)wp_die('Перенесення не виконано: '.esc_html($result));
  wp_safe_redirect(admin_url('tools.php?page='.self::SLUG.'&tab=queue&stage_result=1'));exit;
 }
 private static function execute_one($source,$target,$package_approved=false){
  $queue=self::queue();$staged=self::staged();
  if(!$source||!$target||$source===$target||isset($staged[$source])||!isset($queue[$source])||($queue[$source]['kind']??'')!=='merge'||(int)$queue[$source]['target']!==$target)return 'Операція не відповідає черзі або вже виконана.';
  // An explicitly confirmed package uses per-source queue decisions and fresh fingerprints.
  // Global queue-history changes (including the user's own newly added decisions)
  // must not block an otherwise unchanged package. Standalone execution keeps
  // the original strict global queue-history guard.
  if(!$package_approved){$qs=self::queue_state($queue);if(!$qs['ok'])return 'ЗУПИНЕНО: '.$qs['message'];}
  $check=self::preflight($source,$target,$queue,self::robots_rules());if(!empty($check['errors']))return 'Перевірка не пройдена: '.implode('; ',$check['errors']);
  $saved=self::snapshots();$current=self::fingerprint($source,$target);if(!isset($saved[$source])||(int)$saved[$source]['target']!==$target||$current===null||!hash_equals($saved[$source]['hash'],$current))return 'Застарілий контрольний знімок.';
  $a=get_term($source,'post_tag');$b=get_term($target,'post_tag');if(!$a||is_wp_error($a)||!$b||is_wp_error($b))return 'Тег не знайдено.';
  $ids=self::source_objects($source);$before=self::source_objects($target);if($ids===null||$before===null)return 'Не вдалося отримати зв’язки.';
  if(count($ids)>20000)return 'Надто багато зв’язків для однієї операції.';
  $new=array_values(array_diff($ids,$before));$snapshot=['source'=>$source,'target'=>$target,'source_name'=>$a->name,'source_slug'=>$a->slug,'target_slug'=>$b->slug,'source_url'=>is_wp_error(get_term_link($a))?'':get_term_link($a),'target_url'=>is_wp_error(get_term_link($b))?'':get_term_link($b),'objects'=>$ids,'added'=>$new,'at'=>current_time('mysql'),'user'=>get_current_user_id(),'state'=>'running'];
  // Save the snapshot before the first write, so a failed operation can be investigated.
  $staged[$source]=$snapshot;update_option(self::STAGED,$staged,false);
  $done=[];
  foreach($new as $object_id){$result=wp_add_object_terms($object_id,$target,'post_tag');if(is_wp_error($result)){foreach($done as $rollback_id)wp_remove_object_terms($rollback_id,$target,'post_tag');unset($staged[$source]);update_option(self::STAGED,$staged,false);return 'Помилка додавання зв’язків, перевірте статті вручну.';}$done[]=$object_id;}
  $after=self::fingerprint($source,$target);
  if($after===null){$staged[$source]['state']='needs_review';$staged[$source]['added']=$done;update_option(self::STAGED,$staged,false);return 'Контрольний відбиток не створено, потрібна ручна перевірка.';}
  $staged[$source]['state']='staged';$staged[$source]['added']=$done;$staged[$source]['after_hash']=$after;update_option(self::STAGED,$staged,false);self::journal('stage',$source,$target,['added'=>count($done),'source_links'=>count($ids)]);
  return true;
 }
 public static function package_confirm(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_package_confirm');
  $target=absint($_POST['target']??0);$packs=self::packages(self::queue());$sources=$packs[$target]??[];
  if(!$sources)wp_die('Пакет не знайдено.');
  $pending=array_values(array_filter($sources,static function($id){$ops=self::staged();return !isset($ops[$id]);}));
  // v3.4: Prepare a fresh, source-specific control snapshot as part of the explicit
  // package confirmation screen. Unrelated queue edits must not invalidate a package.
  // The execution endpoint still re-checks the saved fingerprints before every write.
  $total=0;$issues=[];$queue=self::queue();$snap=self::snapshots();$rules=self::robots_rules();
  if(count($pending)>5)$issues[]='Перевищено ліміт 5 джерел.';
  foreach($pending as $sid){
   $a=self::source_objects($sid);$b=self::source_objects($target);$hash=self::fingerprint($sid,$target);
   $check=self::preflight($sid,$target,$queue,$rules);
   if($a===null||$b===null||$hash===null||!empty($check['errors'])){$issues[]='Потрібна перевірка тегу #'.$sid;continue;}
   $st=get_term($sid,'post_tag');$tt=get_term($target,'post_tag');
   $snap[$sid]=['target'=>$target,'hash'=>$hash,'at'=>current_time('mysql'),
    'source_objects'=>$a,'target_objects'=>$b,'source_slug'=>$st->slug,'target_slug'=>$tt->slug];
   $total+=count(array_diff($a,$b));
  }
  if(!$issues&&$pending&&$total<=500)update_option(self::SNAP,$snap,false);
  echo '<!doctype html><html lang="uk"><head><meta charset="utf-8"><title>Пакет OGO</title>';wp_admin_css('wp-admin',true);echo '</head><body class="wp-admin"><div class="wrap" style="max-width:780px;margin:40px auto;padding:25px;background:white"><h1>Підтвердження пакета #'.(int)$target.'</h1><p>Джерел до перенесення: '.count($pending).' · нових зв’язків: '.(int)$total.'. Старі теги та URL залишаться без змін.</p>';
  if($issues||count($pending)>5||$total>500||!$pending){echo '<div class="notice notice-error"><p>Виконання заблоковано: '.esc_html(implode('; ',$issues)?:'Пакет порожній або перевищує ліміт 5 джерел / 500 зв’язків.').'</p></div>';}
  else {echo '<p><strong>Контрольний план щойно оновлено для цього пакета.</strong> Перед записом зв’язків буде ще одна автоматична перевірка. Це змінює робочу базу; потрібне перевірене тестове відновлення.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ogo_tag_manager_package_execute"><input type="hidden" name="target" value="'.(int)$target.'">';wp_nonce_field('ogo_tag_package_execute');echo '<p><label><input type="checkbox" name="confirm_backup" value="1" required> Перевірив резервну копію і тестове відновлення</label></p><p>Введіть <strong>ПАКЕТ</strong>: <input name="confirm_word" required autocomplete="off"></p><button class="button button-primary">Перенести весь пакет</button></form>';}
  echo '<p><a href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=finish')).'">Повернутися</a></p></div></body></html>';exit;
 }
 public static function package_execute(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_package_execute');
  if(empty($_POST['confirm_backup'])||sanitize_text_field(wp_unslash($_POST['confirm_word']??''))!=='ПАКЕТ')wp_die('Підтвердження не пройдено.');
  $target=absint($_POST['target']??0);$packs=self::packages(self::queue());$sources=$packs[$target]??[];$staged=self::staged();$pending=array_values(array_filter($sources,static function($sid)use($staged){return !isset($staged[$sid]);}));
  if(!$pending||count($pending)>5)wp_die('Пакет порожній або завеликий.');
  // Revalidate every source immediately before any write, including the overall operation limit.
  $queue=self::queue();$snap=self::snapshots();$total=0;
  foreach($pending as $sid){$a=self::source_objects($sid);$b=self::source_objects($target);$fp=self::fingerprint($sid,$target);$saved=$snap[$sid]??null;$check=self::preflight($sid,$target,$queue,self::robots_rules());
   if($a===null||$b===null||!$saved||$fp===null||(int)$saved['target']!==$target||!hash_equals((string)$saved['hash'],$fp)||!empty($check['errors']))wp_die('Контроль пакета не пройдено для #'.(int)$sid);
   $total+=count(array_diff($a,$b));
  }
  if($total>500)wp_die('Перевищено ліміт 500 зв’язків.');
  $done=[];$failed='';$expected=self::source_objects($target);sort($expected,SORT_NUMERIC);
  foreach($pending as $sid){
   // Previous source adds to the shared target. Only advance the remaining snapshots if
   // the target now equals precisely the expected union (no concurrent editorial edits).
   $before_source=self::source_objects($sid);$r=self::execute_one((int)$sid,$target,true);
   if($r!==true){$failed='Тег #'.$sid.': '.$r;break;}$done[]=$sid;
   $expected=array_values(array_unique(array_merge($expected,$before_source)));sort($expected,SORT_NUMERIC);
   $actual=self::source_objects($target);if($actual===null){$failed='Не вдалося перевірити ціль після #'.$sid;break;}sort($actual,SORT_NUMERIC);
   if($actual!==$expected){$failed='Ціль змінилася неочікувано після #'.$sid;break;}
   // v3.4: Immediately verify the staged source. A failed verification blocks
   // subsequent sources; deletion remains a separate, manual step.
   $staged_now=self::staged();$op=$staged_now[$sid]??null;
   $src_now=self::source_objects($sid);
   $missing=($src_now===null)?[1]:array_diff($src_now,$actual);
   $lost=($src_now===null||!$op)?[1]:array_diff(array_map('intval',$op['objects']??[]),$src_now);
   $not_added=(!$op)?[1]:array_diff(array_map('intval',$op['added']??[]),$actual);
   $result=(!$missing&&!$lost&&!$not_added?'OK: зв’язки збережені.':'УВАГА: виявлено відмінності.');
   $result.=' Джерело: '.($src_now===null?'?':count($src_now)).'; ціль: '.count($actual).'; відсутні в цілі: '.count($missing).'; втрачені з джерела: '.count($lost).'; додані модулем відсутні: '.count($not_added).'.';
   if($op){$staged_now[$sid]['last_verify']=['at'=>current_time('mysql'),'result'=>$result];update_option(self::STAGED,$staged_now,false);self::journal('verify',$sid,$target,['result'=>$result]);}
   if($missing||$lost||$not_added){$failed='Автоматична перевірка не пройшла для #'.$sid;break;}

   // Refresh only remaining snapshots for this already-approved package.
   $snap=self::snapshots();foreach($pending as $next){if(in_array($next,$done,true))continue;
    $newfp=self::fingerprint($next,$target);if($newfp===null){$failed='Не вдалося оновити контроль #'.$next;break;}
    if(isset($snap[$next])&&(int)$snap[$next]['target']===$target)$snap[$next]['hash']=$newfp;
   }
   if($failed)break;update_option(self::SNAP,$snap,false);
  }
  self::journal('package',$target,$target,['completed'=>count($done),'failed'=>$failed]);
  wp_safe_redirect(add_query_arg(['page'=>self::SLUG,'tab'=>'finish','package_done'=>count($done),'package_error'=>$failed?rawurlencode($failed):''],admin_url('tools.php')));exit;
 }
 public static function undo_stage(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_undo_stage');$source=absint($_POST['source']??0);$staged=self::staged();$op=$staged[$source]??null;
  if(!$op||($op['state']??'')!=='staged')wp_die('Немає завершеного перенесення для відкату.');
  $target=(int)$op['target'];
  // Fail closed: a rollback must never remove links after editorial changes to either term.
  $current=self::fingerprint($source,$target);
  if(empty($op['after_hash'])||$current===null||!hash_equals((string)$op['after_hash'],$current))wp_die('Відкат заблоковано: після перенесення змінилися зв’язки або slug, або операція виконана старішою версією без контрольного відбитка. Не видаляйте зв’язки автоматично; перевірте журнал і матеріали вручну.');
  $errors=[];
  foreach($op['added'] as $id){$r=wp_remove_object_terms((int)$id,$target,'post_tag');if(is_wp_error($r))$errors[]=(int)$id;}
  if($errors){$staged[$source]['state']='needs_review';$staged[$source]['undo_errors']=$errors;}else unset($staged[$source]);
  update_option(self::STAGED,$staged,false);self::journal($errors?'undo_partial':'undo',$source,$target,['errors'=>count($errors)]);wp_safe_redirect(admin_url('tools.php?page='.self::SLUG.'&tab=queue&undo_result='.($errors?'error':'1')));exit;
 }
 public static function redirect_csv(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_redirect_csv');
  $staged=self::staged();$rows=[];$seen=[];
  // 'pending' includes only verified migrations whose source tag still exists.
  // The full mode retains historical entries for backup/recovery.
  $mode=isset($_GET['mode'])?sanitize_key(wp_unslash($_GET['mode'])):'pending';
  if(!in_array($mode,['pending','all'],true))$mode='pending';
  foreach($staged as $source_id=>$op){
   if($mode==='pending'){
    $term=get_term((int)$source_id,'post_tag');
    if(!$term||is_wp_error($term)||strpos((string)($op['last_verify']['result']??''),'OK:')!==0)continue;
   }
   if(($op['state']??'')!=='staged')continue;
   $old=wp_parse_url($op['source_url']??'',PHP_URL_PATH);$new=wp_parse_url($op['target_url']??'',PHP_URL_PATH);
   if(!is_string($old)||!is_string($new)||$old===''||$new===''||$old===$new)continue;
   if($old[0]!=='/'||$new[0]!=='/'||isset($seen[$old]))continue;
   $seen[$old]=true;$rows[]=[$old,$new,'0','301'];
  }
  while(ob_get_level())ob_end_clean();nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ogo-tag-redirection-'.$mode.'-'.gmdate('Y-m-d').'.csv"');
  $out=fopen('php://output','w');
  // Redirection's CSV importer expects headerless rows, not our audit columns.
  foreach($rows as $row)fputcsv($out,$row);
  fclose($out);exit;
 }
 private static function pair_key($a,$b){$ids=[(int)$a,(int)$b];sort($ids,SORT_NUMERIC);return implode(':',$ids);}
 private static function pair_decisions(){ $v=get_option(self::PAIRS,[]);return is_array($v)?$v:[]; }
 public static function pair_decision(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');
  check_admin_referer('ogo_tag_pair_decision');
  $a=absint($_POST['tag_a']??0);$b=absint($_POST['tag_b']??0);
  $decision=sanitize_key(wp_unslash($_POST['decision']??''));
  if(!$a||!$b||$a===$b||!in_array($decision,['related','unrelated','review','clear'],true))wp_die('Некоректне рішення.');
  foreach([$a,$b] as $id){$t=get_term($id,'post_tag');if(!$t||is_wp_error($t))wp_die('Тег не знайдено.');}
  $key=self::pair_key($a,$b);$items=self::pair_decisions();
  if($decision==='clear')unset($items[$key]);else $items[$key]=['decision'=>$decision,'at'=>current_time('mysql'),'user'=>get_current_user_id()];
  update_option(self::PAIRS,$items,false);
  self::finish_action(admin_url('tools.php?page='.self::SLUG.'&tab=analysis&pair_updated=1'));exit;
 }
 private static function pair_form($a,$b,$choice,$label){
  $out='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:2px">';
  $out.='<input type="hidden" name="action" value="ogo_tag_manager_pair"><input type="hidden" name="tag_a" value="'.(int)$a.'"><input type="hidden" name="tag_b" value="'.(int)$b.'"><input type="hidden" name="decision" value="'.esc_attr($choice).'">';
  $out.=wp_nonce_field('ogo_tag_pair_decision','_wpnonce',true,false);
  return $out.'<button type="submit" class="button button-small">'.esc_html($label).'</button></form>';
 }
 private static function shared_examples($taxa,$taxb){
  global $wpdb;
  $sql=$wpdb->prepare("SELECT p.ID,p.post_title FROM {$wpdb->term_relationships} a INNER JOIN {$wpdb->term_relationships} b ON a.object_id=b.object_id INNER JOIN {$wpdb->posts} p ON p.ID=a.object_id WHERE a.term_taxonomy_id=%d AND b.term_taxonomy_id=%d AND p.post_type='post' AND p.post_status IN ('publish','future','draft','private') ORDER BY p.post_date DESC LIMIT 3",$taxa,$taxb);
  return $wpdb->get_results($sql,ARRAY_A);
 }
 
 private static function cleanup_candidates(){
  $queue=self::queue();$staged=self::staged();$out=[];
  foreach($queue as $sid=>$decision){
   $sid=(int)$sid;$tid=(int)($decision['target']??0);
   if(($decision['kind']??'')!=='merge'||!$sid||!$tid||$sid===$tid)continue;
   $op=$staged[$sid]??null;
   if(!$op||($op['state']??'')!=='staged'||(int)($op['target']??0)!==$tid)continue;
   $src=get_term($sid,'post_tag');$dst=get_term($tid,'post_tag');
   if(!$src||is_wp_error($src)||!$dst||is_wp_error($dst))continue;
   $a=self::source_objects($sid);$b=self::source_objects($tid);
   if($a===null||$b===null)continue;
   $missing=array_diff($a,$b);
   $saved=array_map('intval',$op['objects']??[]);
   $lost=array_diff($saved,$a);
   $added=array_map('intval',$op['added']??[]);
   $missing_added=array_diff($added,$b);
   $verify=$op['last_verify']['result']??'';
   $verified=strpos($verify,'OK:')===0;
   $meta=self::seo($sid);$has_seo=implode('',array_map('strval',$meta))!=='';
   $url=get_term_link($src);
   $target_url=get_term_link($dst);
   $eligible=$verified&&!$missing&&!$lost&&!$missing_added&&count($saved)===count($a)&&!$has_seo&&!is_wp_error($url)&&!is_wp_error($target_url);
   $out[$sid]=['source'=>$src,'target'=>$dst,'source_id'=>$sid,'target_id'=>$tid,'source_count'=>count($a),'missing'=>count($missing),'verified'=>$verified,'has_seo'=>$has_seo,'eligible'=>$eligible,'url'=>is_wp_error($url)?'':$url,'target_url'=>is_wp_error($target_url)?'':$target_url];
  }
  return $out;
 }
 private static function cleanup_token($ids){
  $data=[];$c=self::cleanup_candidates();sort($ids,SORT_NUMERIC);
  foreach($ids as $sid){if(empty($c[$sid]['eligible']))return null;
   $r=$c[$sid];$src=self::source_objects($sid);$dst=self::source_objects($r['target_id']);sort($src,SORT_NUMERIC);sort($dst,SORT_NUMERIC);
   $data[]=[$sid,$r['target_id'],$r['source']->slug,$r['target']->slug,$src,$dst];
  }
  return hash('sha256',wp_json_encode($data));
 }
 public static function cleanup_redirect_csv(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');
  check_admin_referer('ogo_tag_cleanup_redirect_csv');
  $raw=isset($_GET['ids'])?sanitize_text_field(wp_unslash($_GET['ids'])):'';
  $ids=array_values(array_unique(array_filter(array_map('absint',explode(',',$raw)))));
  if(!$ids||count($ids)>50)wp_die('Некоректний список тегів для CSV.');
  $items=self::cleanup_candidates();$rows=[];$seen=[];
  foreach($ids as $sid){
   if(empty($items[$sid]['eligible']))wp_die('Стан тегів змінився. Поверніться до списку очищення.');
   $r=$items[$sid];$old=wp_parse_url($r['url'],PHP_URL_PATH);$new=wp_parse_url($r['target_url'],PHP_URL_PATH);
   if(!is_string($old)||!is_string($new)||$old===''||$new===''||$old===$new||$old[0]!=='/'||$new[0]!=='/')wp_die('Некоректний URL тегу #'.$sid.'. CSV не створено.');
   if(isset($seen[$old])){
    if($seen[$old]!==$new)wp_die('Конфлікт 301 для шляху '.esc_html($old).'. CSV не створено.');
    continue;
   }
   $seen[$old]=$new;$rows[]=[$old,$new,'0','301'];
  }
  while(ob_get_level())ob_end_clean();nocache_headers();
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="ogo-tag-redirection-selected-'.gmdate('Y-m-d').'.csv"');
  $out=fopen('php://output','w');foreach($rows as $row)fputcsv($out,$row);fclose($out);exit;
 }
 public static function cleanup_confirm(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_cleanup_confirm');
  $ids=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['source_ids']??[])))));
  if(!$ids||count($ids)>50)wp_die('Оберіть від 1 до 50 перевірених тегів.');
  $token=self::cleanup_token($ids);if(!$token)wp_die('Є неперевірені теги або зміни в базі. Поверніться до списку.');
  $items=self::cleanup_candidates();
  // Use the normal WordPress admin shell instead of a handcrafted page.
  // Admin CSS and JavaScript are loaded through the standard header.
  global $title, $hook_suffix;
  $title='Підтвердження очищення OGO';
  $hook_suffix='tools_page_'.self::SLUG;
  require_once ABSPATH.'wp-admin/admin-header.php';
  echo '<div class="wrap"><h1>Видалення вихідних тегів</h1><div class="notice notice-warning inline"><p><strong>Ця операція видалить саме теги з WordPress, а не статті.</strong> WordPress також видалить зв’язки джерела та його метадані. Відкат перенесення після цього недоступний. 301-редиректи модуль не створює.</p></div><ul>';
  foreach($ids as $sid){$r=$items[$sid];echo '<li>'.esc_html($r['source']->name).' (#'.$sid.') → '.esc_html($r['target']->name).' (#'.(int)$r['target_id'].'); статей: '.(int)$r['source_count'].'; <code>'.esc_html($r['url']).'</code></li>';}
  $csv_url=wp_nonce_url(add_query_arg(['action'=>'ogo_tag_manager_cleanup_redirect_csv','ids'=>implode(',',$ids)],admin_url('admin-post.php')),'ogo_tag_cleanup_redirect_csv');
  echo '</ul><div style="background:#fff;border:1px solid #c3c4c7;padding:14px 18px;margin:18px 0"><strong>Крок 1. CSV для Redirection — лише вибрані теги</strong><p>Файл без заголовка, з відносними URL та кодом 301. Після експорту імпортуйте його в Redirection і перевірте перенаправлення. Експорт сам не створює редиректів.</p><p><a class="button button-primary" href="'.esc_url($csv_url).'">Завантажити CSV для цих '.count($ids).' тегів</a></p></div>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ogo_tag_manager_cleanup_execute"><input type="hidden" name="token" value="'.esc_attr($token).'">';
  foreach($ids as $sid)echo '<input type="hidden" name="source_ids[]" value="'.$sid.'">';wp_nonce_field('ogo_tag_cleanup_execute');
  echo '<fieldset style="border:1px solid #c3c4c7;padding:16px;margin:16px 0;background:#fff"><legend><strong>Обов’язкові підтвердження</strong></legend>';
  echo '<p><label for="ogo-cleanup-backup" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer"><input id="ogo-cleanup-backup" type="checkbox" name="backup" value="1" required style="position:static;opacity:1;pointer-events:auto;visibility:visible"> Резервна копія бази зроблена та перевірена.</label></p>';
  echo '<p><label for="ogo-cleanup-export" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer"><input id="ogo-cleanup-export" type="checkbox" name="exported" value="1" required style="position:static;opacity:1;pointer-events:auto;visibility:visible"> Я завантажив CSV для Redirection для вибраних тегів.</label></p>';
  echo '<p><label for="ogo-cleanup-redirects" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer"><input id="ogo-cleanup-redirects" type="checkbox" name="redirects" value="1" required style="position:static;opacity:1;pointer-events:auto;visibility:visible"> Я імпортував потрібні 301-редиректи та перевірив старі URL.</label></p>';
  echo '<p><label for="ogo-cleanup-understand" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer"><input id="ogo-cleanup-understand" type="checkbox" name="understand" value="1" required style="position:static;opacity:1;pointer-events:auto;visibility:visible"> Розумію, що видалення тегів незворотне.</label></p></fieldset>';
  echo '<p><label for="ogo-cleanup-word">Введіть <strong>ВИДАЛИТИ</strong>:</label> <input id="ogo-cleanup-word" name="word" required autocomplete="off"></p><p><button class="button button-primary" type="submit">Видалити вибрані теги</button> <a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=prune')).'">Скасувати</a></p></form></div>';
  require_once ABSPATH.'wp-admin/admin-footer.php';
  exit;
 }
 public static function cleanup_execute(){
  if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer('ogo_tag_cleanup_execute');
  if(empty($_POST['backup'])||empty($_POST['exported'])||empty($_POST['redirects'])||empty($_POST['understand'])||sanitize_text_field(wp_unslash($_POST['word']??''))!=='ВИДАЛИТИ')wp_die('Не підтверджені обов’язкові умови.');
  $ids=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['source_ids']??[])))));
  if(!$ids||count($ids)>50)wp_die('Неправильний пакет.');
  $token=self::cleanup_token($ids);$submitted=sanitize_text_field(wp_unslash($_POST['token']??''));
  if(!$token||!hash_equals($token,$submitted))wp_die('Дані змінилися після підтвердження. Видалення скасовано.');
  $c=self::cleanup_candidates();$done=0;$errors=[];
  foreach($ids as $sid){
   // Re-check each source immediately before deletion; never delete a target of another queued merge.
   $fresh=self::cleanup_candidates();if(empty($fresh[$sid]['eligible'])){$errors[]='#'.$sid.': змінилися зв’язки';break;}
   $targeted=false;foreach(self::queue() as $other_id=>$decision){if((int)$other_id!==$sid&&($decision['kind']??'')==='merge'&&(int)($decision['target']??0)===$sid){$targeted=true;break;}}
   if($targeted){$errors[]='#'.$sid.': тег є ціллю іншого перенесення';break;}
   $result=wp_delete_term($sid,'post_tag');
   if(is_wp_error($result)||!$result){$errors[]='#'.$sid.': помилка WordPress';break;}
   $done++;self::journal('cleanup_delete',$sid,(int)$c[$sid]['target_id'],['name'=>$c[$sid]['source']->name,'slug'=>$c[$sid]['source']->slug,'url'=>$c[$sid]['url'],'target_url'=>$c[$sid]['target_url'],'objects'=>$c[$sid]['source_count']]);
  }
  wp_safe_redirect(add_query_arg(['page'=>self::SLUG,'tab'=>'prune','deleted'=>$done,'cleanup_error'=>implode('; ',$errors)],admin_url('tools.php')));exit;
 }
 public static function page(){if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');$rows=self::rows();$groups=self::groups($rows);$queue=self::queue();$bad=array_values(array_filter($rows,static function($r){return self::bad_slug($r['slug']);}));$rare=count(array_filter($rows,static function($r){return (int)$r['post_count']>=1&&(int)$r['post_count']<=2;}));$empty=count(array_filter($rows,static function($r){return (int)$r['post_count']===0;}));$rules=self::robots_rules();$tab=isset($_GET['tab'])?sanitize_key(wp_unslash($_GET['tab'])):'workflow';if(!in_array($tab,['workflow','overview','slugs','duplicates','seo','queue','plan','analysis','cleanup','finish','prune'],true))$tab='overview';$base=admin_url('tools.php?page='.self::SLUG);$export=wp_nonce_url(admin_url('admin-post.php?action=ogo_tag_manager_export'),self::NONCE);
 echo '<div class="wrap"><h1>OGO Tag Manager <small>v3.7.0 — робоча панель</small></h1><div class="notice notice-info inline"><p><strong>Безпечний режим:</strong> рішення зберігаються в WordPress. Лише окрема підтверджена дія може додати зв’язки статей із цільовим тегом; старий тег не видаляється. Зміна slug доступна окремим пакетом у вкладці перевірки slug. Редиректи імпортуються через CSV. Категорії не аналізуються і не змінюються. Статус robots.txt не дорівнює фактичній індексації Google.</p></div>';
 echo '<nav class="nav-tab-wrapper">';foreach(['workflow'=>'Робоча панель','overview'=>'Огляд','slugs'=>'Перевірка slug','duplicates'=>'Дублікати','seo'=>'SEO-статус','queue'=>'Черга рішень','plan'=>'План перенесення','finish'=>'Завершення','prune'=>'Видалення дублікатів','analysis'=>'Аналіз тегів','cleanup'=>'Очищення бази'] as $k=>$label)echo '<a class="nav-tab '.($tab===$k?'nav-tab-active':'').'" href="'.esc_url(add_query_arg('tab',$k,$base)).'">'.esc_html($label).'</a>';echo '</nav>';
 echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0">';foreach(['Усього'=>count($rows),'Без статей'=>$empty,'З 1–2 статтями'=>$rare,'Slug до перевірки'=>count($bad),'Груп дублікатів'=>count($groups)] as $k=>$v)echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:14px;min-width:130px">'.esc_html($k).'<br><strong style="font-size:22px">'.esc_html(number_format_i18n($v)).'</strong></div>';echo '</div><p><a class="button button-primary" href="'.esc_url($export).'">Експортувати повний CSV</a></p>';

 if($tab==='prune'){
  echo '<h2>v2.6 — очищення перевірених дублікатів</h2><p>У цьому списку лише теги, для яких модуль має запис про перенесення. <strong>Нічого не видаляється автоматично.</strong> Перевірені зв’язки — не підтвердження SEO-безпеки: старі URL потребують окремих 301.</p>';
  if(isset($_GET['deleted']))echo '<div class="notice notice-info inline"><p>Видалено тегів: '.(int)$_GET['deleted'].'. '.esc_html(sanitize_text_field(wp_unslash($_GET['cleanup_error']??''))).'</p></div>';
  echo '<p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=ogo_tag_manager_redirect_csv&mode=pending'),'ogo_tag_redirect_csv')).'">CSV для Redirection: готові до видалення</a> <a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=ogo_tag_manager_redirect_csv&mode=all'),'ogo_tag_redirect_csv')).'">Повний архів 301 (CSV)</a> <a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=queue')).'">Черга і перевірки</a></p>';
  $c=self::cleanup_candidates();$eligible=0;
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('ogo_tag_cleanup_confirm');echo '<input type="hidden" name="action" value="ogo_tag_manager_cleanup_confirm"><table class="widefat striped"><thead><tr><th>Вибір</th><th>Вихідний тег → основний</th><th>Статей</th><th>Контроль</th><th>URL джерела</th></tr></thead><tbody>';
  foreach($c as $sid=>$r){$eligible+=(int)$r['eligible'];$status=$r['eligible']?'Перевірено; доступний для очищення':(!$r['verified']?'Потрібна перевірка перенесення':($r['missing']?'Не всі статті мають основний тег':($r['has_seo']?'Є власні SEO-метадані — вручну':'Потрібна додаткова перевірка')));
   echo '<tr><td>'.($r['eligible']?'<input type="checkbox" name="source_ids[]" value="'.$sid.'">':'—').'</td><td>'.esc_html($r['source']->name).' (#'.$sid.') → '.esc_html($r['target']->name).' (#'.(int)$r['target_id'].')</td><td>'.(int)$r['source_count'].'</td><td>'.esc_html($status).'</td><td><code>'.esc_html($r['url']).'</code></td></tr>';
  }
  if(!$c)echo '<tr><td colspan="5">Ще немає перенесених тегів для очищення.</td></tr>';
  echo '</tbody></table><p>Доступно для вибору: <strong>'.(int)$eligible.'</strong>. За один запуск не більше 50 тегів. Перед видаленням модуль ще раз звірить зв’язки.</p><button class="button button-primary" type="submit"'.(!$eligible?' disabled':'').'>Переглянути й підтвердити пакет видалення</button></form>';
  echo '<p><strong>Важливо:</strong> після видалення старого тегу стандартний відкат перенесення вже неможливий. Потрібні резервна копія і перевірені редиректи. Спершу випробуйте на Local.</p>';
 }
 if($tab==='finish'){
  $staged=self::staged();$snap=self::snapshots();$qstate=self::queue_state($queue);
  echo '<p><strong>Робочий маршрут:</strong> <a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=plan')).'">1. Контрольний план</a> <a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=queue')).'">2. Пакетна перевірка</a> <a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=prune')).'">3. CSV 301 / видалення</a></p>';
  echo '<h2>Пакети очищення — контрольоване виконання</h2><p>Один пакет — одна ціль і кілька вихідних тегів. Перенесення пакета запускається лише після окремого підтвердження й перевірок; максимум 5 джерел та 500 нових зв’язків за запуск. Старі теги та URL не видаляються.</p>';
  if(isset($_GET['package_done']))echo '<div class="notice notice-info inline"><p>Виконано джерел: '.(int)$_GET['package_done'].'. '.esc_html(sanitize_text_field(wp_unslash($_GET['package_error']??''))).' Перевірте результат у черзі.</p></div>';
  $packages=self::packages($queue);$packnum=0;
  $package_view=isset($_GET['package_view'])?sanitize_key(wp_unslash($_GET['package_view'])):'active';
  if(!in_array($package_view,['active','archive'],true))$package_view='active';
  $active_packages=[];$archive_packages=[];
  foreach($packages as $ptid=>$psources){
   $live=[];foreach($psources as $psid){$pterm=get_term((int)$psid,'post_tag');if($pterm&&!is_wp_error($pterm))$live[]=$psid;}
   // Archived only when ALL source tags were deleted; never hide pending work.
   if(!$live)$archive_packages[$ptid]=$psources;else $active_packages[$ptid]=$psources;
  }
  echo '<p><a class="button '.($package_view==='active'?'button-primary':'').'" href="'.esc_url(add_query_arg(['package_view'=>'active'])).'">Активні пакети ('.count($active_packages).')</a> <a class="button '.($package_view==='archive'?'button-primary':'').'" href="'.esc_url(add_query_arg(['package_view'=>'archive'])).'">Архів завершених ('.count($archive_packages).')</a></p>';
  $visible_packages=$package_view==='archive'?$archive_packages:$active_packages;
  foreach($visible_packages as $tid=>$sources){$packnum++;$destination=get_term($tid,'post_tag');$name=($destination&&!is_wp_error($destination))?$destination->name:'Невідомий тег';$remaining=0;$completed=0;$verified=0;$blocked=0;$union=[];$termids=array_merge([$tid],$sources);
   foreach($termids as $termid){$objects=self::source_objects($termid);if($objects!==null)foreach($objects as $oid)$union[$oid]=true;}
   echo '<section style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0"><h3>Пакет №'.(int)$packnum.' — '.esc_html($name).' (#'.(int)$tid.')</h3>';
   echo '<p>Джерел: <strong>'.count($sources).'</strong> · Унікальних матеріалів у групі зараз: <strong>'.count($union).'</strong>. Це не кількість операцій і не гарантія тематичної правильності.</p>';
   echo '<table class="widefat striped"><thead><tr><th>Джерело</th><th>Зв’язки</th><th>Контроль</th><th>Стан</th><th>Наступна дія</th></tr></thead><tbody>';
   foreach($sources as $sid){$src=get_term($sid,'post_tag');$srcname=($src&&!is_wp_error($src))?$src->name:'Невідомий тег';$ids=self::source_objects($sid);$dst=self::source_objects($tid);$missing=$ids!==null&&$dst!==null?count(array_diff($ids,$dst)):null;$op=$staged[$sid]??null;$saved=$snap[$sid]??null;$fp=self::fingerprint($sid,$tid);$current=$saved&&$fp!==null&&(int)$saved['target']===$tid&&hash_equals((string)$saved['hash'],$fp)&&$qstate['ok'];$check=self::preflight($sid,$tid,$queue,$rules);$errors=!empty($check['errors']);$state=$op['state']??'not_started';$verify=$op['last_verify']['result']??'';$isverified=$state==='staged'&&strpos($verify,'OK:')===0;
    if($isverified)$verified++;elseif($op)$completed++;else $remaining++;
    if(!$current&&!$op||$errors)$blocked++;
    echo '<tr><td>'.esc_html($srcname).' #'.(int)$sid.'</td><td>'.($ids===null?'?':count($ids)).' / відсутні в цілі: '.($missing===null?'?':$missing).'</td><td>'.($current?'Знімок актуальний':'Потрібен контроль').($errors?'; є блокування':'').'</td><td>'.($isverified?'Перевірено':($op?'Перенесено / перевірити':'Очікує')).'</td><td>';
    if($op){echo '<a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=queue')).'">Перевірка / відкат у черзі</a>';}
    elseif($current&&!$errors){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ogo_tag_manager_stage_confirm"><input type="hidden" name="source" value="'.(int)$sid.'"><input type="hidden" name="target" value="'.(int)$tid.'">'.wp_nonce_field('ogo_tag_stage_confirm','_wpnonce',true,false).'<button class="button button-secondary" type="submit">Окреме підтвердження</button></form>';}
    else echo '<a href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=plan')).'">Оновити контрольний план</a>';
    echo '</td></tr>';
   }
   echo '</tbody></table><p><strong>Прогрес:</strong> очікує — '.(int)$remaining.'; перенесено без підтвердженої перевірки — '.(int)$completed.'; перевірено — '.(int)$verified.'.'.($blocked?' Потрібна увага: '.(int)$blocked.'.':'').'</p>';
   if($remaining>0){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:12px 0"><input type="hidden" name="action" value="ogo_tag_manager_package_confirm"><input type="hidden" name="target" value="'.(int)$tid.'">'.wp_nonce_field('ogo_tag_package_confirm','_wpnonce',true,false).'<button class="button button-primary">Підготувати контроль і виконати пакет</button></form>';}
   echo '<p><a class="button" href="'.esc_url(wp_nonce_url(add_query_arg(['action'=>'ogo_tag_manager_package_csv','target'=>$tid],admin_url('admin-post.php')),'ogo_tag_package_csv')).'">Технічний CSV пакета (не для Redirection)</a> <a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=plan')).'">План статей</a></p>';
   echo '<p><small>Після пакетного перенесення зв’язки перевіряються автоматично. Перед видаленням перегляньте результат. Перш ніж видаляти тег: протестуйте 301, відновлення бекапу та збереження SEO-метаданих.</small></p></section>';
  }
  if(!$visible_packages)echo '<p>'.($package_view==='active'?'Немає активних пакетів.':'Архів порожній.').'</p>';
  echo '<details style="margin-top:20px"><summary><strong>Детальна діагностика рішень (розгорнути за потреби)</strong></summary><h2>Завершення об’єднань — покроковий контроль</h2><div class="notice notice-warning inline"><p>Ця вкладка нічого не видаляє та не створює редиректи. Видалення вихідного тегу дозволено розглядати лише після перевірки перенесення, імпорту й тестування 301 та окремої резервної копії. Автоматичного видалення немає.</p></div>';
  echo '<p><strong>Історія черги:</strong> '.esc_html($qstate['message']).'</p>';
  echo '<p><a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=plan')).'">Контрольний план</a> <a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=queue')).'">Черга та перевірки</a> <a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=ogo_tag_manager_redirect_csv&mode=pending'),'ogo_tag_redirect_csv')).'">CSV редиректів виконаних перенесень</a></p>';
  echo '<table class="widefat striped"><thead><tr><th>Джерело → ціль</th><th>Статті</th><th>Стан</th><th>Що зробити далі</th></tr></thead><tbody>';
  $any=false;
  foreach($queue as $sid=>$entry){if(($entry['kind']??'')!=='merge'||!get_term((int)$sid,'post_tag'))continue;$any=true;$sid=(int)$sid;$tid=(int)($entry['target']??0);$src=get_term($sid,'post_tag');$dst=get_term($tid,'post_tag');$op=$staged[$sid]??null;$ids=self::source_objects($sid);$targetids=self::source_objects($tid);$missing=($ids!==null&&$targetids!==null)?count(array_diff($ids,$targetids)):null;
   $verified=$op&&($op['state']??'')==='staged'&&!empty($op['last_verify']['result'])&&strpos($op['last_verify']['result'],'OK:')===0;
   $hash=self::fingerprint($sid,$tid);$saved=$snap[$sid]??null;$current=$saved&&$hash!==null&&(int)$saved['target']===$tid&&hash_equals((string)$saved['hash'],$hash)&&$qstate['ok'];
   if($op){$state=$verified?'Перенесено; перевірка OK':'Перенесено / потрібна перевірка';$next=$verified?'Експортувати CSV, вручну перевірити 301 і URL. Тег поки зберегти.':'Відкрити чергу й виконати перевірку перенесення.';}
   else {$state=$current?'План актуальний, ще не перенесено':'План потребує нового контролю';$next=$current?'Спершу тестове відновлення бекапу; після цього — окреме підтвердження перенесення.':'Перевірити план та створити контрольний знімок.';}
   echo '<tr><td>'.esc_html(($src&&!is_wp_error($src)?$src->name:'Тег').' #'.$sid.' → '.($dst&&!is_wp_error($dst)?$dst->name:'Тег').' #'.$tid).'</td><td>'.($ids===null?'Невідомо':count($ids)).' зв’язків; '.($missing===null?'невідомо':$missing).' відсутні в цілі</td><td><strong>'.esc_html($state).'</strong></td><td>'.esc_html($next).' <a href="'.esc_url(add_query_arg(['page'=>self::SLUG,'tab'=>'plan','source'=>$sid],admin_url('tools.php'))).'">План</a></td></tr>';
  }
  if(!$any)echo '<tr><td colspan="4">У черзі немає рішень на об’єднання.</td></tr>';
  echo '</tbody></table><p><strong>Важливо:</strong> навіть статус «Перенесено; перевірка OK» не доводить працездатність редиректів і не дозволяє автоматичне видалення. Після видалення тегу штатними засобами WordPress його зв’язки та метадані можуть бути втрачені.</p></details>';
 }
 if($tab==='cleanup'){
  echo '<h2>Аудит бази тегів</h2><p>Лише читання. Статистика за поточним значенням лічильника WordPress для таксономії post_tag. Малий обсяг не означає, що тег треба видаляти. Категорії не враховуються.</p>';
  $bands=['0'=>'0 статей','1-2'=>'1–2 статті','3-5'=>'3–5 статей','6-14'=>'6–14 статей','15+'=>'15 і більше'];
  $totals=array_fill_keys(array_keys($bands),0);
  foreach($rows as $r){$n=(int)$r['post_count'];$k=$n===0?'0':($n<=2?'1-2':($n<=5?'3-5':($n<=14?'6-14':'15+')));$totals[$k]++;}
  echo '<table class="widefat striped" style="max-width:750px"><thead><tr><th>Кількість матеріалів</th><th>Тегів</th><th>Частка бази</th><th>Перегляд</th></tr></thead><tbody>';
  $url=admin_url('tools.php?page='.self::SLUG.'&tab=cleanup');
  foreach($bands as $key=>$label){$count=$totals[$key];echo '<tr><td>'.esc_html($label).'</td><td><strong>'.number_format_i18n($count).'</strong></td><td>'.esc_html(number_format_i18n(count($rows)?100*$count/count($rows):0,1)).'%</td><td><a href="'.esc_url(add_query_arg(['band'=>$key,'paged'=>1],$url)).'">Переглянути</a></td></tr>';}
  echo '</tbody></table>';
  $band=isset($_GET['band'])?sanitize_text_field(wp_unslash($_GET['band'])):'1-2';if(!isset($bands[$band]))$band='1-2';
  $search=isset($_GET['tag_search'])?sanitize_text_field(wp_unslash($_GET['tag_search'])):'';
  $matches=[];foreach($rows as $r){$n=(int)$r['post_count'];$k=$n===0?'0':($n<=2?'1-2':($n<=5?'3-5':($n<=14?'6-14':'15+')));if($k!==$band)continue;if($search!==''&&mb_stripos($r['name'].' '.$r['slug'],$search,0,'UTF-8')===false)continue;$matches[]=$r;}
  usort($matches,static function($a,$b){$c=(int)$a['post_count']<=>(int)$b['post_count'];return $c?:strnatcasecmp($a['name'],$b['name']);});
  echo '<h3>Перегляд: '.esc_html($bands[$band]).'</h3>';
  echo '<form method="get" style="margin:10px 0"><input type="hidden" name="page" value="'.esc_attr(self::SLUG).'"/><input type="hidden" name="tab" value="cleanup"/><input type="hidden" name="band" value="'.esc_attr($band).'"/><input type="search" name="tag_search" value="'.esc_attr($search).'" placeholder="Назва або slug"/> <button class="button">Знайти в цій групі</button></form>';
  $pg=isset($_GET['paged'])?max(1,absint($_GET['paged'])):1;$per=60;$total=count($matches);$pages=max(1,(int)ceil($total/$per));$pg=min($pg,$pages);
  echo '<p>Знайдено: <strong>'.number_format_i18n($total).'</strong>. Сторінка '.$pg.' із '.$pages.'.</p>';
  $nav=add_query_arg(['band'=>$band,'tag_search'=>$search],$url);
  if($pg>1)echo '<a class="button" href="'.esc_url(add_query_arg('paged',$pg-1,$nav)).'">← Назад</a> ';
  if($pg<$pages)echo '<a class="button" href="'.esc_url(add_query_arg('paged',$pg+1,$nav)).'">Далі →</a>';
  echo '<table class="widefat striped" style="margin:12px 0"><thead><tr><th>ID</th><th>Тег</th><th>Slug</th><th>Статей</th><th>Дії</th></tr></thead><tbody>';
  foreach(array_slice($matches,($pg-1)*$per,$per) as $r){$id=(int)$r['term_id'];$edit=get_edit_term_link($id,'post_tag');$link=self::link($r);echo '<tr data-ogo-row="'.$id.'"><td>'.$id.'</td><td>'.esc_html($r['name']).'</td><td><code>'.esc_html($r['slug']).'</code></td><td>'.(int)$r['post_count'].'</td><td>';if($edit)echo '<a href="'.esc_url($edit).'">Редагувати</a> ';if(!is_wp_error($link))echo '<a href="'.esc_url($link).'" target="_blank" rel="noopener">Архів</a>';echo '</td></tr>';}
  if(!$total)echo '<tr><td colspan="5">Тегів за вибраними умовами не знайдено.</td></tr>';
  echo '</tbody></table><p><strong>Важливо:</strong> ця вкладка не видаляє й не об’єднує теги. Для дублікатів використовуйте вкладку «Дублікати» й чергу рішень. Перед будь-якими змінами потрібні резервна копія та план редиректів.</p>';
 }
 if($tab==='analysis'){
  echo '<h2>Аналіз тематичних зв’язків</h2><p>Спільні статті <strong>не означають дублювання</strong>. Визначайте, чи є теги пов’язаними, незалежними або потребують окремого перегляду. Рішення не змінюють теги, статті чи чергу об’єднань.</p>';
  if(isset($_GET['pair_updated']))echo '<div class="notice notice-success inline"><p>Редакційне рішення збережено.</p></div>';
  global $wpdb;
  $decisions=self::pair_decisions();
  $filter=isset($_GET['pair_filter'])?sanitize_key(wp_unslash($_GET['pair_filter'])):'all';
  if(!in_array($filter,['all','new','related','unrelated','review'],true))$filter='all';
  $base=admin_url('tools.php?page='.self::SLUG.'&tab=analysis');
  if(isset($_GET['batch_added']))echo '<div class="notice notice-success"><p>Додано до черги: '.absint($_GET['batch_added']).'. Пропущено вже опрацьованих або заблокованих: '.absint($_GET['batch_skipped']??0).'. Жодного об’єднання не виконано.</p></div>';
  echo '<div class="notice notice-info inline"><p><strong>v1.6:</strong> можна обрати основний тег з усієї бази за ID. Зокрема, для «пам’ятник» ціллю може бути «пам’ятники». Спершу знайдіть потрібний ID через Записи → Позначки. Рішення тільки додається в чергу.</p></div>';
  echo '<p>Фільтр: ';
  foreach(['all'=>'Усі','new'=>'Без рішення','related'=>'Пов’язані','unrelated'=>'Не пов’язувати','review'=>'На перегляд'] as $k=>$label)echo '<a class="button '.($filter===$k?'button-primary':'').'" style="margin-right:5px" href="'.esc_url(add_query_arg('pair_filter',$k,$base)).'">'.esc_html($label).'</a>';
  echo '</p><p><em>Для швидкодії показуємо 80 пар із найбільшою кількістю спільних матеріалів. Це не повний пошук усіх можливих дублікатів.</em></p>';
  $sql="SELECT a.term_taxonomy_id AS aa,b.term_taxonomy_id AS bb,COUNT(*) AS shared FROM {$wpdb->term_relationships} a JOIN {$wpdb->term_relationships} b ON a.object_id=b.object_id AND a.term_taxonomy_id<b.term_taxonomy_id JOIN {$wpdb->term_taxonomy} ta ON ta.term_taxonomy_id=a.term_taxonomy_id AND ta.taxonomy='post_tag' JOIN {$wpdb->term_taxonomy} tb ON tb.term_taxonomy_id=b.term_taxonomy_id AND tb.taxonomy='post_tag' GROUP BY a.term_taxonomy_id,b.term_taxonomy_id HAVING shared>=2 ORDER BY shared DESC LIMIT 80";
  $pairs=$wpdb->get_results($sql,ARRAY_A);
  $termByTax=[];$taxIds=$wpdb->get_results("SELECT term_taxonomy_id,term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='post_tag'",ARRAY_A);
  $rowById=[];foreach($rows as $r)$rowById[(int)$r['term_id']]=$r;
  foreach($taxIds as $tx)if(isset($rowById[(int)$tx['term_id']]))$termByTax[(int)$tx['term_taxonomy_id']]=$rowById[(int)$tx['term_id']];
  echo '<table class="widefat striped"><thead><tr><th>Теги</th><th>Спільних статей</th><th>Приклади матеріалів</th><th>Редакційне рішення</th></tr></thead><tbody>';
  $shown=0;
  foreach((array)$pairs as $pair){
   $x=$termByTax[(int)$pair['aa']]??null;$y=$termByTax[(int)$pair['bb']]??null;if(!$x||!$y)continue;
   $a=(int)$x['term_id'];$b=(int)$y['term_id'];$key=self::pair_key($a,$b);
   $current=$decisions[$key]['decision']??'new';if($filter!=='all'&&$filter!==$current)continue;
   $shown++;
   $names=['new'=>'Без рішення','related'=>'Пов’язані теми','unrelated'=>'Не пов’язувати','review'=>'Перевірити на дублювання'];
   echo '<tr><td><strong>'.esc_html($x['name']).'</strong> <small>#'.$a.'</small><br><strong>'.esc_html($y['name']).'</strong> <small>#'.$b.'</small>';
   if(self::normalized($x['name'])===self::normalized($y['name']))echo '<br><span style="color:#b32d2e">Подібні написання — перевірте як можливий дубль</span>';
   echo '</td><td>'.(int)$pair['shared'].'</td><td>';
   foreach(self::shared_examples((int)$pair['aa'],(int)$pair['bb']) as $post){echo '<div style="margin-bottom:4px"><a href="'.esc_url(get_edit_post_link((int)$post['ID'])).'" target="_blank" rel="noopener">'.esc_html($post['post_title']?:'Без назви').' (#'.(int)$post['ID'].')</a></div>';}
   echo '</td><td><strong>'.esc_html($names[$current]??'Без рішення').'</strong><br>';
   foreach(['related'=>'Пов’язати','unrelated'=>'Не пов’язувати','review'=>'На перевірку'] as $choice=>$label){if($choice!==$current)echo self::pair_form($a,$b,$choice,$label);}
   if($current!=='new')echo self::pair_form($a,$b,'clear','Скасувати рішення');
   echo '</td></tr>';
  }
  if(!$shown)echo '<tr><td colspan="4">За обраним фільтром пар немає.</td></tr>';
  echo '</tbody></table><p>Ці рішення зберігаються окремо. У цій версії вони <strong>ще не впливають</strong> на підказки авторів; спочатку перевіримо редакційні оцінки.</p>';
 }
 if($tab==='overview'){$popular=$rows;usort($popular,static function($a,$b){return ((int)$b['post_count']<=>(int)$a['post_count'])?:((int)$a['term_id']<=>(int)$b['term_id']);});echo '<h2>50 найпопулярніших тегів</h2>';self::table(array_slice($popular,0,50),$rules);}
 if(isset($_GET['updated']))echo '<div class="notice notice-success is-dismissible"><p>Рішення збережено. Теги та URL не змінені.</p></div>';
 if($tab==='slugs'){self::slug_page($bad,$queue);}
 if($tab==='duplicates'){
  $filter=isset($_GET['filter'])?sanitize_key(wp_unslash($_GET['filter'])):'all';if(!in_array($filter,['all','pending','queued'],true))$filter='all';
  echo '<h2>Групи нормалізованих дублікатів: '.count($groups).'</h2><p>Основний тег за кількістю статей — лише підказка. Можна вибрати будь-який тег групи як ціль. Рішення не виконуються.</p>';
  if(isset($_GET['batch_added']))echo '<div class="notice notice-success"><p>Додано до черги: '.absint($_GET['batch_added']).'. Пропущено вже опрацьованих або заблокованих: '.absint($_GET['batch_skipped']??0).'. Жодного об’єднання не виконано.</p></div>';
  echo '<div class="notice notice-info inline"><p><strong>v1.6:</strong> можна обрати основний тег з усієї бази за ID. Зокрема, для «пам’ятник» ціллю може бути «пам’ятники». Спершу знайдіть потрібний ID через Записи → Позначки. Рішення тільки додається в чергу.</p></div>';
  echo '<p>Фільтр: ';foreach(['all'=>'Усі','pending'=>'Неопрацьовані','queued'=>'У черзі'] as $key=>$label)echo '<a class="button '.($filter===$key?'button-primary':'').'" href="'.esc_url(add_query_arg(['filter'=>$key,'paged'=>1])).'">'.esc_html($label).'</a> ';echo '</p>';
  $selected=[];foreach($groups as $i=>$g){usort($g,static function($a,$b){return ((int)$b['post_count']<=>(int)$a['post_count'])?:((int)$a['term_id']<=>(int)$b['term_id']);});$ids=array_map(static function($r){return (int)$r['term_id'];},$g);$processed=0;foreach($ids as $id)if(isset($queue[$id]))$processed++;
   $canonical=$ids[0]??0;foreach($ids as $member_id){if(isset($queue[$member_id])&&($queue[$member_id]['kind']??'')==='merge'){$candidate=(int)($queue[$member_id]['target']??0);if(in_array($candidate,$ids,true))$canonical=$candidate;}}$pending_sources=0;foreach($ids as $member_id)if($member_id!==$canonical&&!isset($queue[$member_id])&&!isset(self::staged()[$member_id]))$pending_sources++;$key_ids=$ids;sort($key_ids,SORT_NUMERIC);$keep=sanitize_text_field(wp_unslash($_GET['keep_group']??''))===implode('-',$key_ids);if(!$keep&&$filter==='pending'&&!$pending_sources)continue;if(!$keep&&$filter==='queued'&&!$processed)continue;$selected[]=['number'=>$i+1,'rows'=>$g,'processed'=>$processed];}
  $page=isset($_GET['paged'])?max(1,absint($_GET['paged'])):1;$pages=max(1,(int)ceil(count($selected)/25));$page=min($page,$pages);
  echo '<p>Показано груп: '.count($selected).' · Сторінка '.$page.' із '.$pages.'</p>';
  $base=remove_query_arg('paged');if($page>1)echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page-1,$base)).'">← Назад</a> ';if($page<$pages)echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page+1,$base)).'">Далі →</a>';
  echo '<form id="ogo-batch-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  echo '<input type="hidden" name="action" value="ogo_tag_manager_batch_queue">';wp_nonce_field('ogo_tag_manager_batch_queue');echo '<input type="hidden" name="return_page" value="'.max(1,absint($_GET['paged']??1)).'"><input type="hidden" name="return_filter" value="'.esc_attr($filter).'">';
  echo '<p><strong>Пакетний відбір:</strong> <button type="button" class="button" id="ogo-select-groups">Обрати всі доступні на сторінці</button> <button type="button" class="button" id="ogo-clear-groups">Зняти вибір</button> познач до 25 груп на цій сторінці. У чергу потраплять лише ще не опрацьовані теги, ціль — вибраний основний тег кожної групи (типово найпоширеніший). Уже прийняті рішення не перезаписуються. <button class="button button-primary" type="submit" onclick="return confirm(\'Додати вибрані групи до черги рішень? Теги та статті НЕ зміняться.\')">Додати вибрані групи до черги</button></p>';
  echo '</form>';
  echo '<script>document.addEventListener("DOMContentLoaded",function(){var boxes=function(){return document.querySelectorAll("input[name=\"group_keys[]\"]")};var all=document.getElementById("ogo-select-groups"),none=document.getElementById("ogo-clear-groups");if(all)all.addEventListener("click",function(){boxes().forEach(function(x){x.checked=true})});if(none)none.addEventListener("click",function(){boxes().forEach(function(x){x.checked=false})});});</script>';
  foreach(array_slice($selected,($page-1)*25,25) as $item){$g=$item['rows'];$main=$g[0];$mid=(int)$main['term_id'];$group_num=$item['number'];
   $group_ids=array_map(static function($r){return (int)$r['term_id'];},$g);sort($group_ids,SORT_NUMERIC);$group_key=implode('-',$group_ids);$can_batch=!isset($queue[$mid])&&!isset(self::staged()[$mid]);foreach(array_slice($g,1) as $member){$iid=(int)$member['term_id'];if(!isset($queue[$iid])&&!isset(self::staged()[$iid])){$can_batch=$can_batch&&true;}}$unprocessed=count(array_filter(array_slice($g,1),static function($r)use($queue){return !isset($queue[(int)$r['term_id']]);}));
   echo '<div id="ogo-group-'.esc_attr($group_key).'" data-ogo-group="'.esc_attr($group_key).'" style="background:#fff;border:1px solid #c3c4c7;padding:14px;margin:15px 0"><h3>'.($can_batch&&$unprocessed?'<label style="margin-right:10px"><input type="checkbox" form="ogo-batch-form" name="group_keys[]" value="'.esc_attr($group_key).'"> Вибрати</label> ':'').'Група №'.$group_num.' — '.esc_html($main['name']).' <small>('.$item['processed'].' із '.count($g).' тегів мають рішення)</small></h3><details class="ogo-group-details"><summary style="cursor:pointer;padding:7px 0">Показати позначки та рішення ('.count($g).')</summary>';
   echo '<p><label><strong>Основний тег для пакетного відбору:</strong> <select form="ogo-batch-form" name="group_targets['.esc_attr($group_key).']" class="ogo-batch-target">';foreach($g as $candidate){$cid=(int)$candidate['term_id'];echo '<option value="'.$cid.'" '.selected($cid,$mid,false).(isset($queue[$cid])||isset(self::staged()[$cid])?' disabled':'').'>'.esc_html($candidate['name']).' (#'.$cid.', /'.$candidate['slug'].'/, '.$candidate['post_count'].' статей)</option>';}echo '</select></label></p>';
   echo '<p>'.($item['processed']===0?self::group_form($g,$mid,$page):''). ' <span class="description">Пакетна кнопка використовує запропонований основний тег #'.$mid.'. Для іншої цілі вибирай її в рядку.</span></p>';
   echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Назва</th><th>Slug</th><th>Статей</th><th>Статус</th><th>Рішення та ціль</th></tr></thead><tbody>';
   foreach($g as $r){$id=(int)$r['term_id'];echo '<tr data-ogo-row="'.$id.'"><td>'.$id.'</td><td>'.esc_html($r['name']).'</td><td><code>'.esc_html($r['slug']).'</code></td><td>'.(int)$r['post_count'].'</td><td>'.esc_html(self::status($queue[$id]??null)).'</td><td>';
    if(isset($queue[$id])){if(($queue[$id]['kind']??'')==='merge')echo '→ #'.(int)$queue[$id]['target'].' ';echo self::review_button('remove',$id,0,'Скасувати','duplicates',$page);}
    else{echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block">';echo '<input type="hidden" name="action" value="ogo_tag_manager_review"><input type="hidden" name="kind" value="merge"><input type="hidden" name="source" value="'.$id.'"><input type="hidden" name="return_tab" value="duplicates"><input type="hidden" name="return_page" value="'.$page.'">';echo '<label>Об’єднати з <select name="target">';foreach($g as $candidate){$tid=(int)$candidate['term_id'];if($tid===$id)continue;echo '<option value="'.$tid.'" '.selected($tid,$mid,false).'>'.esc_html($candidate['name']).' (#'.$tid.')</option>';}echo '</select></label> ';echo wp_nonce_field('ogo_tag_review_merge_'.$id,'_wpnonce',true,false);echo '<button class="button button-small">У чергу</button></form> ';echo self::review_button('reject',$id,0,'Не об’єднувати','duplicates',$page);}
    echo '</td></tr>';
   }echo '</tbody></table>';
   echo '<details style="margin-top:12px"><summary><strong>Обрати основний тег поза цією групою (за ID)</strong></summary><p class="description">Знайди ID правильного тегу в Записи → Позначки. Для кожного джерела створи окреме рішення. Жодних змін у статтях зараз немає.</p>';
   foreach($g as $r){$sid=(int)$r['term_id'];if(isset($queue[$sid]))continue;echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:7px 0"><input type="hidden" name="action" value="ogo_tag_manager_external_target"><input type="hidden" name="source" value="'.$sid.'"><input type="hidden" name="return_page" value="'.$page.'">';wp_nonce_field('ogo_tag_manager_external_target');echo '<label>'.esc_html($r['name']).' (#'.$sid.') → ID основного тегу <input name="target" type="number" min="1" required style="width:105px"></label> <button type="submit" class="button" onclick="return confirm(\'Додати рішення в чергу? Теги та статті НЕ змінюються.\')">У чергу</button></form>';}
   echo '</details>';
   $sources=[];foreach($g as $r){$sid=(int)$r['term_id'];if(!isset($queue[$sid]))$sources[]=$sid;}
   if($sources){$dom='ogo-target-'.(int)$group_num;
    echo '<div id="'.esc_attr($dom).'" class="ogo-smart-target" data-ids="'.esc_attr(implode(',',$group_ids)).'" style="margin-top:12px;padding:12px;border:1px solid #c3c4c7;background:#f6f7f7">';
    echo '<strong>Пошук кращого основного тегу в усій базі</strong><p class="description">Пропозиції — лише за схожістю написання та якістю slug, без розуміння змісту. Перевіряй кожну ціль перед рішенням.</p>';
    echo '<input type="search" class="ogo-smart-query" placeholder="Назва тегу (необов’язково)" style="width:260px"> <button type="button" class="button ogo-smart-search">Знайти варіанти</button>';
    echo '<div class="ogo-smart-results" style="margin:8px 0"></div>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="ogo-smart-form" style="display:none">';
    echo '<input type="hidden" name="action" value="ogo_tag_manager_suggested_group"><input type="hidden" name="sources" value="'.esc_attr(implode(',',$sources)).'"><input type="hidden" name="return_page" value="'.$page.'"><input type="hidden" name="target" value="" class="ogo-smart-target-id">';
    wp_nonce_field('ogo_tag_suggested_group');
    echo '<button class="button button-primary" onclick="return confirm(\'Додати всі необроблені джерела до черги з обраною ціллю? Теги й статті НЕ змінюються.\')">Додати '.count($sources).' джерел до черги</button></form></div>';
   }
   echo '</details></div>';
  }
 }
 if($tab==='plan'){
  $queue=self::queue();$staged=self::staged();$only=absint($_GET['source']??0);
  echo '<h2>v2.4 — контрольний план перенесення</h2>';
  echo '<div class="notice notice-info inline"><p>Ця вкладка та експорт CSV <strong>не змінюють</strong> теги, статті, зв’язки, slug чи редиректи. Це знімок поточного стану бази; перед майбутнім перенесенням план потрібно перевірити повторно.</p></div>';
  echo '<p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=ogo_tag_manager_plan_csv'),'ogo_tag_plan_csv')).'">Експортувати весь план CSV</a> <a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=queue')).'">Повернутися до черги</a></p>';
  echo '<p><strong>Контрольний знімок:</strong> фіксує точний склад зв’язків і slug джерел та цілей. Якщо щось зміниться, перенесення буде заблоковано. Створення знімка зберігає лише контрольні дані в опції WordPress.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:8px"><input type="hidden" name="action" value="ogo_tag_manager_snapshot">'.wp_nonce_field('ogo_tag_snapshot','_wpnonce',true,false).'<button class="button button-secondary">Зафіксувати поточний план</button></form>';
  echo '<a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=ogo_tag_manager_control_csv'),'ogo_tag_control_csv')).'">Експортувати контрольний звіт CSV</a>';
  if(isset($_GET['snapshot_saved']))echo '<div class="notice notice-success inline"><p>Контрольний знімок збережено. Він не змінює зв’язки статей.</p></div>';
  $snap=self::snapshots();$queue_status=self::queue_state($queue);echo '<div class="notice '.($queue_status['ok']?'notice-success':'notice-warning').' inline"><p><strong>Контроль історії черги:</strong> '.esc_html($queue_status['message']).'</p></div>';$found=0;
  foreach($queue as $sid=>$entry){$sid=(int)$sid;$target=(int)($entry['target']??0);if(($entry['kind']??'')!=='merge'||($only&&$only!==$sid))continue;$found++;
   $a=get_term($sid,'post_tag');$b=get_term($target,'post_tag');$plan=self::migration_plan($sid,$target,$queue,$rules);$check=$plan['check'];
   echo '<section style="background:#fff;border:1px solid #c3c4c7;padding:15px;margin:18px 0"><h3>'.esc_html($a&&!is_wp_error($a)?$a->name:'Невідомий тег').' (#'.$sid.') → '.esc_html($b&&!is_wp_error($b)?$b->name:'Невідомий тег').' (#'.$target.')</h3>';
   if(isset($staged[$sid]))echo '<p><strong>УВАГА: для цього джерела вже є запис про перенесення. Нижче показано поточний стан, а не початковий план.</strong></p>';
   $saved=$snap[$sid]??null;$hash=self::fingerprint($sid,$target);$state=isset($staged[$sid])?'Вже перенесено':(!$saved?'Немає контрольного знімка':(((int)$saved['target']===$target&&$hash!==null&&hash_equals($saved['hash'],$hash))?'Без змін від знімка':'ЗМІНИЛОСЯ — перенесення заблоковано'));
   echo '<p><strong>Контроль: '.esc_html($state).'</strong>'.($saved?' · знімок '.esc_html($saved['at']):'').'</p>';if($saved&&$state==='ЗМІНИЛОСЯ — перенесення заблоковано')echo '<p style="color:#b32d2e">'.esc_html(self::link_differences($sid,$target,$saved)).'</p>';
   foreach(self::redirect_diagnostics($check['from']??'',$check['to']??'') as $note)echo '<p style="color:#996800">URL: '.esc_html($note).'</p>';
   echo '<p><strong>Усього зв’язків джерела: '.(int)$plan['total'].'</strong>; уже мають ціль: '.(int)$plan['existing'].'; <strong>потребують додавання: '.(int)$plan['new'].'</strong>.</p>';
   echo '<p>Перевірка: <strong>'.($check['errors']?'Є блокування':'Блокувань не знайдено (це не дозвіл на запуск)').'</strong></p>';
   foreach($check['errors'] as $message)echo '<p style="color:#b32d2e">⛔ '.esc_html($message).'</p>';
   foreach($check['warnings'] as $message)echo '<p style="color:#996800">⚠ '.esc_html($message).'</p>';
   if(!empty($check['from'])&&!empty($check['to']))echo '<p>Майбутній 301 (не створений): <code>'.esc_html($check['from']).'</code> → <code>'.esc_html($check['to']).'</code></p>';
   echo '<p><a class="button" href="'.esc_url(wp_nonce_url(add_query_arg(['action'=>'ogo_tag_manager_plan_csv','source'=>$sid],admin_url('admin-post.php')),'ogo_tag_plan_csv')).'">CSV для цієї пари</a></p>';
   echo '<details><summary><strong>Показати список матеріалів ('.(int)$plan['total'].')</strong></summary><table class="widefat striped" style="margin-top:10px"><thead><tr><th>ID</th><th>Матеріал</th><th>Тип</th><th>Статус</th><th>Планована дія</th></tr></thead><tbody>';
   foreach(array_slice($plan['rows'],0,250) as $r){$edit=get_edit_post_link($r['id']);echo '<tr><td>'.(int)$r['id'].'</td><td>'.($edit?'<a href="'.esc_url($edit).'">'.esc_html($r['title']).'</a>':esc_html($r['title'])).'</td><td>'.esc_html($r['type']).'</td><td>'.esc_html($r['status']).'</td><td>'.($r['action']==='add'?'Додати зв’язок':'Уже є в цілі — пропустити').'</td></tr>';}
   if(count($plan['rows'])>250)echo '<tr><td colspan="5">Показано перші 250; повний перелік є в CSV.</td></tr>';
   if(!$plan['rows'])echo '<tr><td colspan="5">Зв’язків немає.</td></tr>';
   echo '</tbody></table></details></section>';
  }
  if(!$found)echo '<p>Немає рішень на об’єднання для показу.</p>';
 }
 if($tab==='queue'){
  self::verify_toolbar();
  $queue=self::queue();$queue_view=isset($_GET['queue_view'])?sanitize_key(wp_unslash($_GET['queue_view'])):'active';
  if(!in_array($queue_view,['active','archive'],true))$queue_view='active';
  $active_queue=[];$archived_queue=[];
  foreach($queue as $qid=>$qe){
   $term=get_term((int)$qid,'post_tag');
   if(!$term||is_wp_error($term))$archived_queue[$qid]=$qe;
   elseif(($qe['kind']??'')==='reject')$archived_queue[$qid]=$qe;else $active_queue[$qid]=$qe;
  }
  $display_queue=$queue_view==='archive'?$archived_queue:$active_queue;
  echo '<h2>Черга рішень (активні: '.count($active_queue).'; архів: '.count($archived_queue).')</h2>';
  echo '<p><a class="button '.($queue_view==='active'?'button-primary':'').'" href="'.esc_url(add_query_arg(['queue_view'=>'active'])).'">Активні</a> <a class="button '.($queue_view==='archive'?'button-primary':'').'" href="'.esc_url(add_query_arg(['queue_view'=>'archive'])).'">Архів / не об’єднувати</a></p>';
  echo '<p><a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=finish')).'">1. Виконання пакетів</a> <a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=prune')).'">2. CSV редиректів і видалення</a></p>';
  if(isset($_GET['batch_verified']))echo '<div class="notice notice-success inline"><p>Пакетна перевірка: успішно '.(int)$_GET['batch_verified'].', помилок '.(int)($_GET['batch_failed']??0).'.</p></div>';
  echo '<p><strong>Рішення черги самі по собі нічого не змінюють.</strong> Перенесення зв’язків запускається лише окремою кнопкою. Рішення зберігаються в базі WordPress як окрема опція.</p>';
  echo '<div class="notice notice-warning inline"><p><strong>v1.0: поетапна міграція.</strong> Кнопка переносить лише зв’язки до цільового тегу. Джерело залишається; видалення тегів і редиректи не виконуються. Спершу створіть повний бекап БД і перевірте на копії сайту.</p></div>';
  $staged=self::staged();echo '<p><strong>v2.1:</strong> перенесення тепер вимагає актуального контрольного знімка з вкладки «План перенесення». Якщо зв’язки чи slug змінилися, операцію буде зупинено.</p>';echo '<p><a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=plan')).'">Переглянути детальний план перенесення</a></p>';echo '<p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=ogo_tag_manager_redirect_csv&mode=pending'),'ogo_tag_redirect_csv')).'">CSV для Redirection: перевірені, ще не видалені</a> <a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=ogo_tag_manager_redirect_csv&mode=all'),'ogo_tag_redirect_csv')).'">Архів 301 для Redirection (CSV)</a></p>';
  if(isset($_GET['stage_result']))echo '<div class="notice notice-success inline"><p>Зв’язки додано. Старий тег не видалено. Перевірте статті перед наступними діями.</p></div>';
  if(isset($_GET['undo_result']))echo '<div class="notice notice-'.($_GET['undo_result']==='error'?'error':'success').' inline"><p>Відкат завершено або потребує перевірки; перегляньте статус нижче.</p></div>';
  $mergeTargets=[];foreach($display_queue as $qid=>$qe){if(($qe['kind']??'')==='merge')$mergeTargets[(int)$qe['target']][]= (int)$qid;}
  foreach($mergeTargets as $tid=>$sources){
   $destination=get_term($tid,'post_tag');if(!$destination||is_wp_error($destination))continue;
   $totalIds=[];foreach(array_merge([$tid],$sources) as $termid){$term=get_term($termid,'post_tag');if($term&&!is_wp_error($term))$totalIds[]=(int)$term->term_taxonomy_id;}
   global $wpdb;$unique=0;
   if($totalIds){$ph=implode(',',array_fill(0,count($totalIds),'%d'));$sql=$wpdb->prepare("SELECT COUNT(DISTINCT object_id) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($ph)",$totalIds);$unique=(int)$wpdb->get_var($sql);}
   echo '<div class="notice notice-info inline"><p><strong>Ціль #'.(int)$tid.' — '.esc_html($destination->name).'</strong>: '.count($sources).' джерел у черзі; після об’єднання всієї групи очікується <strong>'.(int)$unique.' унікальних пов’язаних матеріалів</strong> (без урахування майбутніх змін).</p></div>';
  }
  $needs_verify=0;foreach($staged as $op)if(($op['state']??'')==='staged'&&strpos((string)($op['last_verify']['result']??''),'OK:')!==0)$needs_verify++;

  if($needs_verify){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:14px 0"><input type="hidden" name="action" value="ogo_tag_manager_verify_batch">'.wp_nonce_field('ogo_tag_verify_batch','_wpnonce',true,false).'<button class="button button-primary">Перевірити перенесення пакетом (до 50)</button> <span>Очікують перевірки: '.(int)$needs_verify.'. Теги не видаляються.</span></form>';}
  echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Позначка на момент рішення</th><th>Тип рішення</th><th>Ціль</th><th>Попередній перегляд (лише оцінка)</th><th>Дата</th><th>Дія</th></tr></thead><tbody>';
  foreach($display_queue as $id=>$entry){$kind=$entry['kind']??'';$target=(int)($entry['target']??0);$labels=['merge'=>'Кандидат на об’єднання','slug'=>'Перевірити slug','reject'=>'Не об’єднувати / відхилити'];echo '<tr data-ogo-row="'.(int)$id.'"><td>'.(isset($staged[$id])&&($staged[$id]['state']??'')==='staged'?'<input type="checkbox" class="ogo-verify-choice" value="'.(int)$id.'" aria-label="Вибрати для перевірки"> ':'').(int)$id.'</td><td>'.esc_html($entry['name']??'').'</td><td>'.esc_html($labels[$kind]??$kind).'</td><td>'.($target?'#'.$target:'—').'</td><td>';if($kind==='merge'&&$target){echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>self::SLUG,'tab'=>'plan','source'=>(int)$id],admin_url('tools.php'))).'">Список статей і план</a><br>';$check=self::preflight((int)$id,$target,$queue,$rules);$preview=self::preview((int)$id,$target);echo $preview?esc_html($preview['total'].' зв’язків; '.$preview['overlap'].' вже мають ціль; '.$preview['new'].' нових зв’язків'): 'Потрібна перевірка';[$st,$sd,$sn]=self::seo((int)$id);if($st!==''||$sd!==''||$sn!=='')echo '<br><strong>Є власні SEO-налаштування Yoast</strong>';
    echo '<p><strong>'.(count($check['errors'])?'БЛОКУВАННЯ':'Попередня перевірка: без блокувань').'</strong></p>';
    foreach($check['errors'] as $message)echo '<p style="color:#b32d2e">⛔ '.esc_html($message).'</p>';
    foreach($check['warnings'] as $message)echo '<p style="color:#996800">⚠ '.esc_html($message).'</p>';
    if(!empty($check['from'])&&!empty($check['to']))echo '<p><small>301 (план): <code>'.esc_html($check['from']).'</code> → <code>'.esc_html($check['to']).'</code></small></p>';}else echo '—';echo '</td><td>'.esc_html($entry['at']??'').'</td><td>';
    if($queue_view==='archive'){echo 'Завершено / джерело видалено';}elseif(isset($staged[$id])){$op=$staged[$id];echo '<strong>'.esc_html(($op['state']??'')==='staged'?'Зв’язки перенесено; джерело збережено':'Потребує ручної перевірки').'</strong><br>Додано: '.count($op['added']??[]).'<br>';if(($op['state']??'')==='staged'){
     if(!empty($op['last_verify']))echo '<p><small>'.esc_html($op['last_verify']['at'].' — '.$op['last_verify']['result']).'</small></p>';
     echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ogo_tag_manager_verify"><input type="hidden" name="source" value="'.(int)$id.'">'.wp_nonce_field('ogo_tag_verify','_wpnonce',true,false).'<button class="button">Перевірити перенесення</button></form>';
     echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ogo_tag_manager_undo_stage"><input type="hidden" name="source" value="'.(int)$id.'">'.wp_nonce_field('ogo_tag_undo_stage','_wpnonce',true,false).'<button class="button" onclick="return confirm(\'Відкат можливий лише якщо зв’язки обох тегів не змінювалися після перенесення. Продовжити?\')">Відкотити перенесення</button></form>';}}
    elseif($kind==='merge'&&$target&&!empty($check)&&empty($check['errors'])){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ogo_tag_manager_stage_confirm"><input type="hidden" name="source" value="'.(int)$id.'"><input type="hidden" name="target" value="'.(int)$target.'">'.wp_nonce_field('ogo_tag_stage_confirm','_wpnonce',true,false).'<button class="button button-secondary">Перейти до підтвердження</button></form>';}
    if($kind==='slug')echo '<a class="button" href="'.esc_url(admin_url('tools.php?page='.self::SLUG.'&tab=slugs')).'">Перевірити та змінити slug</a> ';if($queue_view!=='archive'&&!isset($staged[$id]))echo self::review_button('remove',(int)$id,0,'Прибрати з черги','queue');echo '</td></tr>';}
  if(!$display_queue)echo '<tr><td colspan="7">Немає записів у цьому списку.</td></tr>';echo '</tbody></table>';
  $history=get_option(self::JOURNAL,[]);if(!is_array($history))$history=[];
  echo '<details style="margin-top:20px"><summary><strong>Журнал операцій (останні 30) — розгорнути</strong></summary><p>Перевірки, перенесення й відкати. Не замінює бекап.</p>';
  echo '<table class="widefat striped"><thead><tr><th>Час</th><th>Подія</th><th>Джерело</th><th>Ціль</th><th>Результат</th></tr></thead><tbody>';
  foreach(array_reverse(array_slice($history,-30)) as $entry){$details=$entry['details']??[];echo '<tr><td>'.esc_html($entry['time']??'').'</td><td>'.esc_html($entry['event']??'').'</td><td>#'.(int)($entry['source']??0).'</td><td>#'.(int)($entry['target']??0).'</td><td>'.esc_html(isset($details['result'])?$details['result']:wp_json_encode($details,JSON_UNESCAPED_UNICODE)).'</td></tr>';}
  if(!$history)echo '<tr><td colspan="5">Операцій ще немає.</td></tr>';echo '</tbody></table></details>';

 }
 if(false && $tab==='integration'){
  echo '<h2>Інтеграція з Redirection — діагностика</h2>';
  echo '<div class="notice notice-warning inline"><p><strong>Діагностика лише читає.</strong> Окрема тестова кнопка нижче може створити й видалити тимчасове правило; теги не змінюються. Вона перевіряє доступність компонентів Redirection всередині WordPress без HTTP-запитів до /wp-json/.</p></div>';
  $active=false;$version='Невідомо';$pluginFile='redirection/redirection.php';
  if(!function_exists('is_plugin_active'))require_once ABSPATH.'wp-admin/includes/plugin.php';
  $active=is_plugin_active($pluginFile)||(is_multisite()&&is_plugin_active_for_network($pluginFile));
  if(function_exists('get_plugins')){$plugins=get_plugins();if(isset($plugins[$pluginFile]['Version']))$version=$plugins[$pluginFile]['Version'];}
  echo '<table class="widefat striped" style="max-width:900px"><tbody>';
  foreach(['Redirection активний'=>$active?'Так':'Ні / не виявлено','Версія Redirection'=>$version,'Клас Red_Item доступний'=>class_exists('Red_Item',false)?'Так':'Ні','Клас Red_Group доступний'=>class_exists('Red_Group',false)?'Так':'Ні','WordPress REST API'=>function_exists('rest_get_server')?'Функції WordPress доступні':'Недоступний'] as $key=>$value)echo '<tr><th style="width:290px">'.esc_html($key).'</th><td>'.esc_html($value).'</td></tr>';
  echo '</tbody></table>';
  echo '<h3>Зареєстровані маршрути Redirection</h3><p>Перелік маршрутів — тільки для діагностики; сам факт їх наявності не означає, що запис дозволений або що API сумісний.</p>';
  if(function_exists('rest_get_server')){
   $routes=rest_get_server()->get_routes();$matches=[];
   foreach($routes as $route=>$handlers){if(strpos($route,'/redirection/')!==false){$methods=[];foreach($handlers as $handler){if(is_array($handler)&&isset($handler['methods'])){$m=$handler['methods'];if(is_array($m))$methods=array_merge($methods,array_keys(array_filter($m)));elseif(is_string($m))$methods[]=$m;}}$matches[]=[$route,implode(', ',array_unique($methods))];}}
   if($matches){echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Маршрут</th><th>Методи</th></tr></thead><tbody>';foreach(array_slice($matches,0,40) as $r)echo '<tr><td><code>'.esc_html($r[0]).'</code></td><td>'.esc_html($r[1]).'</td></tr>';echo '</tbody></table>';if(count($matches)>40)echo '<p>Показано перші 40 маршрутів із '.count($matches).'.</p>';}else echo '<p>Маршрутів із префіксом /redirection/ не виявлено.</p>';
  }
  echo '<h3>Контрольований тест інтеграції</h3>';
  echo '<div class="notice notice-warning inline"><p><strong>Увага: це вже запис у Redirection.</strong> Тест створює випадкове тимчасове правило 301, намагається його видалити й записує результат. Якщо видалення не підтвердиться, правило доведеться прибрати вручну. Теги та статті не змінюються. Радимо спочатку тестову інсталяцію.</p></div>';
  $pending=get_option(self::test_pending_key());
  if(is_array($pending)&&!empty($pending['source']))echo '<div class="notice notice-error inline"><p><strong>Потребує перевірки попередній тест:</strong> <code>'.esc_html($pending['source']).'</code>. Новий запис не створюватиметься, поки попередній не буде перевірено.</p></div>';
  $messages=get_transient('ogo_redirection_test_'.get_current_user_id());
  if(is_array($messages)){echo '<div class="notice notice-info inline"><p><strong>Результат останнього тесту:</strong></p><ul>';foreach($messages as $m)echo '<li>'.esc_html($m).'</li>';echo '</ul></div>';}
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  echo '<input type="hidden" name="action" value="ogo_tag_manager_redirection_test">';
  echo wp_nonce_field('ogo_redirection_test','_wpnonce',true,false);
  echo '<button type="submit" class="button button-primary" onclick="return confirm(\'Створити та спробувати видалити тимчасове правило Redirection? Рекомендується тестова інсталяція.\')">Перевірити створення й видалення тестового 301</button></form>';
  echo '<p>На цьому етапі об’єднання тегів, зміни slug і редиректи реальних тегів залишаються заблокованими.</p>';
 }
 if($tab==='seo'){echo '<h2>SEO-діагностика</h2><p>Показано перші 100 тегів за кількістю статей. Дані Yoast — лише власні значення з wpseo_taxonomy_meta; успадковані шаблони та реальний Google Index Coverage тут не визначаються.</p>';if($rules===null)echo '<div class="notice notice-warning inline"><p>Фізичний robots.txt не знайдено. WordPress може формувати його динамічно. Статуси robots не визначено.</p></div>';$popular=$rows;usort($popular,static function($a,$b){return ((int)$b['post_count']<=>(int)$a['post_count'])?:((int)$a['term_id']<=>(int)$b['term_id']);});self::table(array_slice($popular,0,100),$rules);}
 echo '</div>';
 }
 public static function export(){if(!current_user_can('manage_options'))wp_die('Недостатньо прав.');check_admin_referer(self::NONCE);$rows=self::rows();$groups=self::groups($rows);$gmap=[];foreach($groups as $i=>$g)foreach($g as $r)$gmap[$r['term_id']]=$i+1;$rules=self::robots_rules();while(ob_get_level())ob_end_clean();nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ogo-tag-audit-v02-'.gmdate('Y-m-d').'.csv"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['term_id','name','slug','post_count','normalized_name','bad_slug','duplicate_group','url','robots_local','yoast_custom_title','yoast_custom_description','yoast_noindex_setting']);foreach($rows as $r){$url=self::link($r);if(is_wp_error($url))$url='';[$title,$desc,$noindex]=self::seo((int)$r['term_id']);$values=[$r['term_id'],$r['name'],$r['slug'],$r['post_count'],self::normalized($r['name']),self::bad_slug($r['slug'])?'yes':'no',$gmap[$r['term_id']]??'',$url,$url?self::robots_status($url,$rules):'unknown',$title,$desc,$noindex];foreach($values as &$v){$v=(string)$v;if(preg_match('/^[\s\x00-\x1F]*[=+@-]/u',$v))$v="'".$v;}unset($v);fputcsv($out,$values);}fclose($out);exit;}
}
OGO_Tag_Manager::init();
