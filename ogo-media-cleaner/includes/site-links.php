<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/site-links-engine.php';

/** Read-only inventory for published posts, with independent reports and progress. */
final class OGO_MC_Site_Links {
    private const SCHEMA='1.0.0';
    private const BATCH=200;
    private const ACTION='ogo_mc_site_links';
    private const PAID=array(0=>'Безкоштовне (без позначки)',1=>'Платне');
    private const CLOSED=array(0=>'Відкрите',1=>'Закрите');

    private static function table() { global $wpdb; return $wpdb->prefix.'ogo_site_links'; }
    private static function ready() { return get_option('ogo_mc_site_links_schema')===self::SCHEMA; }
    public static function init() {
        add_action('admin_init',function(){if (current_user_can('manage_options') && !self::ready()) self::activate();});
        add_action('wp_ajax_'.self::ACTION,array(__CLASS__,'ajax'));
    }
    public static function activate() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            run_id char(36) NOT NULL,
            post_id bigint unsigned NOT NULL,
            domain varchar(253) NOT NULL,
            variant_key char(64) NOT NULL,
            url_key char(64) NOT NULL,
            paid tinyint unsigned NOT NULL,
            ad_marker tinyint unsigned NOT NULL,
            closed tinyint unsigned NOT NULL,
            rel_tokens text NOT NULL,
            new_tab tinyint unsigned NOT NULL,
            target_url longtext NOT NULL,
            anchor_text longtext NOT NULL,
            link_count int unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_post_variant (run_id,post_id,variant_key),
            KEY run_domain (run_id,domain,paid,closed)
        ) ENGINE=InnoDB $charset;");
        $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$t));
        if ($status && strtolower($status->Engine)==='innodb') update_option('ogo_mc_site_links_schema',self::SCHEMA,false);
    }
    private static function valid_run($run) {
        return preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$run)===1;
    }
    private static function state($run) { return get_option(self::ACTION.'_run_'.$run,array()); }
    private static function save($state) {
        $key=self::ACTION.'_run_'.$state['run'];
        if (!update_option($key,$state,false) && get_option($key)!==$state) throw new RuntimeException('Не вдалося зберегти прогрес ревізії.');
    }

    private static function scan($state) {
        global $wpdb; $t=self::table();
        $posts=$wpdb->get_results($wpdb->prepare(
            "SELECT ID,post_content FROM {$wpdb->posts} WHERE ID>%d AND ID<=%d AND post_type='post' AND post_status='publish' ORDER BY ID LIMIT %d",
            $state['cursor'],$state['max'],self::BATCH
        ));
        if ($wpdb->last_error) throw new RuntimeException('Не вдалося прочитати статті.');
        if (!$posts) { $state['complete']=true; return $state; }
        $rows=array();
        foreach ($posts as $post) {
            list($links,$review)=OGO_MC_Site_Links_Engine::inspect($post->post_content);
            foreach ($links as $link) $rows[]=array_merge(array($state['run'],$post->ID),array_values($link));
            $state['review']+=$review;
        }
        $last=end($posts);
        if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('Не вдалося почати запис результатів.');
        try {
            if ($wpdb->query($wpdb->prepare("DELETE FROM $t WHERE run_id=%s AND post_id>%d AND post_id<=%d",$state['run'],$state['cursor'],$last->ID))===false) {
                throw new RuntimeException('Не вдалося оновити порцію звіту.');
            }
            $columns='run_id,post_id,domain,variant_key,url_key,paid,ad_marker,closed,rel_tokens,new_tab,target_url,anchor_text,link_count';
            foreach (array_chunk($rows,100) as $batch) {
                $values=implode(',',array_fill(0,count($batch),'(%s,%d,%s,%s,%s,%d,%d,%d,%s,%d,%s,%s,%d)'));
                if ($wpdb->query($wpdb->prepare("INSERT INTO $t ($columns) VALUES $values",array_merge(...$batch)))===false) {
                    throw new RuntimeException('Не вдалося записати посилання.');
                }
            }
            if ($wpdb->query('COMMIT')===false) throw new RuntimeException('Не вдалося завершити запис порції.');
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
        $state['cursor']=(int)$last->ID;
        $state['scanned']+=count($posts);
        $state['complete']=count($posts)<self::BATCH;
        return $state;
    }

    public static function ajax() {
        if (!current_user_can('edit_others_posts')) wp_send_json_error('Недостатньо прав.',403);
        check_ajax_referer(self::ACTION,'nonce');
        if (!self::ready()) wp_send_json_error('Таблиця аудиту недоступна.');
        $mode=sanitize_key($_POST['mode']??'');
        if (!in_array($mode,array('start','scan'),true)) wp_send_json_error('Ця операція лише складає звіт.');
        $lock=array('time'=>time(),'token'=>wp_generate_uuid4());
        if (!add_option('ogo_mc_lock',$lock,'','no')) {
            $old=get_option('ogo_mc_lock');
            if ((is_array($old)?(int)$old['time']:(int)$old)<time()-600) {
                delete_option('ogo_mc_lock');
                wp_send_json_error('Прибрано прострочене блокування. Повторіть дію.');
            }
            wp_send_json_error('Інша операція вже виконується. Повторіть згодом.');
        }
        $error=null; $state=array();
        try {
            global $wpdb;
            $history=get_option(self::ACTION.'_history',array());
            if ($mode==='start') {
                $max=$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
                if ($wpdb->last_error) throw new RuntimeException('Не вдалося визначити межу сканування.');
                $state=array('run'=>wp_generate_uuid4(),'cursor'=>0,'max'=>(int)$max,'scanned'=>0,'review'=>0,'complete'=>false,
                    'started'=>current_time('mysql'));
                self::save($state);
                array_unshift($history,$state['run']);
                if (!update_option(self::ACTION.'_history',$history,false)) throw new RuntimeException('Не вдалося зберегти запуск у журналі.');
            } else {
                $run=sanitize_text_field(wp_unslash($_POST['run']??''));
                if (!self::valid_run($run) || ($history[0]??'')!==$run) throw new RuntimeException('Відкрийте поточну ревізію.');
                $state=self::state($run);
                if (!$state) throw new RuntimeException('Поточний запуск недоступний.');
                if (!$state['complete']) {
                    if (isset($state['paid_revision'])) throw new RuntimeException('Після оновлення почніть нову ревізію.');
                    $state=self::scan($state);
                    self::save($state);
                }
            }
        } catch (Throwable $e) { $error=$e->getMessage(); }
        finally { if (get_option('ogo_mc_lock')===$lock) delete_option('ogo_mc_lock'); }
        if ($error!==null) wp_send_json_error($error);
        wp_send_json_success(array('state'=>$state));
    }

    private static function url($args=array()) {
        return add_query_arg(array_merge(array('page'=>'ogo-media-cleaner','project'=>'site_links'),$args),admin_url('tools.php'));
    }
    private static function results($run,$domain,$paid,$closed,$limit,$offset,$legacy_paid) {
        global $wpdb; $t=self::table(); $where=$wpdb->prepare('run_id=%s',$run);
        // In 1.44.0, paid could include private article-level signals.
        $public_paid=$legacy_paid?"(CASE WHEN ad_marker=1 OR FIND_IN_SET('sponsored',REPLACE(rel_tokens,' ',','))>0 THEN 1 ELSE 0 END)":"$t.paid";
        if ($domain!=='') $where.=$wpdb->prepare(' AND domain=%s',$domain);
        if ($paid!=='') $where.=$wpdb->prepare(" AND $public_paid=%d",$paid);
        if ($closed!=='') $where.=$wpdb->prepare(' AND closed=%d',$closed);
        $query=$domain===''
            ? "SELECT domain,$public_paid AS paid,closed,COUNT(DISTINCT post_id) AS articles,COUNT(DISTINCT url_key) AS urls FROM $t WHERE $where GROUP BY domain,$public_paid,closed ORDER BY articles DESC,domain,paid DESC,closed"
            : "SELECT * FROM $t WHERE $where ORDER BY post_id DESC,id";
        $rows=$wpdb->get_results($query.$wpdb->prepare(' LIMIT %d OFFSET %d',$limit,$offset),ARRAY_A);
        if ($wpdb->last_error) throw new RuntimeException('Не вдалося прочитати звіт.');
        return $rows;
    }

    public static function page() {
        if (!current_user_can('edit_others_posts')) return;
        if (!self::ready()) { echo '<div class="notice notice-error"><p>Таблиця аудиту недоступна. Відкрийте адмінку від імені адміністратора для створення таблиці.</p></div>'; return; }
        $history=get_option(self::ACTION.'_history',array());
        $current_run=$history[0]??'';
        $current=$current_run!==''?self::state($current_run):array();
        $run=sanitize_text_field(wp_unslash($_GET['run']??$current_run));
        $state=self::valid_run($run) && in_array($run,$history,true)?self::state($run):array();
        $domain=sanitize_text_field(wp_unslash($_GET['domain']??''));
        $paid=sanitize_key(wp_unslash($_GET['paid']??''));
        $closed=sanitize_key(wp_unslash($_GET['closed']??''));
        if (!in_array($paid,array('0','1'),true)) $paid='';
        if (!in_array($closed,array('0','1'),true)) $closed='';
        $page=max(1,absint($_GET['paged']??1)); $limit=40;
        try { $rows=$state?self::results($run,$domain,$paid,$closed,$limit+1,($page-1)*$limit,isset($state['paid_revision'])):array(); }
        catch (Throwable $e) { echo '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div>'; return; }
        wp_enqueue_script('ogo-site-links',plugins_url('assets/audit.js',dirname(__DIR__).'/ogo-media-cleaner.php'),array(),OGO_Media_Cleaner::VERSION,true);
        wp_localize_script('ogo-site-links','ogoAudit',array('url'=>admin_url('admin-ajax.php'),'action'=>self::ACTION,'nonce'=>wp_create_nonce(self::ACTION),'run'=>$current_run,'reportUrl'=>self::url()));
        ?>
        <div class="wrap"><h1>OGO Cleaner — Зовнішні посилання</h1>
        <?php OGO_Media_Cleaner::navigation('site_links'); ?>
        <p>Аудит рахує унікальні зовнішні URL в основному тексті опублікованих статей. Кожен рядок домену відповідає комбінації двох ознак. Стаття або URL можуть потрапити у кілька рядків.</p>
        <p><strong>Платне</strong> визначається за позначкою «На правах реклами» в тексті статті або за <code>rel="sponsored"</code> у посиланні. Позначка статті охоплює всі її посилання для подальшої перевірки. «Безкоштовне» означає відсутність цих ознак. <strong>Закрите</strong> має <code>nofollow</code>, <code>sponsored</code> або <code>ugc</code> у <code>rel</code>. Відкриття в новій вкладці показано окремо в деталях.</p>
        <p><button class="button button-primary" id="ogo-audit-start">Нова ревізія</button>
        <button class="button" id="ogo-audit-scan" <?php disabled(!$current || !empty($current['complete'])); ?>>Продовжити поточну ревізію</button>
        <button class="button" id="ogo-audit-stop">Зупинити після поточної порції</button></p>
        <p id="ogo-audit-progress" role="status" aria-live="polite"></p>
        <?php if ($state): ?>
        <form method="get">
        <input type="hidden" name="page" value="ogo-media-cleaner"><input type="hidden" name="project" value="site_links">
        <label>Запуск <select name="run"><?php foreach ($history as $id) { $meta=self::state($id); if (!$meta) continue; ?>
        <option value="<?php echo esc_attr($id); ?>" <?php selected($run,$id); ?>><?php echo esc_html($meta['started'].($meta['complete']?' — завершено':' — частково')); ?></option>
        <?php } ?></select></label>
        <label>Реклама <select name="paid"><option value="">Усі</option><?php foreach (self::PAID as $value=>$label): ?><option value="<?php echo (int)$value; ?>" <?php selected($paid,(string)$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
        <label>Стан <select name="closed"><option value="">Усі</option><?php foreach (self::CLOSED as $value=>$label): ?><option value="<?php echo (int)$value; ?>" <?php selected($closed,(string)$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
        <button class="button">Показати домени</button>
        </form>
        <p><?php echo $state['complete']?'Ревізію завершено.':'Частковий звіт.'; ?> Перевірено статей: <?php echo (int)$state['scanned']; ?>. Неоднозначних посилань поза підрахунком: <?php echo (int)$state['review']; ?>.</p>
        <?php if ($domain===''): ?>
        <table class="widefat striped"><thead><tr><th>Домен</th><th>К-ть статей</th><th>К-ть URL</th><th>Платне / Безкоштовне</th><th>Закрите / Відкрите</th></tr></thead><tbody>
        <?php foreach (array_slice($rows,0,$limit) as $r): ?>
        <tr><td><a href="<?php echo esc_url(self::url(array('run'=>$run,'domain'=>$r['domain'],'paid'=>$r['paid'],'closed'=>$r['closed']))); ?>"><?php echo esc_html($r['domain']); ?></a></td>
        <td><?php echo (int)$r['articles']; ?></td><td><?php echo (int)$r['urls']; ?></td><td><?php echo esc_html(self::PAID[$r['paid']]); ?></td><td><?php echo esc_html(self::CLOSED[$r['closed']]); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php else: ?>
        <p><a href="<?php echo esc_url(self::url(array('run'=>$run,'paid'=>$paid,'closed'=>$closed))); ?>">← До доменів</a></p>
        <h2><?php echo esc_html($domain); ?></h2>
        <table class="widefat striped"><thead><tr><th>Стаття</th><th>URL і анкор</th><th>Ознаки реклами</th><th>Стан і атрибути</th><th>Входжень</th></tr></thead><tbody>
        <?php foreach (array_slice($rows,0,$limit) as $r):
            $id=(int)$r['post_id']; $edit=get_edit_post_link($id);
            $signals=array();
            if ($r['ad_marker']) $signals[]='«На правах реклами»';
            if (in_array('sponsored',explode(' ',$r['rel_tokens']),true)) $signals[]='rel=sponsored';
        ?>
        <tr><td><?php if ($edit): ?><a href="<?php echo esc_url($edit); ?>"><?php endif; ?><?php echo esc_html(get_the_title($id)?:'Стаття '.$id); ?><?php if ($edit): ?></a><?php endif; ?></td>
        <td style="overflow-wrap:anywhere"><code><?php echo esc_html($r['target_url']); ?></code><br><?php echo esc_html($r['anchor_text']); ?></td>
        <td><?php echo esc_html($signals?implode(', ',$signals):'Без позначки'); ?></td>
        <td><?php echo esc_html(self::CLOSED[$r['closed']]); ?><br><code><?php echo esc_html($r['rel_tokens']!==''?$r['rel_tokens']:'rel відсутній'); ?></code><?php if ($r['new_tab']): ?><br><code>target="_blank"</code><?php endif; ?></td>
        <td><?php echo (int)$r['link_count']; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <?php if (!$rows): ?><p>У вибраному звіті немає збігів.</p><?php endif; ?>
        <p><?php $args=array('run'=>$run,'domain'=>$domain,'paid'=>$paid,'closed'=>$closed);
        if ($page>1) echo '<a class="button" href="'.esc_url(self::url($args+array('paged'=>$page-1))).'">Назад</a> ';
        if (count($rows)>$limit) echo '<a class="button" href="'.esc_url(self::url($args+array('paged'=>$page+1))).'">Далі</a>'; ?></p>
        <?php endif; ?>
        </div>
        <?php
    }
}
