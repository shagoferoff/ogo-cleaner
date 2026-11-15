<?php
if (!defined('ABSPATH')) exit;
trait OGO_Tag_Guided {
 private static function pair_skipped($a,$b){return (self::pair_decisions()[self::pair_key($a,$b)]['decision']??'')==='unrelated';}
 private static function guided_skip(){
  $a=absint($_POST['source']??0);$b=absint($_POST['target']??0);
  if(!$a||!$b||$a===$b)throw new RuntimeException('Некоректна пара.');
  $items=self::pair_decisions();$key=self::pair_key($a,$b);
  if(($_POST['restore']??'')==='1')unset($items[$key]);
  else{
   $x=get_term($a,'post_tag');$y=get_term($b,'post_tag');
   if(!$x||is_wp_error($x)||!$y||is_wp_error($y))throw new RuntimeException('Позначку не знайдено.');
   if(isset(self::staged()[$a])||isset(self::staged()[$b]))throw new RuntimeException('Пара вже бере участь у перенесенні. Спочатку завершіть його.');
   $items[$key]=['decision'=>'unrelated','at'=>current_time('mysql'),'user'=>get_current_user_id(),'source'=>$a,'target'=>$b,'source_name'=>$x->name,'target_name'=>$y->name];
   $queue=self::queue();if(isset($queue[$a])&&($queue[$a]['kind']??'')==='merge'&&(int)$queue[$a]['target']===$b){unset($queue[$a]);self::simple_save(self::QUEUE,$queue);}
  }
  self::simple_save(self::PAIRS,$items);return ['message'=>(($_POST['restore']??'')==='1'?'Пару повернуто до підказок.':'Пару пропущено: ці позначки залишаються окремими.')];
 }
 private static function guided_skipped_list(){
  $out=[];foreach(self::pair_decisions() as $key=>$item){
   if(($item['decision']??'')!=='unrelated')continue;$ids=array_map('intval',explode(':',$key));if(count($ids)!==2)continue;
   $a=get_term($ids[0],'post_tag');$b=get_term($ids[1],'post_tag');
   $out[]=['source'=>$ids[0],'target'=>$ids[1],'name'=>$a&&!is_wp_error($a)?$a->name:($item['source_name']??'#'.$ids[0]),'target_name'=>$b&&!is_wp_error($b)?$b->name:($item['target_name']??'#'.$ids[1])];
  }return ['items'=>$out];
 }
 private static function guided_slugs(){
  $items=[];foreach(self::rows() as $r){
   if(!preg_match('/\p{Cyrillic}/u',rawurldecode($r['slug'])))continue;
   $id=(int)$r['term_id'];if((self::staged()[$id]['state']??'')==='staged'&&strpos((string)(self::staged()[$id]['last_verify']['result']??''),'OK:')===0)continue;$desired=self::suggestion(rawurldecode($r['slug']));$other=get_term_by('slug',$desired,'post_tag');
   if($other&&(int)$other->term_id===$id)$other=false;
   if($other&&self::pair_skipped($id,(int)$other->term_id))continue;
   $items[]=['id'=>$id,'name'=>$r['name'],'old_slug'=>$r['slug'],'slug'=>$desired,'count'=>(int)$r['post_count'],'conflict'=>$other?['id'=>(int)$other->term_id,'name'=>$other->name]:null];
  }return ['items'=>$items];
 }
 private static function guided_slug_preview(){
  $id=absint($_POST['id']??0);$term=get_term($id,'post_tag');$desired=trim((string)wp_unslash($_POST['slug']??''));
  if(!$term||is_wp_error($term)||$term->slug!==wp_unslash($_POST['old_slug']??''))throw new RuntimeException('Позначка змінилася. Оновіть список.');
  if(!$desired||strlen($desired)>200||!preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D',$desired)||sanitize_title($desired)!==$desired)throw new RuntimeException('Введіть коректний латинський slug.');
  $other=get_term_by('slug',$desired,'post_tag');
  if($other&&(int)$other->term_id!==$id){
   $_POST['source']=$id;$_POST['target']=(int)$other->term_id;$plan=self::simple_preview();
   return $plan+['action'=>'merge','name'=>$term->name,'target_name'=>$other->name,'desired'=>$desired];
  }
  if($desired===$term->slug)throw new RuntimeException('Slug уже має це значення.');
  if(isset(self::staged()[$id]))throw new RuntimeException('Позначка вже має перенесення. Завершіть його.');
  foreach(self::queue() as $source=>$decision)if(($decision['kind']??'')==='merge'&&((int)$source===$id||((int)($decision['target']??0)===$id&&(!isset(self::staged()[$source])||(self::staged()[$source]['state']??'')!=='staged'))))throw new RuntimeException('Позначка бере участь у запланованому перенесенні.');
  $old=get_term_link($term);if(is_wp_error($old))throw new RuntimeException('Старий URL недоступний.');
  return ['action'=>'slug','id'=>$id,'name'=>$term->name,'old_slug'=>$term->slug,'desired'=>$desired,'old_url'=>$old];
 }
}
