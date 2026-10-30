<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/inventory.php';
require_once __DIR__.'/list.php';
require_once __DIR__.'/helpers.php';
/** Independent read-only report for the approved workbook article list. */
final class OGO_MLA_Report {
    private const VERSION='1.1.0';
    private const SCHEMA='1.0.0';
    private static function table() { global $wpdb; return $wpdb->prefix.'ogo_miralinks_audit'; }
    public static function init() {
        add_action('admin_init',function(){if(current_user_can('manage_options') && get_option('ogo_mla_schema')!==self::SCHEMA)self::activate();});
        add_action('wp_ajax_ogo_mla',array(__CLASS__,'ajax'));
        add_action('admin_post_ogo_mla_export',array(__CLASS__,'export'));
    }
    public static function activate() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=self::table();$charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(36) NOT NULL,
            post_id bigint unsigned NOT NULL,
            variant_key char(64) NOT NULL,
            url_key char(64) NOT NULL,
            kind varchar(16) NOT NULL,
            domain varchar(253) NOT NULL,
            target_url longtext NOT NULL,
            raw_url longtext NOT NULL,
            anchor_text longtext NOT NULL,
            issue text NOT NULL,
            link_count int unsigned NOT NULL,
            post_title text NOT NULL,
            post_status varchar(20) NOT NULL,
            list_group varchar(32) NOT NULL,
            post_url text NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_post_variant (run_id,post_id,variant_key),
            KEY run_domain (run_id,domain),
            KEY run_kind (run_id,kind)
        ) ENGINE=InnoDB $charset;");
        $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$t));
        if($status && strtolower($status->Engine)==='innodb')update_option('ogo_mla_schema',self::SCHEMA,false);
    }
    public static function kinds() {
        return array('external'=>'Зовнішні — призначення перевірити','network'=>'Пов’язані редакційні сайти','review'=>'Пошкоджені адреси / HTML','empty'=>'Зовнішніх посилань у тексті немає','missing'=>'Запису з таким ID немає','skipped'=>'Пропущено: тип або статус запису');
    }
    private static function valid_run($run) { return is_string($run) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$run); }
    public static function state($run='') { return get_option($run===''?'ogo_mla_scan':'ogo_mla_run_'.$run,array()); }
    private static function save($s) {
        foreach(array('ogo_mla_scan','ogo_mla_run_'.$s['run']) as $key) {
            if(!update_option($key,$s,false) && get_option($key)!==$s)throw new RuntimeException('Не вдалося зберегти прогрес ревізії.');
        }
    }
    private static function store($run,$id,$post) {
        global $wpdb;$t=self::table();$rows=array();$kind='empty';$issue='Перевірено лише post_content. Поле anons не читалося.';
        if(!$post){$kind='missing';$issue='Запис із ID таблиці не знайдено; відповідність вручну.';}
        elseif($post->post_type!=='post' || !in_array($post->post_status,array('publish','private','draft','pending','future'),true)){$kind='skipped';$issue='Тип: '.$post->post_type.'; статус: '.$post->post_status;}
        else $rows=OGO_MLA_Inventory::extract($post->post_content);
        if(!$rows)$rows[]=array('kind'=>$kind,'domain'=>'','target_url'=>'','raw_url'=>'','anchor_text'=>'','issue'=>$issue,'link_count'=>0,'variant_key'=>hash('sha256','status'),'url_key'=>hash('sha256',''));
        $permalink=$post?get_permalink((int)$id):'';
        if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('Не вдалося почати запис звіту.');
        try {
            if($wpdb->delete($t,array('run_id'=>$run,'post_id'=>$id))===false)throw new RuntimeException('Не вдалося оновити звіт матеріалу.');
            foreach($rows as $row) {
                $row['run_id']=$run;$row['post_id']=$id;$row['list_group']=OGO_MLA_List::bucket($id);
                $row['post_title']=$post?$post->post_title:'';$row['post_status']=$post?$post->post_status:'';$row['post_url']=is_string($permalink)?$permalink:'';
                if($wpdb->insert($t,$row)===false)throw new RuntimeException('Не вдалося записати посилання.');
            }
            if($wpdb->query('COMMIT')===false)throw new RuntimeException('Не вдалося завершити запис звіту.');
        } catch(Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }
    public static function stats($run) {
        global $wpdb;$t=self::table();
        $totals=$wpdb->get_row($wpdb->prepare("SELECT COUNT(DISTINCT CASE WHEN link_count>0 THEN url_key END) AS urls,COUNT(DISTINCT CASE WHEN link_count>0 THEN post_id END) AS materials,COALESCE(SUM(link_count),0) AS links,COUNT(DISTINCT post_id) AS checked FROM $t WHERE run_id=%s",$run),ARRAY_A);
        if($wpdb->last_error)throw new RuntimeException('Не вдалося прочитати підсумки.');
        $kinds=$wpdb->get_results($wpdb->prepare("SELECT kind,COUNT(DISTINCT post_id) AS materials,COALESCE(SUM(link_count),0) AS links FROM $t WHERE run_id=%s GROUP BY kind",$run),ARRAY_A);
        if($wpdb->last_error)throw new RuntimeException('Не вдалося прочитати статуси звіту.');
        return array('totals'=>$totals,'kinds'=>$kinds);
    }
    public static function ajax() {
        if(!current_user_can('edit_others_posts'))wp_send_json_error('Недостатньо прав.',403);
        check_ajax_referer('ogo_mla','nonce');
        if(get_option('ogo_mla_schema')!==self::SCHEMA)wp_send_json_error('Таблиця ревізії недоступна. Оновіть сторінку й перевірте права бази.');
        $mode=sanitize_key($_POST['mode']??'');
        if(!in_array($mode,array('start','scan'),true))wp_send_json_error('Ця операція лише складає звіт. Очищення тут недоступне.');
        $lock=array('time'=>time(),'token'=>wp_generate_uuid4());
        if(!add_option('ogo_mc_lock',$lock,'','no')) {
            $old=get_option('ogo_mc_lock');
            if((is_array($old)?(int)$old['time']:(int)$old)<time()-600){delete_option('ogo_mc_lock');wp_send_json_error('Прибрано прострочене блокування. Повторіть дію.');}
            wp_send_json_error('Інша операція вже виконується. Повторіть згодом.');
        }
        register_shutdown_function(function()use($lock){if(get_option('ogo_mc_lock')===$lock)delete_option('ogo_mc_lock');});
        $error=null;$result=null;
        try {
            global $wpdb;$s=self::state();$ids=OGO_MLA_List::ids();
            if($mode==='start') {
                $s=array('run'=>wp_generate_uuid4(),'cursor'=>0,'scanned'=>0,'total'=>count($ids),'complete'=>false,'version'=>self::VERSION,'manifest'=>OGO_MLA_List::digest(),'started'=>current_time('mysql'));
                self::save($s);$history=get_option('ogo_mla_history',array());array_unshift($history,$s['run']);update_option('ogo_mla_history',array_values(array_unique($history)),false);
            } else {
                $run=sanitize_text_field(wp_unslash($_POST['run']??''));
                if(empty($s['run']) || $run!==$s['run'])throw new RuntimeException('Відкрийте поточну ревізію або почніть нову.');
                if(($s['version']??'')!==self::VERSION || ($s['manifest']??'')!==OGO_MLA_List::digest())throw new RuntimeException('Натисніть «Нова ревізія», щоб почати перевірку.');
                if(!$s['complete']) {
                    $batch=array_slice($ids,(int)$s['cursor'],50);$posts=array();
                    if($batch) {
                        $placeholders=implode(',',array_fill(0,count($batch),'%d'));
                        $found=$wpdb->get_results($wpdb->prepare("SELECT ID,post_title,post_content,post_type,post_status FROM {$wpdb->posts} WHERE ID IN ($placeholders)",$batch));
                        if($wpdb->last_error)throw new RuntimeException('Помилка читання статей: відсутність записів не підтверджена.');
                        foreach($found as $post)$posts[(int)$post->ID]=$post;
                    }
                    foreach($batch as $id) {
                        self::store($s['run'],$id,$posts[$id]??null);
                        ++$s['cursor'];$s['scanned']=$s['cursor'];self::save($s);
                    }
                    $s['complete']=$s['cursor']>=count($ids);self::save($s);
                }
            }
            $result=array('state'=>$s,'stats'=>self::stats($s['run']));
        } catch(Throwable $e){$error=$e->getMessage();}
        finally {if(get_option('ogo_mc_lock')===$lock)delete_option('ogo_mc_lock');}
        if($error!==null)wp_send_json_error($error);
        wp_send_json_success($result);
    }
    private static function filters($input) {
        $kind=sanitize_key($input['kind']??'');if(!isset(self::kinds()[$kind]))$kind='';
        $domain=strtolower(sanitize_text_field(wp_unslash($input['domain']??'')));
        if(strlen($domain)>253 || preg_match('/[\x00-\x20\/?#@<>]/',$domain))$domain='';
        return array($kind,$domain);
    }
    private static function where($run,$kind,$domain) {
        global $wpdb;$where=$wpdb->prepare('run_id=%s',$run);
        if($kind!=='')$where.=$wpdb->prepare(' AND kind=%s',$kind);
        if($domain!=='')$where.=$wpdb->prepare(' AND domain=%s',$domain);
        return $where;
    }
    public static function domains($run,$kind='',$limit=30,$offset=0,$after=null) {
        global $wpdb;$t=self::table();$where=self::where($run,$kind,'').' AND link_count>0';
        if($after!==null)$where.=$wpdb->prepare($after===''?' AND domain>=%s':' AND domain>%s',$after);
        $order=$after===null?'links DESC,domain':'domain';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT domain,COUNT(DISTINCT post_id) AS materials,COUNT(DISTINCT url_key) AS urls,SUM(link_count) AS links FROM $t WHERE $where GROUP BY domain ORDER BY $order LIMIT %d OFFSET %d",$limit,$offset),ARRAY_A);
        if($wpdb->last_error)throw new RuntimeException('Не вдалося прочитати домени.');return $rows;
    }
    public static function details($run,$kind='',$domain='',$limit=50,$offset=0,$after=null) {
        global $wpdb;$t=self::table();$where=self::where($run,$kind,$domain);
        if($after!==null)$where.=$wpdb->prepare(' AND id>%d',$after);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY id LIMIT %d OFFSET %d",$limit,$offset),ARRAY_A);
        if($wpdb->last_error)throw new RuntimeException('Не вдалося прочитати деталі.');return $rows;
    }
    private static function url($args=array()) {return add_query_arg(array_merge(array('page'=>'ogo-miralinks-audit'),$args),admin_url('tools.php'));}
    private static function export_url($run,$report,$kind,$domain) {
        return wp_nonce_url(add_query_arg(array('action'=>'ogo_mla_export','run'=>$run,'report'=>$report,'kind'=>$kind,'domain'=>$domain),admin_url('admin-post.php')),'ogo_mla_export_'.$run);
    }
    public static function export() {
        if(!current_user_can('edit_others_posts'))wp_die('Недостатньо прав.','',array('response'=>403));
        $run=sanitize_text_field(wp_unslash($_GET['run']??''));
        if(!self::valid_run($run))wp_die('Невідомий запуск.');
        check_admin_referer('ogo_mla_export_'.$run);
        if(!self::state($run) || get_option('ogo_mla_schema')!==self::SCHEMA)wp_die('Звіт недоступний.');
        if((self::state($run)['manifest']??'')!==OGO_MLA_List::digest())wp_die('Звіт створено за попереднім списком, що міг включати приховані аркуші. Почніть нову ревізію та експортуйте її результат.');
        list($kind,$domain)=self::filters($_GET);$report=($_GET['report']??'')==='domains'?'domains':'details';
        global $wpdb;
        if($wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')===false || $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT')===false)wp_die('Не вдалося відкрити узгоджений звіт.');
        nocache_headers();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="ogo-external-'.$report.'-'.$run.'.csv"');
        $stream=fopen('php://output','w');fwrite($stream,"\xEF\xBB\xBF");$labels=self::kinds();
        if($report==='domains') {
            OGO_MLA_CSV::csv_row($stream,array('domain','materials','distinct_urls','links'));
            $after='';
            do {
                $rows=self::domains($run,$kind,500,0,$after);
                foreach($rows as $r){OGO_MLA_CSV::csv_row($stream,array($r['domain'],$r['materials'],$r['urls'],$r['links']));$after=$r['domain'];}
            }while(count($rows)===500);
        } else {
            OGO_MLA_CSV::csv_row($stream,array('post_id','post_title','post_status','post_url','edit_url','list_group','field','category','domain','original_href','resolved_url','anchor','links_in_material','note'));
            $after=0;
            do {
                $rows=self::details($run,$kind,$domain,500,0,$after);
                foreach($rows as $r) {
                    $edit=admin_url('post.php?post='.(int)$r['post_id'].'&action=edit');
                    OGO_MLA_CSV::csv_row($stream,array($r['post_id'],$r['post_title'],$r['post_status'],$r['post_url'],$edit,$r['list_group'],'post_content',$labels[$r['kind']],$r['domain'],$r['raw_url'],$r['target_url'],$r['anchor_text'],$r['link_count'],$r['issue']));
                    $after=(int)$r['id'];
                }
            }while(count($rows)===500);
        }
        fclose($stream);$wpdb->query('COMMIT');exit;
    }
    public static function page() {
        if(!current_user_can('edit_others_posts'))return;
        if(get_option('ogo_mla_schema')!==self::SCHEMA){echo '<div class="notice notice-error"><p>Не вдалося створити таблицю ревізії. Перевірте права бази на створення таблиць InnoDB.</p></div>';return;}
        $current=self::state();$run=sanitize_text_field(wp_unslash($_GET['run']??($current['run']??'')));if(!self::valid_run($run))$run='';
        $s=$run!==''?self::state($run):array();list($kind,$domain)=self::filters($_GET);$page=max(1,absint($_GET['paged']??1));
        $view=($_GET['view']??'')==='details'?'details':'domains';if($domain!=='')$view='details';
        $rows=array();$stats=array('totals'=>array('urls'=>0,'materials'=>0,'links'=>0,'checked'=>0),'kinds'=>array());
        try{if($s){$stats=self::stats($run);$rows=$view==='domains'?self::domains($run,$kind,30,($page-1)*30):self::details($run,$kind,$domain,50,($page-1)*50);}}
        catch(Throwable $e){echo '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div>';return;}
        wp_enqueue_script('ogo-mla',plugins_url('assets/scan.js',dirname(__DIR__).'/ogo-miralinks-audit.php'),array(),self::VERSION,true);
        wp_localize_script('ogo-mla','ogoMLAudit',array('url'=>admin_url('admin-ajax.php'),'action'=>'ogo_mla','nonce'=>wp_create_nonce('ogo_mla'),'run'=>$current['run']??'','reportUrl'=>self::url()));
        $labels=self::kinds();
        ?>
        <div class="wrap"><h1>OGO Miralinks Audit — ревізія зовнішніх посилань</h1>
        <p><strong>Версія <?php echo esc_html(self::VERSION); ?>. Лише звіт: текст статей, метаполя anons і дати не змінюються.</strong></p>
        <p>Поточний список: 2 215 унікальних ID ОГО лише з 31 видимого місячного аркуша таблиці. Усі приховані аркуші повністю виключені. Зелені записи виключені; повтори об’єднані. Натисніть «Нова ревізія», щоб почати перевірку.</p>
        <p>Перевіряє лише a[href] у post_content цих статей. Поле anons не читає; інші статті, меню, віджети й метаполя не сканує. Підтримує http, https і //. Внутрішні адреси ogo.ua/ogo.rv.ua та їхніх піддоменів пропускає. my.rv.ua/volyninfo.com та їхні піддомени показує як пов’язані редакційні сайти. Зовнішнє посилання не оголошується рекламою автоматично. HTTP-перевірок немає.</p>
        <p>Порожні результати, відсутні ID, кошик та інші типи записів мають окремий статус. Коментарі, код, скрипти та голі URL пропускаються. Наявність запису за ID ще не підтверджує змістову відповідність статті таблиці: заголовок і адресу слід звірити у звіті.</p>
        <p><button class="button button-primary" id="ogo-audit-start">Нова ревізія</button> <button class="button" id="ogo-audit-scan" <?php disabled(empty($current['run'])||!empty($current['complete']));?>>Продовжити поточну ревізію</button> <button class="button" id="ogo-audit-stop">Зупинити після поточної порції</button></p>
        <p id="ogo-audit-progress" role="status" aria-live="polite">Перевірено ID: <?php echo (int)($current['scanned']??0); ?> із <?php echo count(OGO_MLA_List::ids()); ?>. <?php echo !empty($current['complete'])?'Завершено.':'Можна продовжити сканування.';?></p>
        <form method="get"><input type="hidden" name="page" value="ogo-miralinks-audit"><label>Запуск <select name="run"><?php foreach(get_option('ogo_mla_history',array()) as $id)if(self::valid_run($id)){$meta=self::state($id);echo '<option value="'.esc_attr($id).'" '.selected($run,$id,false).'>'.esc_html(($meta['started']??$id).' — '.(!empty($meta['complete'])?'завершено':'частково')).'</option>';}?></select></label> <label>Категорія <select name="kind"><option value="">Усі</option><?php foreach($labels as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($kind,$key,false).'>'.esc_html($label).'</option>';?></select></label> <label>Звіт <select name="view"><option value="domains" <?php selected($view,'domains');?>>За доменами</option><option value="details" <?php selected($view,'details');?>>Статті та анкори</option></select></label> <button class="button">Показати</button></form>
        <?php if($s): ?>
        <?php if(($s['manifest']??'')!==OGO_MLA_List::digest())echo '<div class="notice notice-warning"><p>Історичний звіт за попереднім списком: він міг включати приховані аркуші. Його CSV-експорт заблоковано. Натисніть «Нова ревізія», щоб перевірити лише видимі аркуші.</p></div>'; ?>
        <p><strong><?php echo !empty($s['complete'])?'Завершений звіт':'Частковий звіт — сканування не завершене'; ?>.</strong> Перевірено ID: <?php echo (int)$s['scanned']; ?>. Адрес: <?php echo (int)$stats['totals']['urls']; ?>; посилань: <?php echo (int)$stats['totals']['links']; ?>; матеріалів із посиланнями: <?php echo (int)$stats['totals']['materials']; ?>. Дані на момент сканування; це список для оцінки, а не підтверджена реклама.</p>
        <?php foreach($stats['kinds'] as $row)echo '<p>'.esc_html($labels[$row['kind']].': '.$row['materials'].' матеріалів; '.$row['links'].' посилань.').'</p>'; ?>
        <?php if(($s['manifest']??'')===OGO_MLA_List::digest()): ?><p><a class="button" href="<?php echo esc_url(self::export_url($run,'domains',$kind,''));?>">CSV — домени</a> <a class="button" href="<?php echo esc_url(self::export_url($run,'details',$kind,$domain));?>">CSV — статті, адреси й анкори</a></p><?php endif;?>
        <?php if($view==='domains'): ?>
        <table class="widefat striped"><thead><tr><th>Домен</th><th>Статей</th><th>Адрес</th><th>Посилань</th></tr></thead><tbody>
        <?php foreach($rows as $r):?><tr><td><a href="<?php echo esc_url(self::url(array('run'=>$run,'kind'=>$r['domain']===''?'review':$kind,'domain'=>$r['domain'],'view'=>'details')));?>"><?php echo esc_html($r['domain']?:'[пошкоджена адреса]');?></a></td><td><?php echo (int)$r['materials'];?></td><td><?php echo (int)$r['urls'];?></td><td><?php echo (int)$r['links'];?></td></tr><?php endforeach;?>
        </tbody></table>
        <?php else: ?>
        <p><a href="<?php echo esc_url(self::url(array('run'=>$run,'kind'=>$kind)));?>">← До доменів</a></p>
        <table class="widefat striped"><thead><tr><th>Стаття</th><th>Категорія</th><th>Адреса / анкор</th><th>Кількість</th></tr></thead><tbody>
        <?php foreach($rows as $r):?><tr><td>ID <?php echo (int)$r['post_id'];?><br><a href="<?php echo esc_url(admin_url('post.php?post='.(int)$r['post_id'].'&action=edit'));?>"><?php echo esc_html($r['post_title']?:'[без назви / запису немає]');?></a><br><?php echo esc_html($r['list_group']);?></td><td><?php echo esc_html($labels[$r['kind']]);?><br><?php echo esc_html($r['post_status']);?></td><td style="overflow-wrap:anywhere"><code><?php echo esc_html($r['raw_url']);?></code><br><?php echo esc_html($r['anchor_text']);?><br><?php echo esc_html($r['issue']);?></td><td><?php echo (int)$r['link_count'];?></td></tr><?php endforeach;?>
        </tbody></table>
        <?php endif;?>
        <?php if(!$rows)echo '<p>У вибраному розділі рядків немає. Статуси без посилань переглядайте у звіті «Статті та анкори».</p>';?>
        <p><?php $args=array('run'=>$run,'kind'=>$kind,'domain'=>$domain,'view'=>$view);if($page>1)echo '<a class="button" href="'.esc_url(self::url(array_merge($args,array('paged'=>$page-1)))).'">Назад</a> ';if(count($rows)===($view==='domains'?30:50))echo '<a class="button" href="'.esc_url(self::url(array_merge($args,array('paged'=>$page+1)))).'">Далі</a>';?></p>
        <?php else:?><p>Натисніть «Нова ревізія». Після завершення завантажте CSV зі статтями, адресами й анкорами для визначення рекламних посилань.</p><?php endif;?>
        </div>
        <?php
    }
}
