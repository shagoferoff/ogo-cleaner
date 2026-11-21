<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) exit(2);
define('DOING_AJAX',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
wp_set_current_user(get_user_by('login','audit_admin')->ID);

class OGO_Social_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Social_Test_Exit();};});
function social_request($project,$mode,$run='',$selection=array()) {
    $_POST=array('mode'=>$mode,'run'=>$run,'project'=>$project,'backup'=>'1','nonce'=>wp_create_nonce('ogo_mc'));
    if ($selection) { $_POST['selected']='1'; $_POST['ids']=$selection; }
    $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc'); } catch (OGO_Social_Test_Exit $e) {}
    finally { delete_option('ogo_mc_lock'); }
    $response=json_decode(ob_get_clean(),true);
    if (empty($response['success'])) throw new RuntimeException($project.' request failed: '.json_encode($response));
    return $response['data']['state'];
}
function social_test_post($title,$html,$status='publish') {
    return wp_insert_post(wp_slash(array('post_title'=>$title,'post_status'=>$status,'post_content'=>$html)));
}
function social_test_check($ok,$message) {
    if (!$ok) throw new RuntimeException($message);
}
$fixtures=array(
    'telegram_urls'=>array('https://t.me/test_ogo/12','<iframe src="https://t.me/test_ogo/12?embed=1"></iframe>'),
    'facebook_urls'=>array('https://facebook.com/ogo/posts/12','<div class="fb-post"><a href="https://facebook.com/ogo/posts/12">Пост</a></div>'),
);
foreach ($fixtures as $project=>list($url,$embed)) {
    $link='<a href="'.$url.'">Новина</a>';
    $closed='<a href="'.$url.'" target="_blank" rel="nofollow noopener">Новина</a>';
    $mixed=social_test_post("$project mixed fixture",$link.' '.$embed);
    $widget=social_test_post("$project embedded fixture",$embed);
    $plain=social_test_post("$project plain fixture",$link);
    $draft=social_test_post("$project draft fixture",$link,'draft');
    update_post_meta($mixed,'anons',$link);
    try {
        $state=social_request($project,'start'); $run=$state['run'];
        do { $state=social_request($project,'scan',$run); } while (!$state['complete']);
        $table=$wpdb->prefix.'ogo_media_cleanup';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE run_id=%s AND post_id IN (%d,%d,%d,%d)",$run,$mixed,$widget,$plain,$draft),OBJECT_K);
        $by_post=array_column(array_values($rows),null,'post_id');
        social_test_check(count($by_post)===3 && !isset($by_post[$draft]),'Scope is published articles');
        social_test_check($by_post[$mixed]->status==='ready' && (int)$by_post[$mixed]->review===1 && $by_post[$mixed]->after_content===$closed.' '.$embed,'A mixed article can update its plain link and still mark the embed');
        social_test_check($by_post[$widget]->status==='review' && $by_post[$widget]->after_content===$embed,'Standalone embed is manual');
        $_GET=array('project'=>$project,'run'=>$run,'review'=>'1');
        ob_start(); OGO_Media_Cleaner::page(); $html=ob_get_clean();
        social_test_check(strpos($html,"$project mixed fixture")!==false && strpos($html,"$project embedded fixture")!==false
            && strpos($html,"$project plain fixture")===false,'Manual filter shows standalone and mixed embeds');
        wp_update_post(wp_slash(array('ID'=>$plain,'post_content'=>$link.' Правка редактора.')));
        social_request($project,'apply',$run,array($by_post[$mixed]->id,$by_post[$plain]->id));
        social_test_check(get_post($mixed)->post_content===$closed.' '.$embed && get_post($widget)->post_content===$embed,'Apply preserves embedded HTML exactly');
        social_test_check(get_post($plain)->post_content===$link.' Правка редактора.','Apply preserves edits after preview');
        social_test_check(get_post_meta($mixed,'anons',true)===$link,'Apply preserves anons');
        social_request($project,'restore',$run,array($by_post[$mixed]->id));
        social_test_check(get_post($mixed)->post_content===$link.' '.$embed,'Rollback restores original HTML');
    } finally {
        foreach (array($mixed,$widget,$plain,$draft) as $id) wp_delete_post($id,true);
    }
    echo "Passed WordPress $project scope, mixed review filter, apply and rollback checks.\n";
}
