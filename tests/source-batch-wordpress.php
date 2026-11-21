<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) exit(2);
define('DOING_AJAX',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
wp_set_current_user(get_user_by('login','audit_admin')->ID);

class OGO_Batch_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Batch_Test_Exit();};});
function batch_ajax($mode,$run='',$selection=array()) {
    global $batch_project;
    $_POST=array('mode'=>$mode,'run'=>$run,'project'=>$batch_project,'backup'=>'1','nonce'=>wp_create_nonce('ogo_mc'));
    if ($selection) { $_POST['selected']='1'; $_POST['ids']=$selection; }
    $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc'); } catch (OGO_Batch_Test_Exit $e) {}
    finally { delete_option('ogo_mc_lock'); }
    $reply=json_decode(ob_get_clean(),true);
    if (empty($reply['success'])) throw new RuntimeException('Batch AJAX failed: '.json_encode($reply));
    return $reply['data'];
}
function batch_fixture($html,$status='publish',$type='post',$title='Source batch QA') {
    return wp_insert_post(wp_slash(array('post_title'=>$title,'post_status'=>$status,'post_type'=>$type,'post_content'=>$html)));
}
function batch_assert($ok,$message) {
    if (!$ok) throw new RuntimeException($message);
}
$hosts=array(1=>'pravda.com.ua',2=>'suspilne.media',3=>'fakty.ictv.ua',4=>'slide.com');
$batch=(int)getenv('OGO_TEST_SOURCE_BATCH')?:1;
$batch_project='source_batch_'.$batch;
$host=$hosts[$batch];
$plain='<p><a href="https://'.$host.'/test" rel="noreferrer">Джерело</a> <a href="https://t.me/channel/1">Telegram</a> <a href="https://facebook.com/video/2">Facebook</a></p>';
$embed=$batch>=3?'<object><param name="movie" value="https://'.$host.'/test">'.$plain.'</object>':'';
$source=$plain.$embed;
$expected=str_replace('rel="noreferrer"','rel="nofollow noopener noreferrer" target="_blank"',$plain).$embed;
$paid=$source.'<p>На правах реклами.</p>';
$article=batch_fixture($source,'publish','post','Source batch mixed fixture');
$draft=batch_fixture($source,'draft');
$page=batch_fixture($source,'publish','page');
$advert=batch_fixture($paid);
$fixtures=array($article,$draft,$page,$advert);
if ($embed!=='') {
    $widget=batch_fixture($embed,'publish','post','Source batch embed fixture');
    $plain_id=batch_fixture($plain,'publish','post','Source batch plain fixture');
    $fixtures[]=$widget;
    $fixtures[]=$plain_id;
}
update_post_meta($article,'anons',$source);
$previous_paid=get_option('ogo_mc_paid_articles',array());
try {
    if ($batch===4) {
        delete_option('ogo_mc_paid_articles');
        $imported=batch_fixture($plain,'publish','post','Source batch imported fixture');
        $marked=batch_fixture($plain,'publish','post','Source batch marked fixture');
        $fixtures[]=$imported;
        $fixtures[]=$marked;
        update_post_meta($marked,'paid_article','1');
        batch_assert(!has_action('admin_post_ogo_mc_import_paid'),'Remove the previous upload handler');
        $_GET=array('project'=>'paid_posts');
        ob_start(); OGO_Media_Cleaner::page(); $page_html=ob_get_clean();
        batch_assert(strpos($page_html,'paid_posts')===false && strpos($page_html,'paid_csv')===false,'The old URL exposes neither the menu item nor the import form');
    }
    $state=batch_ajax('start')['state']; $run=$state['run'];
    if ($batch===4) update_option('ogo_mc_paid_articles',array('/existing-import'=>$imported,'/unresolved-fixture'=>0),false);
    do { $state=batch_ajax('scan',$run)['state']; } while (!$state['complete']);
    global $wpdb;
    $table=$wpdb->prefix.'ogo_media_cleanup';
    $row=$wpdb->get_row($wpdb->prepare("SELECT id,status,review,after_content FROM $table WHERE run_id=%s AND post_id=%d",$run,$article));
    batch_assert($row && $row->status==='ready' && $row->after_content===$expected && get_post($article)->post_content===$source,'Scan offers a read-only preview of approved links');
    foreach (array($draft,$page,$advert) as $id) {
        batch_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE run_id=%s AND post_id=%d",$run,$id)),'Excluded content has no pending edit');
    }
    if ($embed!=='') {
        $widget_row=$wpdb->get_row($wpdb->prepare("SELECT status,review,after_content FROM $table WHERE run_id=%s AND post_id=%d",$run,$widget));
        batch_assert((int)$row->review===1 && $widget_row && $widget_row->status==='review'
            && (int)$widget_row->review===1 && $widget_row->after_content===$embed,'Manual review records mixed and standalone embeds');
        $_GET=array('project'=>$batch_project,'run'=>$run,'review'=>'1');
        ob_start(); OGO_Media_Cleaner::page(); $html=ob_get_clean();
        batch_assert(strpos($html,'Source batch mixed fixture')!==false && strpos($html,'Source batch embed fixture')!==false
            && strpos($html,'Source batch plain fixture')===false,'The manual review filter shows only marked articles');
    }
    if ($batch===4) {
        foreach (array($imported,$marked) as $id) {
            batch_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE run_id=%s AND post_id=%d",$run,$id)),'Paid posts have no ready edit');
        }
        $plain_row=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE run_id=%s AND post_id=%d",$run,$plain_id));
        update_post_meta($plain_id,'paid_article','1');
        batch_ajax('apply',$run,array($plain_row));
        batch_assert(get_post($plain_id)->post_content===$plain && $wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE id=%d",$plain_row))==='conflict','Late paid marker blocks an already prepared edit');
    }
    batch_ajax('apply',$run,array($row->id));
    batch_assert(get_post($article)->post_content===$expected && get_post_meta($article,'anons',true)===$source,'Apply preserves the rest of the content');
    batch_ajax('restore',$run,array($row->id));
    batch_assert(get_post($article)->post_content===$source,'Rollback restores the source HTML');
} finally {
    if ($batch===4) {
        if ($previous_paid) update_option('ogo_mc_paid_articles',$previous_paid,false);
        else delete_option('ogo_mc_paid_articles');
    }
    foreach ($fixtures as $id) wp_delete_post($id,true);
}
echo "Passed WordPress $batch_project preview, published scope, advertising exclusion, apply and rollback checks.\n";
