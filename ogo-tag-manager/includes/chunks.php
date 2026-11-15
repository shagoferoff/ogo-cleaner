<?php
if (!defined('ABSPATH')) exit;
trait OGO_Tag_Chunks {
 private static function chunk_merge(){
  $a=absint($_POST['source']??0);$b=absint($_POST['target']??0);$staged=self::staged();$op=$staged[$a]??null;
  if($op&&($op['state']??'')==='staged'){
   if((int)$op['target']!==$b)throw new RuntimeException('Джерело вже перенесене в іншу ціль.');
   $_POST['id']=$a;$verify=self::workbench_verify();if(!$verify['ok'])throw new RuntimeException($verify['result']);
   self::simple_rule($op['source_url'],$b,'merge',$op['source_slug'],$a);
   return ['complete'=>true,'moved'=>count($op['added']),'total'=>count($op['added']),'message'=>'Перенесення перевірено. 301 активний; джерело доступне для видалення у розділі 3.'];
  }
  if($op&&empty($op['chunked_v311']))throw new RuntimeException('Попередня незавершена операція потребує перевірки у розширеній панелі.');
  if(!$op){
   $plan=self::simple_preview();$hash=(string)wp_unslash($_POST['hash']??'');
   if(!$hash||!hash_equals($plan['hash'],$hash))throw new RuntimeException('Дані змінилися після перевірки. Оновіть план.');
   $source=get_term($a,'post_tag');$target=get_term($b,'post_tag');$ids=self::source_objects($a);$before=self::source_objects($b);
   $queue=self::queue();$queue[$a]=['kind'=>'merge','target'=>$b,'at'=>current_time('mysql'),'user'=>get_current_user_id()];self::simple_save(self::QUEUE,$queue);
   $op=['source'=>$a,'target'=>$b,'source_name'=>$source->name,'source_slug'=>$source->slug,'target_slug'=>$target->slug,'source_url'=>$plan['old_url'],'target_url'=>$plan['new_url'],'objects'=>$ids,'before'=>$before,'planned'=>array_values(array_diff($ids,$before)),'added'=>[],'intent'=>[],'at'=>current_time('mysql'),'user'=>get_current_user_id(),'state'=>'running','chunked_v311'=>true,'initial_hash'=>$hash];
   $staged[$a]=$op;self::simple_save(self::STAGED,$staged);
  }
  if((int)$op['target']!==$b)throw new RuntimeException('Ціль не відповідає незавершеній операції.');
  $source=get_term($a,'post_tag');$target=get_term($b,'post_tag');$src=self::source_objects($a);$dst=self::source_objects($b);
  if(!$source||is_wp_error($source)||!$target||is_wp_error($target)||$source->slug!==$op['source_slug']||$target->slug!==$op['target_slug']||$src===null||$dst===null||array_diff($src,$op['objects'])||array_diff($op['objects'],$src))throw new RuntimeException('Джерело, ціль або склад статей змінилися. Перенесення зупинено для перевірки.');
  $allowed=array_unique(array_merge($op['before'],$op['intent'],$op['added']));
  if(array_diff($dst,$allowed)||array_diff($op['before'],$dst)||array_diff($op['added'],$dst))throw new RuntimeException('Зв’язки цілі змінилися поза цим перенесенням. Потрібна перевірка.');
  $queue=self::queue();if(($queue[$a]['kind']??'')!=='merge'||(int)($queue[$a]['target']??0)!==$b)throw new RuntimeException('Рішення в черзі змінилося.');
  self::simple_rule($op['source_url'],$b,'merge',$op['source_slug'],$a);
  // A crash after an intended link write is reconciled from durable intent and live links.
  $op['added']=array_values(array_unique(array_merge($op['added'],array_intersect($op['intent'],$dst))));
  $pending=array_values(array_diff($op['planned'],$op['added']));$chunk=array_slice($pending,0,100);
  $op['intent']=array_values(array_unique(array_merge($op['intent'],$chunk)));$staged[$a]=$op;self::simple_save(self::STAGED,$staged);
  foreach($chunk as $object){
   $r=wp_add_object_terms((int)$object,$b,'post_tag');
   if(is_wp_error($r)){
    $op['last_error']=$r->get_error_message();$staged[$a]=$op;self::simple_save(self::STAGED,$staged);
    throw new RuntimeException('Порцію перервано: '.$r->get_error_message().' Уже додані зв’язки збережені. Запустіть цю пару знову для продовження.');
   }
   $op['added'][]=(int)$object;
  }
  $op['added']=array_values(array_unique($op['added']));$staged[$a]=$op;self::simple_save(self::STAGED,$staged);
  $moved=count($op['added']);$total=count($op['planned']);
  if($moved<$total)return ['complete'=>false,'moved'=>$moved,'total'=>$total,'message'=>'Додано '.$moved.' із '.$total.' зв’язків. Джерельна позначка залишається.'];
  $dst=self::source_objects($b);$src=self::source_objects($a);
  if($dst===null||$src===null||array_diff($op['objects'],$src)||array_diff($src,$op['objects'])||array_diff($src,$dst))throw new RuntimeException('Фінальна перевірка не пройдена. Джерело не видалено.');
  $op['state']='staged';$op['after_hash']=self::fingerprint($a,$b);$staged[$a]=$op;self::simple_save(self::STAGED,$staged);
  $_POST['id']=$a;$verify=self::workbench_verify();if(!$verify['ok'])throw new RuntimeException($verify['result']);
  self::journal('stage',$a,$b,['added'=>$moved,'source_links'=>count($src),'chunked'=>true]);
  return ['complete'=>true,'moved'=>$moved,'total'=>$total,'message'=>'Усі статті перенесено й перевірено. 301 активний. Джерело готове до масового видалення у розділі 3.'];
 }
}
