<?php
$root=getenv('OGO_TEST_WP_ROOT');
if (!$root || !is_file($root.'/wp-load.php')) exit(2);
define('DOING_AJAX',true);
require $root.'/wp-load.php';
if (DB_NAME!=='ogo_audit_test') throw new RuntimeException('Dedicated test database required');
wp_set_current_user(get_user_by('login','audit_admin')->ID);

class OGO_Flash_Test_Exit extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function(){throw new OGO_Flash_Test_Exit();};});
function flash_ajax($mode,$run='') {
    $_POST=array('mode'=>$mode,'run'=>$run,'project'=>'flash','backup'=>'1','nonce'=>wp_create_nonce('ogo_mc'));
    $_REQUEST=$_POST;
    ob_start();
    try { do_action('wp_ajax_ogo_mc'); } catch (OGO_Flash_Test_Exit $e) {}
    finally { delete_option('ogo_mc_lock'); }
    $reply=json_decode(ob_get_clean(),true);
    if (empty($reply['success'])) throw new RuntimeException('Flash AJAX failed: '.json_encode($reply));
    return $reply['data']['state'];
}
function flash_fixture($title,$html) {
    return wp_insert_post(wp_slash(array('post_title'=>$title,'post_status'=>'publish','post_content'=>$html)));
}
function flash_assert($ok,$message) {
    if (!$ok) throw new RuntimeException($message);
}
$player='<object data="/player.swf"><param name="movie" value="/player.swf"></object>';
$youtube='<object><param name="movie" value="http://www.youtube.com/v/K7wvnnpGVyI&amp;hl=ru_RU&amp;fs=1&amp;"/>'
    .'<embed type="application/x-shockwave-flash" src="http://www.youtube.com/v/K7wvnnpGVyI&amp;hl=ru_RU&amp;fs=1&amp;"/></object>';
$shortcode='[embed]https://youtu.be/K7wvnnpGVyI[/embed]';
$modern='<p>Матеріал і підпис.</p><iframe src="https://www.youtube.com/embed/modern"></iframe>';
$fallback='<object data="/other.swf"><p>Резервний текст</p></object>';
$before=$player.$youtube.$modern;
$after=$shortcode.$modern;
$ready=flash_fixture('Flash QA', $before);
$manual=flash_fixture('Flash mixed QA', $player.$fallback);
update_post_meta($ready,'anons',$player);
try {
    $state=flash_ajax('start'); $run=$state['run'];
    do { $state=flash_ajax('scan',$run); } while (!$state['complete']);
    global $wpdb;
    $table=$wpdb->prefix.'ogo_media_cleanup';
    $row=$wpdb->get_row($wpdb->prepare("SELECT status,after_content FROM $table WHERE run_id=%s AND post_id=%d",$run,$ready));
    flash_assert($row && $row->status==='ready' && $row->after_content===$after && get_post($ready)->post_content===$before,'Scan previews the YouTube shortcode without writing');
    $_GET=array('project'=>'flash','run'=>$run);
    ob_start(); OGO_Media_Cleaner::page(); $report=ob_get_clean();
    flash_assert(strpos($report,'<th>Джерело</th>')!==false && strpos($report,'YouTube · https://youtu.be/K7wvnnpGVyI')!==false,'Flash journal shows source and canonical URL');
    $status=$wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE run_id=%s AND post_id=%d",$run,$manual));
    flash_assert($status==='review','Mixed content is held for manual review');
    flash_ajax('apply',$run);
    flash_assert(get_post($ready)->post_content===$after && get_post_meta($ready,'anons',true)===$player,'Only approved post_content is changed');
    add_filter('pre_oembed_result',function($html,$url){
        return $url==='https://youtu.be/K7wvnnpGVyI'?'<iframe src="https://www.youtube.com/embed/K7wvnnpGVyI"></iframe>':$html;
    },10,2);
    global $wp_embed;
    $wp_embed->post_ID=$ready;
    $rendered=apply_filters('the_content','<!-- wp:html -->'.$shortcode.'<!-- /wp:html -->');
    flash_assert(strpos($rendered,'https://www.youtube.com/embed/K7wvnnpGVyI')!==false && strpos($rendered,'[embed]')===false,'WordPress renders regenerated embed, including inside a Custom HTML block');
    flash_assert(get_post($manual)->post_content===$player.$fallback,'Bulk apply leaves mixed content alone');
    flash_ajax('restore',$run);
    flash_assert(get_post($ready)->post_content===$before,'Rollback restores original HTML');
} finally {
    wp_delete_post($ready,true);
    wp_delete_post($manual,true);
}
echo "Passed WordPress Flash scan, apply, fallback and rollback checks.\n";
