<?php
if (!defined('ABSPATH')) exit;

/** Bulk source cleanup. A preview token binds each deletion to current data. */
trait OGO_Tag_Delete {
 private static function simple_deletions(){
  $rows=get_option('ogo_tm_deletions_v39',[]);return is_array($rows)?$rows:[];
 }
 private static function simple_delete_candidate($id){
  $source=get_term($id,'post_tag');if(!$source||is_wp_error($source))return null;
  $staged=self::staged();$op=$staged[$id]??null;
  if(!$op||($op['state']??'')!=='staged')return null;
  $target=self::simple_target((int)$op['target']);
  $item=['id'=>$id,'name'=>$source->name,'target_id'=>$target?(int)$target->term_id:0,'target_name'=>$target?$target->name:'Ціль недоступна','count'=>0,'eligible'=>false,'reason'=>'','token'=>''];
  $queue=self::queue();$decision=$queue[$id]??null;
  if(!current_user_can('delete_term',$id))$item['reason']='Недостатньо прав для видалення цієї позначки.';
  elseif(!$decision||($decision['kind']??'')!=='merge'||(int)($decision['target']??0)!==(int)$op['target'])$item['reason']='Рішення в черзі змінилося. Потрібна перевірка.';
  elseif(!$target||(int)$target->term_id===$id)$item['reason']='Кінцева ціль недоступна або є цикл перенесень.';
  elseif($source->slug!==($op['source_slug']??''))$item['reason']='Slug джерела змінився після перенесення.';
  elseif(strpos((string)($op['last_verify']['result']??''),'OK:')!==0)$item['reason']='Перенесення не пройшло перевірку.';
  elseif(trim((string)($source->description??''))!=='')$item['reason']='Джерело має опис. Спершу збережіть потрібний опис у цільовій позначці та очистіть його у джерела.';
  elseif(implode('',array_map('strval',self::seo($id)))!=='')$item['reason']='Джерело має власні SEO-налаштування. Спершу вирішіть, які з них зберегти.';
  if($item['reason'])return $item;
  foreach($queue as $other=>$entry){
   if((int)$other!==$id&&($entry['kind']??'')==='merge'&&(int)($entry['target']??0)===$id&&(!isset($staged[$other])||($staged[$other]['state']??'')!=='staged')){
    $item['reason']='Джерело є ціллю ще не завершеного перенесення.';return $item;
   }
  }
  $a=self::source_objects($id);$b=self::source_objects((int)$target->term_id);
  if($a===null||$b===null){$item['reason']='Не вдалося прочитати зв’язки статей.';return $item;}
  $item['count']=count($a);$saved=array_map('intval',$op['objects']??[]);
  if(array_diff($a,$saved)||array_diff($saved,$a)){$item['reason']='Склад статей джерела змінився після перенесення. Потрібна повторна перевірка.';return $item;}
  if(array_diff($a,$b)||array_diff(array_map('intval',$op['added']??[]),$b)){$item['reason']='Не всі статті мають кінцеву цільову позначку. Видалення заблоковано.';return $item;}
  $old=get_term_link($source);$new=get_term_link($target);
  if(is_wp_error($old)||is_wp_error($new)||$old!==($op['source_url']??'')){$item['reason']='Адреси позначок змінилися або недоступні.';return $item;}
  $rules=self::simple_rules();$rule=$rules[hash('sha256',self::simple_path($old))]??null;
  if(!$rule||$rule['kind']!=='merge'||(int)$rule['source']!==$id||(int)$rule['target']!==(int)$op['target']||self::simple_destination($rule)!==$new){$item['reason']='Немає активного правила 301 на кінцеву ціль. Видалення заблоковано.';return $item;}
  sort($a,SORT_NUMERIC);sort($b,SORT_NUMERIC);$meta=get_term_meta($id);
  $payload=wp_json_encode([(int)get_current_user_id(),(array)$source,(array)$target,$meta,self::seo($id),$a,$b,$op,$decision,$rule]);
  if(!is_string($payload))throw new RuntimeException('Не вдалося сформувати контрольний відбиток.');
  $item['token']=wp_hash('ogo_tm_delete_v39|'.$payload);
  $item['eligible']=true;$item['reason']='Готово: усі статті збережені у цілі, правило 301 активне.';
  $item['old_url']=$old;$item['new_url']=$new;
  // Private audit data never leaves the server in preview responses.
  $item['_audit']=['source'=>(array)$source,'meta'=>$meta,'target'=>(int)$op['target'],'final_target'=>(int)$target->term_id,'source_url'=>$old,'target_url'=>$new,'source_count'=>count($a),'rule'=>$rule];
  return $item;
 }
 private static function simple_delete_scan(){
  $after=absint($_POST['after']??0);$ids=[];
  foreach(self::staged() as $id=>$op)if((int)$id>$after&&($op['state']??'')==='staged')$ids[]=(int)$id;
  sort($ids,SORT_NUMERIC);$chunk=array_slice($ids,0,25);$items=[];
  foreach($chunk as $id){$item=self::simple_delete_candidate($id);if(!$item)continue;unset($item['_audit']);$items[]=$item;}
  return ['items'=>$items,'next'=>count($ids)>count($chunk)?end($chunk):null,'checked'=>count($chunk)];
 }
 private static function simple_delete_source(){
  if(($_POST['confirmed']??'')!=='1')throw new RuntimeException('Підтвердіть видалення показаного списку.');
  $id=absint($_POST['id']??0);$token=(string)wp_unslash($_POST['token']??'');
  if(!$id||!$token)throw new RuntimeException('Некоректне джерело або підтвердження.');
  $history=self::simple_deletions();$previous=$history[$id]??null;$source=get_term($id,'post_tag');
  if((!$source||is_wp_error($source))&&$previous&&in_array($previous['state']??'',['deleting','deleted'],true)&&hash_equals((string)$previous['token'],$token)){
   $history[$id]['state']='deleted';$history[$id]['deleted_at']=current_time('mysql');self::simple_save('ogo_tm_deletions_v39',$history);
   return ['id'=>$id,'message'=>'Джерельну позначку вже видалено. Статті та редирект збережені.'];
  }
  $item=self::simple_delete_candidate($id);
  if(!$item||!$item['eligible'])throw new RuntimeException($item['reason']??'Немає завершеного перенесення для цього джерела.');
  if(!hash_equals($item['token'],$token))throw new RuntimeException('Дані змінилися після показу списку. Оновіть список видалення.');
  $history[$id]=$item['_audit']+['state'=>'deleting','token'=>$token,'at'=>current_time('mysql'),'user'=>get_current_user_id()];
  // Keep the staged migration and redirect rule intact, including after term deletion.
  self::simple_save('ogo_tm_deletions_v39',$history);
  $result=wp_delete_term($id,'post_tag');
  $fresh=get_term($id,'post_tag');
  if(is_wp_error($result)||!$result||($fresh&&!is_wp_error($fresh))){
   $history[$id]['state']='failed';self::simple_save('ogo_tm_deletions_v39',$history);
   throw new RuntimeException('WordPress не видалив позначку. Статті та журнал перенесення збережені; повторіть перевірку.');
  }
  $history[$id]['state']='deleted';$history[$id]['deleted_at']=current_time('mysql');self::simple_save('ogo_tm_deletions_v39',$history);
  self::journal('cleanup_delete',$id,(int)$item['target_id'],['name'=>$item['name'],'slug'=>$source->slug,'url'=>$item['old_url'],'target_url'=>$item['new_url'],'objects'=>$item['count']]);
  return ['id'=>$id,'message'=>'Джерельну позначку видалено. Статті, цільова позначка та 301 збережені.'];
 }
 private static function simple_delete_page(){
  echo '<h2>3. Масове видалення перенесених джерел</h2><p>Натисніть «Підготувати список»: модуль знайде джерела вже виконаних об’єднань, перевірить статті та правила 301 і вибере всі придатні до видалення. Потім підтвердьте видалення всього списку однією дією. Нові позначки та неперенесені джерела не видаляються.</p><p>Видаляються лише старі позначки та їхні власні метадані. Статті, цільові позначки й редиректи залишаються. Видалення позначок незворотне; відкат перенесення для них стане недоступним.</p><p><button type="button" class="button" id="ogo-simple-delete-scan">Підготувати список видалення</button> <button type="button" class="button button-primary" id="ogo-simple-delete-run">Видалити всі перевірені джерела…</button></p><div id="ogo-simple-delete-list"></div>';
 }
}
