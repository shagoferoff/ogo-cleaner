<?php
/** Local compiler. Input is a JSON decoding of the user's CSV, never executed as code. */
require_once __DIR__.'/miralinks-unwrap.php';
require_once __DIR__.'/../ogo-miralinks-audit/includes/list.php';
if($argc!==3){fwrite(STDERR,"Usage: php scripts/build-miralinks-sql.php posts.json output-directory\n");exit(2);}
$posts=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$out=rtrim($argv[2],'/');if(!is_dir($out))mkdir($out,0777,true);
$allowed=array_flip(OGO_MLA_List::ids());$seen=array();$plan=array();$unchanged=0;$links=0;
foreach($posts as $post) {
    $id=(int)$post['ID'];if(!isset($allowed[$id]) || isset($seen[$id]))throw new RuntimeException('Unexpected or duplicate ID '.$id);$seen[$id]=true;
    if(($post['post_status']??'')!=='publish')throw new RuntimeException('Unexpected status for '.$id);
    if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$post['post_date']))throw new RuntimeException('Invalid post date');
    $before=$post['post_content'];$r=OGO_Miralinks_Unwrap::analyze($before);
    if(!$r['links']){++$unchanged;continue;}
    $again=OGO_Miralinks_Unwrap::analyze($r['after']);if($again['links'] || $again['after']!==$r['after'])throw new RuntimeException('Not idempotent for '.$id);
    $plan[]=array('id'=>$id,'before_sha'=>hash('sha256',$before),'after_sha'=>hash('sha256',$r['after']),'date'=>$post['post_date'],'edits'=>$r['edits'],'links'=>$r['links']);$links+=$r['links'];
}
usort($plan,function($a,$b){return $a['id']<=>$b['id'];});
$tag=substr(hash('sha256',json_encode($plan)),0,12);
$table='`ogo2023_ogo`.`wp_ogo_miralinks_backup_'.$tag.'`';$posts_table='`ogo2023_ogo`.`wp_posts`';
$sql="-- OGO Miralinks cleanup: strip external a tags, preserving text and all other HTML.\n-- Approved article list: visible sheets only. anons and post dates are untouched.\n-- Exact-content hashes prevent overwriting edits made since the CSV export.\n-- Backup table: $table (kept for rollback).\n-- Backups are committed BEFORE any article is updated. Safe to re-run.\n";
$sql.="CREATE TABLE IF NOT EXISTS $table (\n  post_id bigint unsigned NOT NULL PRIMARY KEY,\n  before_content LONGBLOB NOT NULL,\n  before_sha256 binary(64) NOT NULL,\n  after_sha256 binary(64) NOT NULL,\n  post_status varbinary(20) NOT NULL,\n  post_date datetime NOT NULL,\n  saved_gmt datetime NOT NULL\n) ENGINE=InnoDB;\n\n-- BACKUP PHASE\n";
foreach($plan as $r) {
    $id=$r['id'];$before=$r['before_sha'];$after=$r['after_sha'];$date=$r['date'];
    $sql.="INSERT INTO $table (post_id,before_content,before_sha256,after_sha256,post_status,post_date,saved_gmt) SELECT ID,BINARY post_content,'$before','$after',post_status,post_date,UTC_TIMESTAMP() FROM $posts_table WHERE ID=$id AND post_type='post' AND post_status='publish' AND post_date='$date' AND SHA2(BINARY post_content,256)='$before' ON DUPLICATE KEY UPDATE post_id=VALUES(post_id);\n";
}
$sql.="COMMIT;\n\n-- CLEANUP PHASE: offsets are bytes, applied from right to left.\n";
foreach($plan as $r) {
    $expr='BINARY p.post_content';foreach($r['edits'] as $e)$expr='INSERT('.$expr.','.($e[0]+1).','.$e[1].",'')";
    $sql.="UPDATE $posts_table p JOIN $table b ON b.post_id=p.ID SET p.post_content=$expr WHERE p.ID=".$r['id']." AND p.post_type='post' AND p.post_status=b.post_status AND p.post_date=b.post_date AND b.before_sha256='".$r['before_sha']."' AND b.after_sha256='".$r['after_sha']."' AND SHA2(b.before_content,256)=b.before_sha256 AND SHA2(BINARY p.post_content,256)=b.before_sha256 AND SHA2($expr,256)=b.after_sha256;\n";
}
$stats="SELECT ".count($plan)." AS planned_articles,COUNT(*) AS backed_up,".count($plan)."-COUNT(*) AS skipped_before_backup,COALESCE(SUM(SHA2(BINARY p.post_content,256)=b.after_sha256),0) AS cleaned,COALESCE(SUM(SHA2(BINARY p.post_content,256)=b.before_sha256),0) AS original,COALESCE(SUM(p.ID IS NOT NULL AND SHA2(BINARY p.post_content,256) NOT IN (b.before_sha256,b.after_sha256)),0) AS changed_since_backup FROM $table b LEFT JOIN $posts_table p ON p.ID=b.post_id;\n";
$sql.="COMMIT;\n\n-- RESULT\n".$stats;
$rollback="-- OGO Miralinks rollback. Restores exact original bytes from the backup table.\n-- Later edits/status/date changes are never overwritten. Backup is retained.\nUPDATE $posts_table p JOIN $table b ON p.ID=b.post_id SET p.post_content=CONVERT(b.before_content USING utf8mb4) WHERE p.post_type='post' AND p.post_status=b.post_status AND p.post_date=b.post_date AND SHA2(b.before_content,256)=b.before_sha256 AND SHA2(BINARY p.post_content,256)=b.after_sha256;\nCOMMIT;\n".$stats;
file_put_contents($out.'/ogo-miralinks-clean.sql',$sql);file_put_contents($out.'/ogo-miralinks-restore.sql',$rollback);
$summary=array('export_rows'=>count($posts),'articles_to_clean'=>count($plan),'links_to_unwrap'=>$links,'without_external_links'=>$unchanged,'missing_export_ids'=>array_values(array_diff(OGO_MLA_List::ids(),array_keys($seen))),'backup_table'=>$table,'plan_tag'=>$tag);
file_put_contents($out.'/ogo-miralinks-clean-summary.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
file_put_contents($out.'/ogo-miralinks-plan.json',json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
