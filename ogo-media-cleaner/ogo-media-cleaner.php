<?php
/**
 * Plugin Name: OGO Cleaner
 * Description: Пакетне очищення й оновлення посилань у матеріалах ОГО з попереднім переглядом, журналом і відкатом.
 * Version: 1.43.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ОГО
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/includes/engine.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/external.php';
require_once __DIR__ . '/includes/coverage.php';
require_once __DIR__ . '/includes/site-links.php';
final class OGO_Media_Cleaner {
    public const VERSION = '1.43.0';
    private const DEFAULT_PROJECT = 'legacy_missing';
    private static function table() { global $wpdb; return $wpdb->prefix . 'ogo_media_cleanup'; }
    private static function post_types($project='') {
        return (self::published_posts_only($project) || $project==='miralinks_unwrap')?array('post'):array('post','page','rivnenschina','dovidka','phogo','press');
    }
    private static function published_posts_only($project) {
        return isset(OGO_MC_Engine::projects()[$project]['source_batch']) || in_array($project,array('telegram_urls','facebook_urls'),true);
    }
    private static function post_type_placeholders($project='') { return implode(',', array_fill(0, count(self::post_types($project)), '%s')); }
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table(); $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(36) NOT NULL,
            project varchar(20) NOT NULL DEFAULT 'media',
            post_id bigint unsigned NOT NULL,
            before_content longtext NOT NULL,
            after_content longtext NOT NULL,
            modified_gmt varchar(30) NOT NULL,
            post_status varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            removed int unsigned NOT NULL DEFAULT 0,
            review int unsigned NOT NULL DEFAULT 0,
            changed_gmt varchar(30) NOT NULL DEFAULT '',
            message text NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_post (run_id,post_id),
            KEY run_status (run_id,status),
            KEY project_run (project,run_id)
        ) $charset;");
        $column=$wpdb->get_var("SHOW COLUMNS FROM $t LIKE 'project'");
        // Preserve the separate 1.29.0 operation's snapshots in the unified history.
        if ($column==='project') {
            $migrated=$wpdb->query("UPDATE $t SET project='gazeta' WHERE project='gazeta_sources'");
            if ($migrated!==false) update_option('ogo_mc_schema','1.3.1',false);
        }
        OGO_MC_Audit::activate();
        OGO_MC_External::activate();
        OGO_MC_Site_Links::activate();
    }
    public static function init() {
        OGO_MC_Audit::init();
        OGO_MC_External::init();
        OGO_MC_Site_Links::init();
        OGO_MC_Coverage::init();
        add_action('admin_menu', function(){ add_management_page('OGO Cleaner','OGO Cleaner','edit_others_posts','ogo-media-cleaner',array(__CLASS__,'page')); });
        add_action('admin_init',function(){ if (current_user_can('manage_options') && get_option('ogo_mc_schema')!=='1.3.1') self::activate(); });
        add_action('wp_ajax_ogo_mc',array(__CLASS__,'ajax'));
    }
    private static function option_name($project) { return $project==='media'?'ogo_mc_scan':'ogo_mc_scan_'.$project; }
    private static function state($project) { return get_option(self::option_name($project), array()); }
    private static function valid_project($project) { return isset(OGO_MC_Engine::projects()[$project]); }
    private static function stats($run,$project) {
        global $wpdb; $t=self::table();
        return $wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) AS materials,SUM(removed) AS links,SUM(review) AS review FROM $t WHERE run_id=%s AND project=%s GROUP BY status",$run,$project),ARRAY_A);
    }
    public static function navigation($project) {
        $projects=OGO_MC_Engine::projects();
        ?>
        <form method="get">
            <input type="hidden" name="page" value="ogo-media-cleaner">
            <label>Проєкт / операція <select name="project" required>
                <?php if (OGO_MC_Engine::is_archived($project)): ?><option value="" selected disabled>Виберіть операцію</option><?php endif; ?>
                <?php foreach ($projects as $key=>$config): if (OGO_MC_Engine::is_archived($key)) continue; ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($project,$key); ?>><?php echo esc_html($config['label']); ?></option>
                <?php endforeach; ?>
            </select></label> <button class="button">Відкрити</button>
        </form>
        <details <?php if (OGO_MC_Engine::is_archived($project)) echo 'open'; ?>>
            <summary>Журнали завершених очищень</summary>
            <ul>
                <?php foreach ($projects as $key=>$config): if (!OGO_MC_Engine::is_archived($key)) continue; ?>
                    <li><a href="<?php echo esc_url(add_query_arg(array('page'=>'ogo-media-cleaner','project'=>$key),admin_url('tools.php'))); ?>"><?php echo esc_html($config['label']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
    }
    public static function ajax() {
        if (!current_user_can('edit_others_posts')) wp_send_json_error('Недостатньо прав.',403);
        check_ajax_referer('ogo_mc','nonce');
        global $wpdb; $t=self::table(); $mode=sanitize_key($_POST['mode'] ?? '');
        $project=sanitize_key(wp_unslash($_POST['project']??'media'));
        if (!self::valid_project($project)) wp_send_json_error('Невідомий проєкт.');
        if (OGO_MC_Engine::is_archived($project) && $mode!=='restore') wp_send_json_error('Для завершеного очищення доступні журнал і відкат.',403);
        if (in_array($project,array('audit','external_audit','coverage_404','site_links'),true)) wp_send_json_error('Ревізія лише складає звіт. Використайте окремі кнопки сканування.');
        if (get_option('ogo_mc_schema')!=='1.3.1') wp_send_json_error('Не вдалося оновити таблицю журналу. Очищення не запущено.');
        $s=self::state($project);
        $miralinks=$project==='miralinks_unwrap';
        // Site-wide operation lock; an interrupted request releases on shutdown.
        $owned_lock=array('time'=>time(),'token'=>wp_generate_uuid4());
        if (!add_option('ogo_mc_lock',$owned_lock,'','no')) {
            $lock=get_option('ogo_mc_lock');
            if ((is_array($lock)?(int)$lock['time']:(int)$lock) < time()-600) { delete_option('ogo_mc_lock'); wp_send_json_error('Прибрано прострочене блокування. Повторіть дію.'); }
            wp_send_json_error('Інша операція вже виконується. Повторіть згодом.');
        }
        register_shutdown_function(function() use ($owned_lock){ if (get_option('ogo_mc_lock')===$owned_lock) delete_option('ogo_mc_lock'); });
        if ($mode==='start') {
            $s=array('run'=>wp_generate_uuid4(),'cursor'=>0,'max'=>(int)$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"),'scanned'=>0,'complete'=>false,'project'=>$project,'engine_version'=>self::VERSION);
            if ($wpdb->last_error) wp_send_json_error('Не вдалося визначити межу сканування.');
            if ($miralinks) {
                $audit_run=sanitize_text_field(wp_unslash($_POST['audit_run']??''));
                if (!OGO_MC_External::cleanup_audit($audit_run)) wp_send_json_error('Відкрийте завершену ревізію Міралінкс і виберіть очищення за нею.');
                $s['audit_run']=$audit_run;
            }
            update_option(self::option_name($project),$s,false);
        } elseif ($mode==='scan') {
            if (empty($s['run'])) wp_send_json_error('Спочатку почніть сканування.');
            if (($s['engine_version']??'')!==self::VERSION) wp_send_json_error('Після оновлення почніть нове сканування цього проєкту.');
            if ($miralinks && (sanitize_text_field(wp_unslash($_POST['run']??''))!==$s['run'] || !OGO_MC_External::cleanup_audit($s['audit_run']??''))) {
                wp_send_json_error('Відкрийте поточне очищення за завершеною ревізією Міралінкс.');
            }
            if (!$s['complete']) {
                $block_on_review=isset(OGO_MC_Engine::projects()[$project]['link_action']) || in_array($project,array('legacy_missing','legacy_urls','day','horoscope_urls','garden_urls','pets_urls','nashregion_urls','dim_urls','woman_urls','editorial_urls','vybir_home','flash'),true);
                $site_batch=self::published_posts_only($project);
                $statuses=$site_batch?"'publish'":"'publish','private','draft','pending','future'";
                $limit=$site_batch?200:50;
                $posts=$miralinks?OGO_MC_External::cleanup_posts($s['audit_run'],$s['cursor'],$s['max'],$limit):$wpdb->get_results($wpdb->prepare(
                    "SELECT ID,post_content,post_modified_gmt,post_status FROM {$wpdb->posts} WHERE ID>%d AND ID<=%d AND post_type IN (".self::post_type_placeholders($project).") AND post_status IN ($statuses) ORDER BY ID LIMIT %d",
                    array_merge(array($s['cursor'],$s['max']),self::post_types($project),array($limit))
                ));
                if ($wpdb->last_error) wp_send_json_error('Не вдалося прочитати матеріали.');
                foreach ($posts as $p) {
                    if ($miralinks) {
                        require_once __DIR__.'/includes/source-links.php';
                        try { $a=OGO_MC_Source_Links::miralinks($p->post_content,OGO_MC_External::cleanup_targets($s['audit_run'],$p->ID)); }
                        catch (RuntimeException $e) { wp_send_json_error($e->getMessage()); }
                    } else $a=OGO_MC_Engine::analyze($p->post_content,$project);
                    if ($a['total']) {
                        $ready=$a['removed'] && (!($block_on_review || $miralinks) || !($a['blocking_review']??$a['review']));
                        $entry=array('run_id'=>$s['run'],'project'=>$project,'post_id'=>$p->ID,'before_content'=>$p->post_content,'after_content'=>$a['after'],'modified_gmt'=>$p->post_modified_gmt,'post_status'=>$p->post_status,'status'=>$ready?'ready':'review','removed'=>$a['removed'],'review'=>$a['review'],'message'=>$a['summary']??'');
                        $ok=($site_batch || $miralinks)?$wpdb->replace($t,$entry):$wpdb->insert($t,$entry);
                        if (!$ok) wp_send_json_error('Не вдалося записати звіт. Перезапустіть сканування.');
                    }
                    $s['cursor']=(int)$p->ID; ++$s['scanned'];
                    if (!$site_batch || $a['total']) update_option(self::option_name($project),$s,false);
                }
                if (count($posts)<$limit) $s['complete']=true;
                update_option(self::option_name($project),$s,false);
            }
        } elseif ($mode==='apply' || $mode==='restore') {
            if ($mode==='apply' && (($s['engine_version']??'')!==self::VERSION || sanitize_text_field(wp_unslash($_POST['run']??''))!==($s['run']??''))) wp_send_json_error('Виконайте нове сканування для цього проєкту й відкрийте його звіт.');
            if ($mode==='apply' && empty($s['complete'])) wp_send_json_error('Спочатку завершіть сканування.');
            if ($mode==='apply' && empty($_POST['backup'])) wp_send_json_error('Підтвердьте наявність резервної копії.');
            if ($mode==='apply' && $miralinks && !OGO_MC_External::cleanup_audit($s['audit_run']??'')) wp_send_json_error('Пов’язана ревізія Міралінкс недоступна.');
            $ids=isset($_POST['ids'])?array_slice(array_filter(array_map('absint',(array)$_POST['ids'])),0,20):array();
            $status=$mode==='apply'?'ready':'done';
            $operation_run=$mode==='restore'?sanitize_text_field(wp_unslash($_POST['run']??($s['run']??''))):$s['run'];
            $where=$wpdb->prepare('run_id=%s AND status=%s AND project=%s',$operation_run,$status,$project);
            if ($ids) $where.=' AND id IN ('.implode(',',$ids).')';
            // An empty explicit selection must never become "all".
            if (isset($_POST['selected']) && !$ids) wp_send_json_error('Виберіть матеріали.');
            $rows=$wpdb->get_results("SELECT * FROM $t WHERE $where ORDER BY id LIMIT 20");
            if ($wpdb->last_error) wp_send_json_error('Помилка читання журналу. Зупинено.');
            foreach ($rows as $r) self::change($r,$mode);
        } else { wp_send_json_error('Невідома дія.'); }
        $remaining=isset($status)?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE run_id=%s AND status=%s AND project=%s",$operation_run,$status,$project)):0;
        wp_send_json_success(array('state'=>$s,'stats'=>self::stats($operation_run??($s['run']??''),$project),'remaining'=>$remaining));
    }
    private static function change($r,$mode) {
        global $wpdb; $t=self::table(); $p=get_post($r->post_id);
        if (!$p || !current_user_can('edit_post',$r->post_id)) { $wpdb->update($t,array('status'=>'conflict','message'=>'Матеріал відсутній або недостатньо прав.'),array('id'=>$r->id)); return; }
        require_once ABSPATH.'wp-admin/includes/post.php';
        if (wp_check_post_lock($r->post_id)) { $wpdb->update($t,array('status'=>'conflict','message'=>'Матеріал відкритий іншим редактором.'),array('id'=>$r->id)); return; }
        if ($mode==='apply' && in_array($r->project??'',array('legacy_missing','legacy_urls','nashregion_urls','woman_urls'),true)) {
            require_once __DIR__.'/includes/legacy.php';
            OGO_MC_Legacy::clear_cache();
            $fresh=OGO_MC_Engine::analyze($r->before_content,$r->project);
            if ($fresh['after']!==$r->after_content || $fresh['removed']!==(int)$r->removed || $fresh['review']!==(int)$r->review) {
                $wpdb->update($t,array('status'=>'conflict','message'=>'Актуальні адреси або доступність цільових статей змінилися після сканування. Зробіть нове сканування. Не перезаписано.'),array('id'=>$r->id)); return;
            }
        }
        $before=$mode==='apply'?$r->before_content:$r->after_content;
        $after=$mode==='apply'?$r->after_content:$r->before_content;
        $expected=$mode==='apply'?$r->modified_gmt:$r->changed_gmt;
        $gmt=current_time('mysql',true); $local=current_time('mysql');
        // Atomic compare-and-swap: never overwrite a concurrent editor's content or status.
        $changed=$wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content=%s,post_modified=%s,post_modified_gmt=%s WHERE ID=%d AND BINARY post_content=BINARY %s AND post_modified_gmt=%s AND post_status=%s AND post_type IN (".self::post_type_placeholders($r->project).")",
            array_merge(array($after,$local,$gmt,$r->post_id,$before,$expected,$r->post_status),self::post_types($r->project))
        ));
        if ($changed!==1) {
            $wpdb->update($t,array('status'=>'conflict','message'=>'Матеріал змінився після сканування/очищення, або сталася помилка запису. Не перезаписано.'),array('id'=>$r->id)); return;
        }
        clean_post_cache($r->post_id);
        // Snapshot already exists in the journal before the first update. A crash
        // between content and journal writes is resolved conservatively by conflict.
        $ok=$wpdb->update($t,array('status'=>$mode==='apply'?'done':'restored','changed_gmt'=>$gmt,'message'=>$r->message??''),array('id'=>$r->id));
        if ($ok===false) wp_send_json_error('Контент змінено, але журнал не оновився. Зупиніться: оригінал збережений у таблиці ogo_media_cleanup.');
        if ($mode==='restore') {
            // Restore the previous run's expected timestamp only when snapshots
            // prove it is the direct predecessor. Content/status guards still apply.
            $linked=$wpdb->query($wpdb->prepare("UPDATE $t SET changed_gmt=%s WHERE post_id=%d AND id<%d AND status='done' AND changed_gmt=%s AND BINARY after_content=BINARY %s ORDER BY id DESC LIMIT 1",$gmt,$r->post_id,$r->id,$r->modified_gmt,$after));
            if ($linked===false) wp_send_json_error('Оригінал відновлено, але не вдалося оновити зв’язок попереднього відкату. Перевірте журнал.');
        }
    }
    public static function page() {
        if (!current_user_can('edit_others_posts')) return;
        if (sanitize_key(wp_unslash($_GET['project']??''))==='external_audit') { OGO_MC_External::page(); return; }
        if (sanitize_key(wp_unslash($_GET['project']??''))==='site_links') { OGO_MC_Site_Links::page(); return; }
        if (sanitize_key(wp_unslash($_GET['project']??''))==='coverage_404') { OGO_MC_Coverage::page(); return; }
        if (sanitize_key(wp_unslash($_GET['project']??''))==='audit') { OGO_MC_Audit::page(); return; }
        if (get_option('ogo_mc_schema')!=='1.3.1') { echo '<div class="notice notice-error"><p>Не вдалося оновити таблицю журналу. Очищення недоступне. Перевірте права користувача бази на зміну таблиць.</p></div>'; return; }
        global $wpdb; $t=self::table();
        $project=sanitize_key(wp_unslash($_GET['project']??self::DEFAULT_PROJECT));
        if ($project==='gazeta_sources') $project='gazeta';
        if (!self::valid_project($project)) $project=self::DEFAULT_PROJECT;
        $archived=OGO_MC_Engine::is_archived($project);
        $s=self::state($project); $projects=OGO_MC_Engine::projects();
        $miralinks=$project==='miralinks_unwrap';
        $audit_run='';
        if ($miralinks) {
            $audit_run=sanitize_text_field(wp_unslash($_GET['audit_run']??($s['audit_run']??(OGO_MC_External::state()['run']??''))));
            if (!OGO_MC_External::cleanup_audit($audit_run)) $audit_run='';
        }
        $replacing=in_array($project,array('day_urls','legacy_urls','dossier_urls','horoscope_urls','garden_urls','pets_urls','nashregion_urls','dim_urls','woman_urls','editorial_urls','vybir_home'),true);
        $unwrapping=in_array($project,array('catalog','media_unwrap','segodnya','miralinks_unwrap'),true);
        $updating_attributes=($projects[$project]['link_action']??'')==='attributes';
        $vk_mode=$project==='vk';
        $ustream_mode=$project==='ustream';
        $flash_mode=$project==='flash';
        $runs=$wpdb->get_col($wpdb->prepare("SELECT run_id FROM $t WHERE project=%s GROUP BY run_id ORDER BY MAX(id) DESC".($archived?'':' LIMIT 30'),$project));
        $operation_run=$s['run']??($runs[0]??'');
        $matching_audit=!$miralinks || ($audit_run!=='' && $audit_run===($s['audit_run']??''));
        if ($miralinks && isset($_GET['audit_run']) && !$matching_audit) $operation_run='';
        $run=isset($_GET['run'])?sanitize_text_field(wp_unslash($_GET['run'])):$operation_run;
        $page=max(1,absint($_GET['paged']??1));
        $social_mode=in_array($project,array('telegram_urls','facebook_urls'),true);
        $review_filter_available=$social_mode || $project==='source_batch_3';
        $review_only=$review_filter_available && ($_GET['review']??'')==='1';
        $review_filter=$review_only?' AND review>0':'';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE run_id=%s AND project=%s$review_filter ORDER BY id LIMIT 20 OFFSET %d",$run,$project,($page-1)*20));
        $labels=array('ready'=>'Готово до очищення','review'=>'Ручна перевірка','done'=>'Очищено','restored'=>'Відновлено','conflict'=>'Пропущено: конфлікт');
        if ($replacing) { $labels['ready']='Готово до заміни URL'; $labels['done']='URL замінено'; }
        if ($vk_mode) { $labels['ready']='Готово до обробки'; $labels['done']='Оброблено'; }
        if ($unwrapping) { $labels['ready']='Готово до зняття посилань'; $labels['done']='Посилання знято'; }
        if ($updating_attributes) { $labels['ready']='Готово до оновлення атрибутів'; $labels['done']='Атрибути оновлено'; }
        $count_label='посилань для видалення/видалених';
        $row_action='Видалити';
        if ($replacing) { $count_label='посилань для заміни/замінених'; $row_action='Замінити'; }
        if ($unwrapping) { $count_label='посилань для зняття/знятих'; $row_action='Зняти'; }
        if ($updating_attributes) { $count_label='посилань для оновлення/оновлених'; $row_action='Оновити'; }
        if ($project==='legacy_missing' || $vk_mode || $project==='day') { $count_label='посилань для обробки/оброблених'; $row_action='Обробити'; }
        if ($ustream_mode) { $count_label='елементів для видалення/видалених'; $row_action='Видалити елементів'; }
        if ($flash_mode) { $count_label='вставок для обробки/оброблених'; $row_action='Обробити вставок'; }
        if ($project==='gazeta') { $count_label='збігів для обробки/оброблених'; $row_action='Обробити збігів'; }
        $apply_selected='Очистити вибрані';
        $apply_all='Очистити всі готові';
        if ($replacing) { $apply_selected='Замінити вибрані URL'; $apply_all='Замінити всі готові URL'; }
        if ($unwrapping) { $apply_selected='Зняти вибрані посилання'; $apply_all='Зняти всі готові посилання'; }
        if ($vk_mode) { $apply_selected='Обробити вибрані'; $apply_all='Обробити всі готові'; }
        if ($updating_attributes) { $apply_selected='Оновити вибрані'; $apply_all='Оновити всі готові'; }
        wp_enqueue_script('ogo-mc',plugins_url('assets/admin.js',__FILE__),array(), self::VERSION,true);
        wp_localize_script('ogo-mc','ogoMC',array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ogo_mc'),'complete'=>!empty($s['complete']),'hasRun'=>!empty($s['run']),'run'=>$run,'currentRun'=>$s['run']??'','project'=>$project,'projectLabel'=>$projects[$project]['label'],'replacing'=>$replacing,'unwrapping'=>$unwrapping,'updatingAttributes'=>$updating_attributes,'vkMode'=>$vk_mode,'archived'=>$archived,'auditRun'=>$audit_run,'reviewOnly'=>$review_only));
        ?>
        <div class="wrap"><h1>OGO Cleaner</h1>
        <p><strong>Версія <?php echo esc_html(self::VERSION); ?>.</strong></p>
        <?php self::navigation($project); ?>
        <h2><?php echo esc_html($projects[$project]['label']); ?></h2>
        <?php if ($archived): ?>
        <p>Виберіть попередній запуск у журналі, щоб переглянути зміни або відкотити очищення.</p>
        <?php elseif ($flash_mode): ?>
        <p>Замінює старі YouTube-плеєри на шорткод <code>[embed]</code> за ID відео. Прибирає інші впізнані Flash-вставки. Неоднозначні ID, резервний текст, інше медіа в тій самій вставці й неповна розмітка потребують ручної перевірки.</p>
        <?php elseif ($ustream_mode): ?>
        <p>Видаляє закриті iframe з порожнім вмістом та src на точні домени <code>ustream.tv</code> / <code>www.ustream.tv</code>, шляхи <code>/embed/ID</code> або <code>/embed/recorded/ID</code>. Порядок атрибутів і стилі не заважають. Підтримує http, https та //.</p>
        <p>Видаляє стандартні посилання-підписи «Live streaming video by Ustream» та «Video streaming by Ustream» на головну Ustream. Прибирає br між видаленим плеєром і підписом, а також прості абзаци й блоки HTML/paragraph Gutenberg, що стали порожніми. Текст статей та інше відео зберігає. Нестандартні посилання, невпізнані плеєри й незакриті теги залишає для перевірки.</p>
        <p>Лічильник показує елементи Ustream: один iframe та один підпис — це два збіги. Кількість плеєрів, підписів і порожніх блоків наведено окремо в кожному рядку журналу.</p>
        <?php elseif ($vk_mode): ?>
        <p>Знімає посилання на точні домени <code>vk.com</code> і <code>www.vk.com</code> зі збереженням імен, назв груп, джерел, оформлення та іншого тексту. <code>vovk.com</code> не змінює.</p>
        <p>У простих списках «Або ж пишіть нам у соцмережах…» прибирає пункти ВК та роздільники, залишаючи Facebook. Видаляє окремий абзац «Група зустрічі: vk.com/…» і впізнаний абзац конкурсу книг із двома наведеними email. Інші конкурси автоматично не видаляє. Посилання з голою адресою ВК, складні списки й неоднозначні шаблони залишає для перевірки. Результат кожного правила видно в журналі.</p>
        <?php elseif ($project==='vybir_home'): ?>
        <p>Замінює посилання на головну <code>/vybir2010</code> і <code>/vybir2010/</code> на <code>https://ogo.ua/tag/rejtyngy/</code>. Посилання на статті та інші підсторінки пропускає. Текст, оформлення, параметри та фрагменти зберігаються.</p>
        <?php elseif ($project==='editorial_urls'): ?>
        <p>Замінює старі рубрики за погодженою редакцією картою: рецепти газети, підсумки року, рейтинги, оборона, Волинь, Європа, Євро-2012, довідка, один день, політика, нові обличчя, вибори, бізнес, агровиробники, гороскоп, влада й рукоділля. Змінює лише href, зберігаючи текст, оформлення та інші атрибути.</p>
        <p>Старі рейтингові рубрики з токеном <code>top</code> (також <code>top20</code>) або <code>influence</code> у назві ведуть на <code>https://ogo.ua/tag/rejtyngy/</code>. Це правило діє лише для рубрик головного сайту; активна <code>/rubrics/view/top/</code> не змінюється. Для <code>ogo20</code> є окремий точний відповідник.</p>
        <p>Підтримує http, https, www, //, адреси від кореня сайту, кінцевий слеш та числові сторінки <code>/page:N</code>. Номер сторінки не переноситься. Якір <code>#page-top</code> прибирається, інші якорі та параметри зберігаються. Коментарі й код пропускає; неоднозначні та пошкоджені посилання залишає для перевірки. Матеріали з необробленими збігами не потрапляють у масову заміну готових.</p>
        <?php elseif ($project==='woman_urls'): ?>
        <p>Головну <code>/woman</code> та <code>/woman/</code> замінює на <code>https://ogo.ua/tag/zhinochyj-klub/</code>. Вісім рубрик <code>/woman/rubrics/view/…</code>, також без <code>/woman</code>, замінює на погоджені редакцією теги. Підтримує сторінки рубрик <code>/page:N</code> і головної проєкту: номер сторінки не переноситься. Текст, оформлення й інші атрибути зберігаються.</p>
        <p>Карта: <code>sex_relationships → seks</code>, <code>parents-children → batky-i-dity</code>, <code>recipes → reczepty</code>, <code>adviser → korysni-porady</code>, <code>stories → istoriyi</code>, <code>handmade → rukodillya</code>, <code>beauty-care → krasa</code>, <code>wedding → vesillya</code>.</p>
        <p>Статті <code>/woman/РРРР-ММ-ДД/ID.html</code> знаходить за ID WordPress, перевіряє доступність опублікованої статті та отримує актуальний permalink. Відсутні/закриті записи, статті з параметрами, невідомі рубрики й пошкоджені посилання залишає для перевірки. Матеріали з необробленими збігами не потрапляють до готових для масової заміни. Перед застосуванням повторно перевіряє результат.</p>
        <p>Підтримує http, https, www, // та адреси від кореня сайту. Якір <code>#page-top</code> прибирає із замінюваних посилань; інші якорі й параметри рубрик/головної зберігає. Сторонні домени, коментарі та блоки коду пропускає. Доступні попередній перегляд, журнал і відкат.</p>
        <?php elseif ($project==='nashregion_urls'): ?>
        <p>Замінює 14 регіональних рубрик <code>/rubrics/view/…</code> і <code>/nashregion/rubrics/view/…</code> на теги за картою, звіреною з експортом WordPress. Підтримує головні рубрик і сторінки <code>/page:N</code>: наприклад, обидва варіанти <code>dubno</code> та їхні сторінки ведуть на <code>https://ogo.ua/tag/dubno/</code>. Головну <code>/nashregion</code> або <code>/nashregion/</code> замінює на <code>https://ogo.ua/rubrics/view/region/</code> («Новини Рівного»). Якір <code>#page-top</code> прибирається із замінюваних посилань цього проєкту. Текст, оформлення та інші атрибути зберігаються.</p>
        <p>Статті виду <code>/nashregion/РРРР-ММ-ДД/ID.html</code> знаходить за ID WordPress і підставляє актуальний permalink опублікованої доступної статті. Адреси статей із параметрами, відсутні/закриті записи, невідомі рубрики, вкладені й незакриті посилання залишає для перевірки. Якщо є необроблені збіги, матеріал не потрапляє до готових для масової заміни. Перед застосуванням повторно перевіряє результат; зміна доступності або URL цілі зупиняє запис.</p>
        <p>Підтримує http, https, www, // та адреси від кореня сайту. Для рубрик і головної параметри зберігаються; інші якорі зберігаються. Коментарі та блоки коду пропускає. Доступні попередній перегляд, журнал і відкат.</p>
        <?php elseif ($project==='dim_urls'): ?>
        <p>Замінює лише href головної рубрики <code>/rubrics/view/dim</code>, <code>/rubrics/view/dim/</code> та сторінок <code>/rubrics/view/dim/page:N</code> (N — число) на <code>https://ogo.ua/tag/dim/</code>. Усі номери сторінок ведуть на головну тегу. Текст, оформлення та інші атрибути посилання зберігаються.</p>
        <p>Підтримує http, https, www, //, адреси від кореня сайту та кінцевий слеш. Параметри й фрагменти зберігаються. Інші рубрики й домени не змінює. Коментарі та блоки коду пропускає; вкладені й незакриті посилання залишає для перевірки. Матеріали з необробленими збігами не потрапляють у масову заміну готових.</p>
        <?php elseif ($project==='pets_urls'): ?>
        <p>Замінює лише href посилань зі старої рубрики <code>https://ogo.ua/rubrics/view/pets</code> на тег <code>https://ogo.ua/tag/domashni-tvaryny/</code>. Текст посилання, оформлення та інші атрибути зберігаються.</p>
        <p>Підтримує http, https, www, //, адреси від кореня сайту та кінцевий слеш. Параметри й фрагмент #… зберігаються. Інші рубрики, домени й уже актуальні посилання не змінює. Коментарі та блоки коду пропускає; вкладені й незакриті посилання залишає для перевірки. Матеріали з такими збігами потребують ручної перевірки.</p>
        <?php elseif ($project==='garden_urls'): ?>
        <p>Замінює лише href посилань зі старої рубрики <code>https://ogo.ua/rubrics/view/garden</code> на тег <code>https://ogo.ua/tag/dacha-sad-gorod/</code>. Текст посилання, оформлення та інші атрибути зберігаються.</p>
        <p>Підтримує http, https, www, //, адреси від кореня сайту та кінцевий слеш. Параметри й фрагмент #… зберігаються. Інші рубрики, домени й уже актуальні посилання не змінює. Коментарі та блоки коду пропускає; вкладені й незакриті посилання залишає для ручної перевірки. Матеріали з такими збігами потребують ручної перевірки.</p>
        <?php elseif ($project==='horoscope_urls'): ?>
        <p>Замінює лише href посилань зі старої рубрики <code>https://ogo.ua/rubrics/view/goroskop</code> на тег <code>https://ogo.ua/tag/goroskop/</code>. Текст посилання (зокрема «ЩОДЕННИЙ ГОРОСКОП»), оформлення та інші атрибути зберігаються.</p>
        <p>Підтримує http, https, www, //, адреси від кореня сайту та кінцевий слеш. Параметри й фрагмент #… зберігаються. Інші рубрики, домени й уже актуальні посилання не змінює. Коментарі та блоки коду пропускає; вкладені й незакриті посилання залишає для перевірки. Матеріали з такими збігами потребують ручної перевірки.</p>
        <?php elseif ($project==='legacy_missing'): ?>
        <p>Знімає лише теги <code>&lt;a&gt;</code> з архівних адрес <code>/archive/дата/розділ/число</code> та <code>/rubrics/рубрика/дата/число</code>. Текст, оформлення й абзаци зберігаються. Числа з цих адрес не використовуються для пошуку статей; активні <code>/rubrics/view/…</code> пропускаються.</p>
        <p>Для старих адрес <code>/articles/…</code> перевіряє ID у WordPress. Доступна публічна стаття отримує актуальний URL. Якщо запису немає, знімає лише теги посилання. Не використовує HTTP 404 як доказ відсутності. Закриті записи, неоднозначні адреси й пошкоджений HTML потребують ручної перевірки.</p>
        <?php elseif ($miralinks): ?>
        <p>Знімає лише теги <code>&lt;a&gt;</code> збігів із вибраної ревізії в опублікованих статтях. Порівнює ID статті, адресу й анкор. Текст та оформлення зберігаються.</p>
        <?php if ($audit_run!==''): ?>
        <p>Джерело очищення — <a href="<?php echo esc_url(add_query_arg(array('page'=>'ogo-media-cleaner','project'=>'external_audit','run'=>$audit_run),admin_url('tools.php'))); ?>">вибрана ревізія Міралінкс</a>.</p>
        <?php else: ?>
        <p>Відкрийте <a href="<?php echo esc_url(add_query_arg(array('page'=>'ogo-media-cleaner','project'=>'external_audit'),admin_url('tools.php'))); ?>">завершену ревізію Міралінкс</a> і натисніть «Підготувати очищення за цією ревізією».</p>
        <?php endif; ?>
        <?php elseif ($project==='segodnya'): ?>
        <p>Знімає лише теги <code>&lt;a&gt;</code> з посилань на <code>segodnya.ua</code> та піддомени. Текст, оформлення й абзаци зберігаються.</p>
        <?php elseif ($project==='source_batch_1'): ?>
        <p>У 31 погодженого домену додає <code>target="_blank"</code> і <code>nofollow noopener</code> для посилань у тих самих категоріях, що й у надісланих рядках ревізії. Для <code>roe.vsei.ua</code> і <code>suspilne.media</code> обробляє лише вже закриті посилання. Враховує точний домен і його варіант з <code>www</code>.</p>
        <p>Telegram, Facebook, вбудовані матеріали та посилання з виявленими ознаками реклами пропускає. URL, анкор та інші значення <code>rel</code> зберігаються.</p>
        <?php elseif ($project==='source_batch_2'): ?>
        <p>Додає <code>target="_blank"</code> і <code>nofollow noopener</code> до відкритих посилань із другої партії ревізії. Для <code>prosport.tsn.ua</code> і <code>forms.gle</code> оновлює атрибути вже закритих посилань. Враховує точний домен і варіант із <code>www</code>.</p>
        <p>Вбудовані матеріали й посилання з виявленими ознаками реклами пропускає. URL, анкор та інші значення <code>rel</code> зберігаються.</p>
        <?php elseif ($project==='source_batch_3'): ?>
        <p>Додає <code>target="_blank"</code> і <code>nofollow noopener</code> до звичайних відкритих посилань на 32 домени з третьої партії ревізії у статтях без рекламної позначки. Виявлені вставки позначає для ручної перевірки. Звичайні посилання в тій самій статті можна оновити окремо.</p>
        <?php elseif ($social_mode): ?>
        <p>Додає <code>target="_blank"</code> і <code>nofollow noopener</code> до звичайних відкритих посилань на <code><?php echo esc_html($projects[$project]['hosts'][0]); ?></code> (також із <code>www</code>) у статтях без рекламної позначки. Вбудовані пости й відео позначає для ручної перевірки. Звичайні посилання в тій самій статті можна оновити окремо від вставки.</p>
        <?php elseif ($updating_attributes): ?>
        <p>Встановлює <code>target="_blank"</code> і додає <code>nofollow noopener</code> до <code>rel</code> посилань на <code><?php echo esc_html($projects[$project]['hosts'][0]); ?></code> та піддомени. Інші значення <code>rel</code>, URL і текст посилання зберігаються.</p>
        <?php elseif ($project==='legacy_urls'): ?>
        <p>Визначає ID зі старих адрес <code>/articles/view/…</code> та <code>/articles/дата/ID…</code>. Підтримує .html, .htm, відсутнє розширення, крапку після адреси, зайвий br наприкінці та вкладені/склеєні URL з одним і тим самим ID. Домени джерел: ogo.ua, mail.ogo.ua, gazeta.ogo.ua (також www, http, https, // та адреси від кореня). Запити до цих старих адрес не виконуються.</p>
        <p>Заміна відбувається лише за однозначним ID, коли WordPress знаходить доступну опубліковану статтю без пароля й повертає актуальний permalink. ID важливіший за помилкову дату в старому URL. Різні ID в одній адресі, невідомі суфікси, параметри URL, відсутні й закриті статті залишаються для перевірки з поясненням. Якщо матеріал має необроблені збіги, він не потрапляє до готових для масової заміни.</p>
        <p>Змінює лише href, зберігає текст, оформлення та інші атрибути. Прибирає #page-top; інші якорі зберігає. Перед застосуванням повторно перевіряє актуальність адрес і доступність статей. Нічого автоматично не видаляє; доступні попередній перегляд, журнал і відкат.</p>
        <?php elseif ($replacing): ?>
        <p>Змінює лише href посилань <code>day.ogo.ua/articles/view/…</code> на <code>https://ogo.ua/articles/view/…</code>. Текст, оформлення, шлях, параметри й фрагмент залишаються. Інші URL не замінює. Модуль не перевіряє, чи існує перенесена стаття.</p>
        <?php elseif ($project==='day'): ?>
        <p>Видаляє окремі текстові рекомендації, «За матеріалами…» та впізнані запрошення «Продовження читайте/переглядайте на сайті…» з посиланнями <code>day.ogo.ua</code>. У змістовних реченнях знімає тільки теги a, зберігаючи текст та оформлення. Підтримує числові й текстові назви .html, жирний текст і переноси всередині посилань. Вкладені посилання видаляє лише у цілому впізнаному фрагменті, якщо всі вони належать старому проєкту. Адреси <code>/articles/view/…</code> виправляйте через окрему заміну URL. Матеріали з необробленими посиланнями залишаються для ручної перевірки.</p>
        <?php endif; ?>
        <?php if (!$archived): ?>
        <?php if (self::published_posts_only($project) || $miralinks): ?>
        <p>Область — текст опублікованих статей. Під час сканування створюється попередній перегляд.</p>
        <?php else: ?>
        <p>Область: post_content типів <code><?php echo esc_html(implode(', ',self::post_types())); ?></code>, включно з чернетками та запланованими. Меню, віджети, метаполя й файли не змінює. Сканування нічого не змінює.</p>
        <?php endif; ?>
        <?php if (!$miralinks || $audit_run!==''): ?>
        <p><button class="button button-primary" data-mode="start"><?php echo $miralinks?'Підготувати попередній перегляд':'Нове сканування'; ?></button>
        <?php if ($matching_audit): ?><button class="button" data-mode="scan">Продовжити сканування</button><?php endif; ?></p>
        <?php endif; ?>
        <label><input type="checkbox" id="ogo-backup"> Я маю актуальну резервну копію бази даних.</label>
        <?php endif; ?>
        <p>
            <?php if (!$archived && $matching_audit): ?>
            <button class="button" data-mode="apply" data-selected="1"><?php echo esc_html($apply_selected); ?></button>
            <?php if (!$review_only): ?><button class="button" data-mode="apply"><?php echo esc_html($apply_all); ?></button><?php endif; ?>
            <?php endif; ?>
            <button class="button" data-mode="restore" data-selected="1">Відкотити вибрані</button>
            <button class="button" data-mode="restore">Відкотити показаний запуск</button>
            <button class="button" id="ogo-stop">Зупинити після поточної порції</button>
        </p>
        <p id="ogo-progress" role="status" aria-live="polite"><?php if (!$archived): ?>Перевірено матеріалів: <?php echo esc_html($s['scanned']??0); ?>. <?php echo !empty($s['complete'])?'Сканування завершено.':'Сканування не завершено.'; ?><?php endif; ?></p>
        <p>Відкат працює для запуску, вибраного у звіті. Зміни виконуються порціями до 20 матеріалів. Відкат пропускає матеріали з подальшими правками.</p>
        <p><strong>Після очищення/відкату очистіть кеш сайту та CDN, якщо вони використовуються.</strong> Модуль скидає кеш записів WordPress; сторонній кеш може зберігати стару сторінку.</p>
        <h2>Звіт і журнал</h2>
        <?php if ($review_filter_available && $run!==''): ?>
        <p><a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'ogo-media-cleaner','project'=>$project,'run'=>$run,'review'=>$review_only?'0':'1'),admin_url('tools.php'))); ?>"><?php echo $review_only?'Показати всі матеріали':'Лише матеріали для ручної перевірки'; ?></a></p>
        <?php endif; ?>
        <?php foreach (self::stats($run,$project) as $st) echo '<p>'.esc_html(($labels[$st['status']]??$st['status']).': '.$st['materials'].' матеріалів; '.$count_label.': '.$st['links'].'; для перевірки: '.$st['review']).'</p>'; ?>
        <form method="get"><input type="hidden" name="page" value="ogo-media-cleaner"><input type="hidden" name="project" value="<?php echo esc_attr($project); ?>"><label>Запуск <select name="run"><?php foreach(array_unique(array_merge(array($s['run']??''),$runs)) as $r) { if (!$r) continue; echo '<option value="'.esc_attr($r).'" '.selected($run,$r,false).'>'.esc_html($r).'</option>'; } ?></select></label> <button class="button">Показати</button></form>
        <table class="widefat striped"><thead><tr><th>Вибір</th><th>Матеріал</th><?php if ($flash_mode): ?><th>Джерело</th><?php endif; ?><th>Стан</th><th>Попередній перегляд</th></tr></thead><tbody>
        <?php foreach($rows as $r): ?><tr>
        <td><?php if ($r->status==='done' || (!$archived && $run===($s['run']??'') && $r->status==='ready')): ?><input type="checkbox" class="ogo-row" value="<?php echo (int)$r->id; ?>"><?php endif; ?></td>
        <td><a href="<?php echo esc_url(get_edit_post_link($r->post_id)); ?>"><?php echo esc_html(get_the_title($r->post_id)?:'Без назви'); ?></a><br>ID <?php echo (int)$r->post_id; ?></td>
        <?php if ($flash_mode): ?>
        <td style="overflow-wrap:anywhere"><?php
            $flash_sources=OGO_MC_Engine::analyze($r->before_content,'flash')['sources'];
            echo $flash_sources?implode('<br>',array_map('esc_html',$flash_sources)):'—';
        ?></td>
        <?php endif; ?>
        <td><?php echo esc_html($labels[$r->status]??$r->status); ?><br><?php echo esc_html($row_action); ?>: <?php echo (int)$r->removed; ?><br>Перевірити: <?php echo (int)$r->review; ?><p><?php echo esc_html($r->message); ?></p></td>
        <td><details><summary>Було → стане (HTML)</summary><p>Оригінал</p><textarea readonly style="width:100%;height:180px"><?php echo esc_textarea($r->before_content); ?></textarea><p>Після очищення</p><textarea readonly style="width:100%;height:180px"><?php echo esc_textarea($r->after_content); ?></textarea></details></td>
        </tr><?php endforeach; ?></tbody></table>
        <p><?php if($page>1) echo '<a class="button" href="'.esc_url(add_query_arg(array('paged'=>$page-1))).'">Назад</a> '; if(count($rows)===20) echo '<a class="button" href="'.esc_url(add_query_arg(array('paged'=>$page+1))).'">Далі</a>'; ?></p>
        </div>
        <?php
    }
}
register_activation_hook(__FILE__,array('OGO_Media_Cleaner','activate'));
OGO_Media_Cleaner::init();
