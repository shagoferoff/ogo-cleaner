<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/audit-engine.php';
/** Audit data is isolated from the cleanup journal and from wp_posts writes. */
final class OGO_MC_Audit {
    private const VERSION = '1.24.0';
    private const SCHEMA = '1.0.0';
    private const TYPES = array('post','page','rivnenschina','dovidka','phogo','press');
    private static function table() { global $wpdb; return $wpdb->prefix.'ogo_link_audit'; }
    public static function init() {
        add_action('admin_init', function() { if (current_user_can('manage_options') && get_option('ogo_mc_audit_schema') !== self::SCHEMA) self::activate(); });
        add_action('wp_ajax_ogo_mc_audit', array(__CLASS__,'ajax'));
        add_action('admin_post_ogo_mc_audit_export', array(__CLASS__,'export'));
    }
    public static function activate() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t = self::table(); $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(36) NOT NULL,
            post_id bigint unsigned NOT NULL,
            group_key char(64) NOT NULL,
            variant_key char(64) NOT NULL,
            group_url text NOT NULL,
            category varchar(24) NOT NULL,
            target_url text NOT NULL,
            suggestion text NOT NULL,
            raw_url longtext NOT NULL,
            resolved_url longtext NOT NULL,
            link_count int unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_post_variant (run_id,post_id,variant_key),
            KEY run_group (run_id,group_key),
            KEY run_category (run_id,category)
        ) ENGINE=InnoDB $charset;");
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $t));
        if ($status && strtolower($status->Engine) === 'innodb') update_option('ogo_mc_audit_schema', self::SCHEMA, false);
    }
    private static function ready() { return get_option('ogo_mc_audit_schema') === self::SCHEMA; }
    private static function valid_run($run) { return is_string($run) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $run); }
    public static function state($run = '') { return get_option($run === '' ? 'ogo_mc_audit_scan' : 'ogo_mc_audit_run_'.$run, array()); }
    private static function save_state($s) {
        foreach (array('ogo_mc_audit_scan','ogo_mc_audit_run_'.$s['run']) as $key) {
            if (!update_option($key, $s, false) && get_option($key) !== $s) throw new RuntimeException('Не вдалося зберегти прогрес ревізії.');
        }
    }
    public static function stats($run) {
        global $wpdb; $t = self::table();
        $totals = $wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT group_key) AS urls,COUNT(DISTINCT post_id) AS materials,COALESCE(SUM(link_count),0) AS links FROM $t WHERE run_id=%s", $run), ARRAY_A);
        if ($wpdb->last_error) throw new RuntimeException('Не вдалося прочитати підсумки ревізії.');
        $categories = $wpdb->get_results($wpdb->prepare("SELECT category,COUNT(DISTINCT group_key) AS urls,COUNT(DISTINCT post_id) AS materials,SUM(link_count) AS links FROM $t WHERE run_id=%s GROUP BY category", $run), ARRAY_A);
        if ($wpdb->last_error) throw new RuntimeException('Не вдалося прочитати групи ревізії.');
        return array('totals'=>$totals,'categories'=>$categories);
    }
    private static function store_post($run, $post) {
        global $wpdb; $t = self::table();
        $base = get_permalink($post->ID);
        $rows = OGO_MC_Audit_Engine::extract($post->post_content, is_string($base) && $base !== '' ? $base : home_url('/'));
        if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('Не вдалося почати запис звіту.');
        try {
            if ($wpdb->delete($t, array('run_id'=>$run, 'post_id'=>$post->ID)) === false) throw new RuntimeException('Не вдалося оновити рядки матеріалу у звіті.');
            foreach ($rows as $row) {
                $row['run_id'] = $run; $row['post_id'] = $post->ID;
                if ($wpdb->insert($t, $row) === false) throw new RuntimeException('Не вдалося зберегти посилання. Продовжіть сканування після усунення помилки.');
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('Не вдалося завершити запис звіту.');
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }
    public static function ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error('Недостатньо прав.',403);
        check_ajax_referer('ogo_mc_audit','nonce');
        if (!self::ready()) wp_send_json_error('Таблиця ревізії недоступна. Оновіть сторінку; перевірте права бази на створення таблиці.');
        $mode = sanitize_key($_POST['mode'] ?? '');
        if (!in_array($mode, array('start','scan'), true)) wp_send_json_error('Ревізія підтримує лише сканування. Заміни й відкат тут недоступні.');
        $lock = array('time'=>time(),'token'=>wp_generate_uuid4());
        if (!add_option('ogo_mc_lock', $lock, '', 'no')) {
            $old = get_option('ogo_mc_lock');
            if ((is_array($old) ? (int)$old['time'] : (int)$old) < time()-600) { delete_option('ogo_mc_lock'); wp_send_json_error('Прибрано прострочене блокування. Повторіть дію.'); }
            wp_send_json_error('Інша операція вже виконується. Повторіть згодом.');
        }
        register_shutdown_function(function() use ($lock) { if (get_option('ogo_mc_lock') === $lock) delete_option('ogo_mc_lock'); });
        $error = null; $result = null;
        try {
            global $wpdb;
            $s = self::state();
            if ($mode === 'start') {
                $max = $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
                if ($wpdb->last_error) throw new RuntimeException('Не вдалося визначити межу сканування.');
                $s = array('run'=>wp_generate_uuid4(),'cursor'=>0,'max'=>(int)$max,'scanned'=>0,'complete'=>false,'version'=>self::VERSION,'started'=>current_time('mysql'));
                self::save_state($s);
                $history = get_option('ogo_mc_audit_history', array());
                array_unshift($history, $s['run']); update_option('ogo_mc_audit_history', array_values(array_unique($history)), false);
            } else {
                $run = sanitize_text_field(wp_unslash($_POST['run'] ?? ''));
                if (empty($s['run']) || $run !== $s['run']) throw new RuntimeException('Відкрийте поточну ревізію або почніть нове сканування.');
                if (($s['version'] ?? '') !== self::VERSION) throw new RuntimeException('Після оновлення почніть нову ревізію.');
                if (!$s['complete']) {
                    $types = implode(',', array_fill(0, count(self::TYPES), '%s'));
                    $posts = $wpdb->get_results($wpdb->prepare("SELECT ID,post_content FROM {$wpdb->posts} WHERE ID>%d AND ID<=%d AND post_type IN ($types) AND post_status IN ('publish','private','draft','pending','future') ORDER BY ID LIMIT 50", array_merge(array($s['cursor'],$s['max']),self::TYPES)));
                    if ($wpdb->last_error) throw new RuntimeException('Не вдалося прочитати матеріали.');
                    foreach ($posts as $post) {
                        self::store_post($s['run'], $post);
                        $s['cursor'] = (int)$post->ID; ++$s['scanned'];
                        self::save_state($s);
                    }
                    if (count($posts) < 50) $s['complete'] = true;
                    self::save_state($s);
                }
            }
            $result = array('state'=>$s,'stats'=>self::stats($s['run']));
        } catch (Throwable $e) { $error = $e->getMessage(); }
        finally { if (get_option('ogo_mc_lock') === $lock) delete_option('ogo_mc_lock'); }
        if ($error !== null) wp_send_json_error($error);
        wp_send_json_success($result);
    }
    private static function filters($input) {
        $category = sanitize_key($input['category'] ?? '');
        if (!isset(OGO_MC_Audit_Engine::categories()[$category])) $category = '';
        $group = sanitize_text_field(wp_unslash($input['group'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/D', $group)) $group = '';
        return array($category,$group);
    }
    private static function where($run, $category, $group = '') {
        global $wpdb;
        $sql = $wpdb->prepare('run_id=%s', $run);
        if ($category !== '') $sql .= $wpdb->prepare(' AND category=%s', $category);
        if ($group !== '') $sql .= $wpdb->prepare(' AND group_key=%s', $group);
        return $sql;
    }
    public static function groups($run, $category = '', $limit = 30, $offset = 0, $after = null) {
        global $wpdb; $t = self::table(); $where = self::where($run,$category);
        if ($after !== null) $where .= $wpdb->prepare(' AND group_key>%s', $after);
        $order = $after !== null ? 'group_key' : "FIELD(MIN(category),'unknown_rubric','known','old_article','other','active'),links DESC,group_key";
        $rows = $wpdb->get_results($wpdb->prepare("SELECT group_key,MIN(group_url) AS group_url,MIN(category) AS category,MIN(target_url) AS target_url,MIN(suggestion) AS suggestion,SUM(link_count) AS links,COUNT(DISTINCT post_id) AS materials,COUNT(DISTINCT variant_key) AS variants FROM $t WHERE $where GROUP BY group_key ORDER BY $order LIMIT %d OFFSET %d", $limit,$offset), ARRAY_A);
        if ($wpdb->last_error) throw new RuntimeException('Не вдалося прочитати згрупований звіт.');
        return $rows;
    }
    public static function details($run, $category = '', $group = '', $limit = 50, $offset = 0, $after = null) {
        global $wpdb; $t = self::table(); $where = self::where($run,$category,$group);
        if ($after !== null) $where .= $wpdb->prepare(' AND id>%d', $after);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY id LIMIT %d OFFSET %d", $limit,$offset), ARRAY_A);
        if ($wpdb->last_error) throw new RuntimeException('Не вдалося прочитати матеріали звіту.');
        return $rows;
    }
    private static function url($args = array()) {
        return add_query_arg(array_merge(array('page'=>'ogo-media-cleaner','project'=>'audit'),$args), admin_url('tools.php'));
    }
    private static function export_url($run,$kind,$category,$group) {
        return wp_nonce_url(add_query_arg(array('action'=>'ogo_mc_audit_export','run'=>$run,'kind'=>$kind,'category'=>$category,'group'=>$group),admin_url('admin-post.php')), 'ogo_mc_audit_export_'.$run);
    }
    public static function export() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.', '', array('response'=>403));
        $run = sanitize_text_field(wp_unslash($_GET['run'] ?? ''));
        if (!self::valid_run($run)) wp_die('Невідомий запуск.');
        check_admin_referer('ogo_mc_audit_export_'.$run);
        if (!self::ready() || !self::state($run)) wp_die('Звіт ревізії недоступний.');
        list($category,$group) = self::filters($_GET);
        $kind = ($_GET['kind'] ?? '') === 'details' ? 'details' : 'groups';
        global $wpdb;
        if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false || $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') === false) wp_die('Не вдалося відкрити узгоджений звіт для експорту.');
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="ogo-audit-'.$kind.'-'.$run.'.csv"');
        $stream = fopen('php://output','w'); fwrite($stream,"\xEF\xBB\xBF");
        $labels = OGO_MC_Audit_Engine::categories();
        if ($kind === 'groups') {
            OGO_MC_Audit_Engine::csv_row($stream,array('group_url','category','links','materials','variants','suggested_target','suggestion'));
            $after = '';
            do {
                $rows = self::groups($run,$category,500,0,$after);
                foreach ($rows as $r) {
                    OGO_MC_Audit_Engine::csv_row($stream,array($r['group_url'],$labels[$r['category']],$r['links'],$r['materials'],$r['variants'],$r['target_url'],$r['suggestion']));
                    $after = $r['group_key'];
                }
            } while (count($rows) === 500);
        } else {
            OGO_MC_Audit_Engine::csv_row($stream,array('group_url','category','original_href','resolved_url','links_in_material','post_id','post_title','edit_url','suggested_target','suggestion'));
            $after = 0;
            do {
                $rows = self::details($run,$category,$group,500,0,$after);
                foreach ($rows as $r) {
                    OGO_MC_Audit_Engine::csv_row($stream,array($r['group_url'],$labels[$r['category']],$r['raw_url'],$r['resolved_url'],$r['link_count'],$r['post_id'],get_the_title($r['post_id']),get_edit_post_link($r['post_id'],'raw'),$r['target_url'],$r['suggestion']));
                    $after = (int)$r['id'];
                }
            } while (count($rows) === 500);
        }
        fclose($stream); $wpdb->query('COMMIT'); exit;
    }
    public static function page() {
        if (!current_user_can('manage_options')) return;
        if (!self::ready()) { echo '<div class="notice notice-error"><p>Не вдалося створити таблицю ревізії. Перевірте права бази даних на створення таблиць InnoDB.</p></div>'; return; }
        $current = self::state();
        $run = sanitize_text_field(wp_unslash($_GET['run'] ?? ($current['run'] ?? '')));
        if (!self::valid_run($run)) $run = '';
        $s = $run !== '' ? self::state($run) : array();
        list($category,$group) = self::filters($_GET);
        $page = max(1,absint($_GET['paged'] ?? 1));
        $labels = OGO_MC_Audit_Engine::categories(); $projects = OGO_MC_Engine::projects();
        $stats = array('totals'=>array('urls'=>0,'links'=>0,'materials'=>0),'categories'=>array()); $rows = array();
        try {
            if ($s) { $stats = self::stats($run); $rows = $group !== '' ? self::details($run,$category,$group,50,($page-1)*50) : self::groups($run,$category,30,($page-1)*30); }
        } catch (Throwable $e) { echo '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div>'; return; }
        wp_enqueue_script('ogo-audit',plugins_url('assets/audit.js',dirname(__DIR__).'/ogo-media-cleaner.php'),array(),self::VERSION,true);
        wp_localize_script('ogo-audit','ogoAudit',array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ogo_mc_audit'),'run'=>$current['run'] ?? '', 'reportUrl'=>self::url()));
        ?>
        <div class="wrap"><h1>OGO cleaner — Ревізія старих посилань</h1>
        <p><strong>Версія <?php echo esc_html(self::VERSION); ?>. Лише звіт: статті та їхні дати не змінюються.</strong></p>
        <form method="get"><input type="hidden" name="page" value="ogo-media-cleaner"><label>Проєкт / операція <select name="project"><?php foreach ($projects as $key=>$config) echo '<option value="'.esc_attr($key).'" '.selected('audit',$key,false).'>'.esc_html($config['label']).'</option>'; ?></select></label> <button class="button">Відкрити</button></form>
        <p>Перевіряє посилання a[href] у post_content типів post, page, rivnenschina, dovidka, phogo, press: опубліковані, приватні, чернетки, на модерації та заплановані. Меню, віджети, метаполя, голі URL, коментарі та блоки коду не сканує. Домени: ogo.ua, ogo.rv.ua та їхні піддомени. HTTP-перевірки сторінок не виконує.</p>
        <p>Активні лише рубрики головного сайту: exclusive, life, nadzvychaini, region, opytuvannya, consumer, top, ukraine, foto-dnya. Інші адреси не оголошуються непрацюючими автоматично. Відомий відповідник — пропозиція з карти, а не готова заміна.</p>
        <p>Варіанти http/https, www, кінцевий слеш, якорі та числові сторінки рубрик згруповані. Оригінальні href збережені в деталях. Відносні посилання визначаються від адреси матеріалу. Параметри звичайних сторінок зберігають окремі групи, щоб не змішувати різні записи.</p>
        <p><button class="button button-primary" id="ogo-audit-start">Нова ревізія</button> <button class="button" id="ogo-audit-scan" <?php disabled(empty($current['run']) || !empty($current['complete'])); ?>>Продовжити поточну ревізію</button> <button class="button" id="ogo-audit-stop">Зупинити після поточної порції</button></p>
        <p id="ogo-audit-progress" role="status" aria-live="polite">Поточна ревізія: перевірено <?php echo (int)($current['scanned'] ?? 0); ?> матеріалів. <?php echo !empty($current['complete']) ? 'Завершена.' : 'Можна продовжити сканування.'; ?></p>
        <form method="get"><input type="hidden" name="page" value="ogo-media-cleaner"><input type="hidden" name="project" value="audit"><label>Запуск <select name="run"><?php foreach (get_option('ogo_mc_audit_history',array()) as $id) if (self::valid_run($id)) { $meta = self::state($id); echo '<option value="'.esc_attr($id).'" '.selected($run,$id,false).'>'.esc_html(($meta['started'] ?? $id).' — '.(!empty($meta['complete']) ? 'завершено' : 'частково')).'</option>'; } ?></select></label> <label>Група <select name="category"><option value="">Усі</option><?php foreach ($labels as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($category,$key,false).'>'.esc_html($label).'</option>'; ?></select></label> <button class="button">Показати</button></form>
        <?php if ($s): ?>
        <p><strong><?php echo !empty($s['complete']) ? 'Завершений звіт' : 'Частковий звіт — сканування не завершене'; ?>.</strong> Перевірено матеріалів: <?php echo (int)$s['scanned']; ?>. Знайдено груп URL: <?php echo (int)$stats['totals']['urls']; ?>; посилань: <?php echo (int)$stats['totals']['links']; ?>; матеріалів із посиланнями: <?php echo (int)$stats['totals']['materials']; ?>. Результати відображають вміст на момент сканування.</p>
        <?php foreach ($stats['categories'] as $c) echo '<p>'.esc_html($labels[$c['category']].': '.$c['urls'].' груп URL; '.$c['links'].' посилань; '.$c['materials'].' матеріалів.').'</p>'; ?>
        <p><a class="button" href="<?php echo esc_url(self::export_url($run,'groups',$category,'')); ?>">CSV — згрупований звіт</a> <a class="button" href="<?php echo esc_url(self::export_url($run,'details',$category,$group)); ?>">CSV — оригінальні адреси та матеріали</a></p>
        <?php if ($group !== ''): ?>
        <p><a href="<?php echo esc_url(self::url(array('run'=>$run,'category'=>$category))); ?>">← До списку адрес</a></p>
        <table class="widefat striped"><thead><tr><th>Група URL</th><th>Оригінальний href</th><th>Матеріал</th><th>Посилань у матеріалі</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td><?php echo esc_html($r['group_url']); ?></td><td><code style="overflow-wrap:anywhere"><?php echo esc_html($r['raw_url']); ?></code><br><a href="<?php echo esc_url($r['resolved_url']); ?>" target="_blank" rel="noopener noreferrer">Відкрити адресу</a></td><td><a href="<?php echo esc_url(get_edit_post_link($r['post_id'])); ?>"><?php echo esc_html(get_the_title($r['post_id']) ?: 'Без назви'); ?></a><br>ID <?php echo (int)$r['post_id']; ?></td><td><?php echo (int)$r['link_count']; ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php else: ?>
        <table class="widefat striped"><thead><tr><th>Група URL</th><th>Класифікація</th><th>Посилань</th><th>Матеріалів</th><th>Варіантів href</th><th>Пропозиція</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td style="overflow-wrap:anywhere"><a href="<?php echo esc_url(self::url(array('run'=>$run,'category'=>$category,'group'=>$r['group_key']))); ?>"><?php echo esc_html($r['group_url']); ?></a></td><td><?php echo esc_html($labels[$r['category']]); ?></td><td><?php echo (int)$r['links']; ?></td><td><?php echo (int)$r['materials']; ?></td><td><?php echo (int)$r['variants']; ?></td><td><?php echo esc_html($r['suggestion']); ?><?php if ($r['target_url'] !== ''): ?><br><a href="<?php echo esc_url($r['target_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($r['target_url']); ?></a><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <?php if (!$rows) echo '<p>У вибраній групі посилань немає.</p>'; ?>
        <p><?php if ($page > 1) echo '<a class="button" href="'.esc_url(self::url(array('run'=>$run,'category'=>$category,'group'=>$group,'paged'=>$page-1))).'">Назад</a> '; if (count($rows) === ($group !== '' ? 50 : 30)) echo '<a class="button" href="'.esc_url(self::url(array('run'=>$run,'category'=>$category,'group'=>$group,'paged'=>$page+1))).'">Далі</a>'; ?></p>
        <?php else: ?><p>Почніть нову ревізію, щоб отримати перелік адрес.</p><?php endif; ?>
        </div>
        <?php
    }
}
