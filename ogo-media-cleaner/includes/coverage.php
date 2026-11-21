<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/coverage-engine.php';

final class OGO_MC_Coverage {
    public static function init() {
        add_action('wp_ajax_ogo_mc_404',function(){ OGO_MC_Audit::ajax(true); });
        add_action('admin_post_ogo_mc_404_export',array(__CLASS__,'export'));
    }

    public static function upload($upload) {
        if (($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']??'')) {
            throw new RuntimeException('Не вдалося завантажити файл. Виберіть ZIP або CSV із Search Console.');
        }
        return self::import_file($upload['tmp_name'],$upload['name']);
    }

    public static function import_file($file,$name) {
        $max=OGO_MC_Coverage_Engine::MAX_BYTES;
        if (filesize($file)>$max) throw new RuntimeException('Файл має бути до 2 МБ.');
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $targets=null;
        if ($extension==='csv') {
            $stream=fopen($file,'rb');
            if (!$stream) throw new RuntimeException('Не вдалося прочитати CSV.');
            try { $targets=OGO_MC_Coverage_Engine::read_csv($stream); }
            finally { fclose($stream); }
        } elseif ($extension==='zip') {
            if (!class_exists('ZipArchive')) throw new RuntimeException('На сервері немає підтримки ZIP. Розпакуйте архів і завантажте CSV зі стовпцем URL.');
            $zip=new ZipArchive();
            if ($zip->open($file,ZipArchive::RDONLY)!==true) throw new RuntimeException('Не вдалося відкрити ZIP.');
            try {
                if ($zip->numFiles>20) throw new RuntimeException('У ZIP забагато файлів. Завантажте CSV зі стовпцем URL.');
                for ($index=0;$index<$zip->numFiles;++$index) {
                    $entry=$zip->statIndex($index);
                    if (!$entry || strtolower(pathinfo($entry['name'],PATHINFO_EXTENSION))!=='csv') continue;
                    if ($entry['size']>$max) throw new RuntimeException('Один із CSV в архіві перевищує 2 МБ.');
                    $data=$zip->getFromIndex($index,$max+1);
                    if ($data===false || strlen($data)>$max) throw new RuntimeException('Не вдалося прочитати CSV з архіву.');
                    $stream=fopen('php://memory','w+b');
                    try {
                        fwrite($stream,$data); rewind($stream);
                        $rows=OGO_MC_Coverage_Engine::read_csv($stream);
                    } finally { fclose($stream); }
                    if ($rows===null) continue;
                    if ($targets!==null) throw new RuntimeException('У ZIP кілька CSV зі стовпцем URL. Виберіть потрібний CSV окремо.');
                    $targets=$rows;
                }
            } finally { $zip->close(); }
        }
        if (!$targets || !array_filter(array_column($targets,'key'))) throw new RuntimeException('У файлі немає адрес ogo.ua у стовпці URL.');
        return $targets;
    }

    private static function url($args=array()) {
        return add_query_arg(array_merge(array('page'=>'ogo-media-cleaner','project'=>'coverage_404'),$args),admin_url('tools.php'));
    }

    private static function report_state($run) {
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$run)) return array();
        return OGO_MC_Audit::state($run,true);
    }

    public static function targets($run) {
        $targets=get_option('ogo_mc_404_urls_'.$run,array());
        if (!$targets) throw new RuntimeException('Список імпортованих адрес цього запуску недоступний.');
        return $targets;
    }

    private static function report_rows($state) {
        $targets=self::targets($state['run']);
        $groups=OGO_MC_Audit::groups($state['run'],'',count($targets));
        $index=array_column($groups,null,'group_key');
        $rows=array();
        foreach ($targets as $target) {
            $group=$index[$target['key']]??null;
            $target['materials']=(int)($group['materials']??0);
            $target['links']=(int)($group['links']??0);
            $target['status']=$target['key']===''?'invalid':($group?'found':'missing');
            $rows[]=$target;
        }
        return $rows;
    }

    private static function label($status,$complete) {
        if ($status==='found') return 'Знайдено у матеріалах';
        if ($status==='invalid') return 'Адреса поза областю або пошкоджена';
        return $complete?'У матеріалах не знайдено':'Поки не знайдено';
    }

    public static function export() {
        if (!current_user_can('edit_others_posts')) wp_die('Недостатньо прав.','',array('response'=>403));
        $run=sanitize_text_field(wp_unslash($_GET['run']??''));
        check_admin_referer('ogo_mc_404_export_'.$run);
        $state=self::report_state($run);
        if (!$state || !OGO_MC_Audit::ready()) wp_die('Звіт недоступний.');
        $rows=self::report_rows($state);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="ogo-404-'.$run.'.csv"');
        $stream=fopen('php://output','w'); fwrite($stream,"\xEF\xBB\xBF");
        OGO_MC_Audit_Engine::csv_row($stream,array('url','google_last_crawl','status','materials','links','scan_complete','sources_report'));
        foreach ($rows as $row) {
            $sources=$row['materials']?self::url(array('run'=>$run,'group'=>$row['key'])):'';
            OGO_MC_Audit_Engine::csv_row($stream,array($row['raw'],$row['date'],self::label($row['status'],$state['complete']),$row['materials'],$row['links'],(int)$state['complete'],$sources));
        }
        fclose($stream);
        exit;
    }

    public static function page() {
        if (!current_user_can('edit_others_posts')) return;
        if (!OGO_MC_Audit::ready()) { echo '<div class="notice notice-error"><p>Таблиця ревізії недоступна.</p></div>'; return; }
        $current=OGO_MC_Audit::state('',true);
        $run=sanitize_text_field(wp_unslash($_GET['run']??($current['run']??'')));
        $state=self::report_state($run);
        $group=sanitize_key(wp_unslash($_GET['group']??''));
        if (!preg_match('/^[a-f0-9]{64}$/D',$group)) $group='';
        $filter=sanitize_key(wp_unslash($_GET['result']??''));
        $page=max(1,absint($_GET['paged']??1));
        $limit=40; $rows=array(); $details=array(); $target=null; $more=false;
        try {
            if ($state) {
                $rows=self::report_rows($state);
                if ($group!=='') {
                    foreach ($rows as $row) if ($row['key']===$group) { $target=$row; break; }
                    if ($target) $details=OGO_MC_Audit::details($run,'coverage_404',$group,$limit+1,($page-1)*$limit);
                    $more=count($details)>$limit;
                }
            }
        } catch (Throwable $e) { echo '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div>'; return; }
        wp_enqueue_script('ogo-404',plugins_url('assets/audit.js',dirname(__DIR__).'/ogo-media-cleaner.php'),array(),OGO_Media_Cleaner::VERSION,true);
        wp_localize_script('ogo-404','ogoAudit',array('action'=>'ogo_mc_404','nonce'=>wp_create_nonce('ogo_mc_404'),'url'=>admin_url('admin-ajax.php'),'run'=>$current['run']??'','reportUrl'=>self::url()));
        $counts=array_count_values(array_column($rows,'status'));
        $filters=array(''=>'Усі адреси','found'=>'Знайдено у матеріалах','missing'=>'Без збігів','invalid'=>'Поза областю або пошкоджені');
        ?>
        <div class="wrap">
        <h1>OGO Cleaner — Не знайдено (404)</h1>
        <?php OGO_Media_Cleaner::navigation('coverage_404'); ?>
        <p>Завантажте ZIP із Search Console або CSV зі стовпцем URL. Ревізія шукає посилання в основному тексті опублікованих матеріалів WordPress. Дата в таблиці — останнє сканування Google.</p>
        <p>Звіт охоплює <code>post_content</code>. Меню, метаполя, шаблони, зовнішні сайти й поточний HTTP-статус залишаються поза цією перевіркою.</p>
        <p><label>Експорт Search Console <input type="file" id="ogo-audit-upload" accept=".zip,.csv"></label>
        <button class="button button-primary" id="ogo-audit-start">Почати ревізію</button>
        <button class="button" id="ogo-audit-scan" <?php disabled(empty($current['run']) || !empty($current['complete'])); ?>>Продовжити поточну ревізію</button>
        <button class="button" id="ogo-audit-stop">Зупинити після поточної порції</button></p>
        <p id="ogo-audit-progress" role="status" aria-live="polite"></p>
        <?php if ($state): ?>
        <form method="get">
        <input type="hidden" name="page" value="ogo-media-cleaner"><input type="hidden" name="project" value="coverage_404">
        <label>Запуск <select name="run"><?php foreach (get_option('ogo_mc_404_history',array()) as $id) {
            $report=self::report_state($id);
            if ($report) echo '<option value="'.esc_attr($id).'" '.selected($run,$id,false).'>'.esc_html($report['started'].' — '.($report['complete']?'завершено':'частково')).'</option>';
        } ?></select></label>
        <label>Результат <select name="result"><?php foreach ($filters as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected($filter,$key,false).'>'.esc_html($label).'</option>'; ?></select></label>
        <button class="button">Показати</button>
        </form>
        <p><?php echo $state['complete']?'Ревізію завершено.':'Частковий звіт. Сканування ще триває.'; ?> Перевірено матеріалів: <?php echo (int)$state['scanned']; ?>. Адрес у файлі: <?php echo count($rows); ?>. Зі збігами: <?php echo (int)($counts['found']??0); ?>.</p>
        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action'=>'ogo_mc_404_export','run'=>$run),admin_url('admin-post.php')),'ogo_mc_404_export_'.$run)); ?>">Завантажити CSV</a></p>
        <?php if ($group!==''): ?>
        <p><a href="<?php echo esc_url(self::url(array('run'=>$run,'result'=>$filter))); ?>">← До списку адрес</a></p>
        <?php if ($target): ?><h2 style="overflow-wrap:anywhere"><?php echo esc_html($target['raw']); ?></h2><?php endif; ?>
        <table class="widefat striped"><thead><tr><th>Матеріал</th><th>Оригінальний href</th><th>Входжень</th></tr></thead><tbody>
        <?php foreach (array_slice($details,0,$limit) as $detail):
            $id=(int)$detail['post_id']; $edit=get_edit_post_link($id); ?>
        <tr><td><?php if ($edit): ?><a href="<?php echo esc_url($edit); ?>"><?php endif; ?><?php echo esc_html(get_the_title($id)?:'Матеріал '.$id); ?><?php if ($edit): ?></a><?php endif; ?></td>
        <td style="overflow-wrap:anywhere"><code><?php echo esc_html($detail['raw_url']); ?></code></td><td><?php echo (int)$detail['link_count']; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php else:
            if (isset($filters[$filter]) && $filter!=='') $rows=array_values(array_filter($rows,function($row) use ($filter){return $row['status']===$filter;}));
            usort($rows,function($a,$b){ return $b['materials']<=>$a['materials'] ?: strcmp($b['date'],$a['date']); });
            $more=count($rows)>$page*$limit;
        ?>
        <table class="widefat striped"><thead><tr><th>URL із Search Console</th><th>Сканування Google</th><th>Результат</th><th>Матеріалів</th><th>Посилань</th></tr></thead><tbody>
        <?php foreach (array_slice($rows,($page-1)*$limit,$limit) as $row): ?>
        <tr><td style="overflow-wrap:anywhere"><?php if ($row['url']!==''): ?><a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener noreferrer"><?php endif; ?><?php echo esc_html($row['raw']); ?><?php if ($row['url']!==''): ?></a><?php endif; ?></td>
        <td><?php echo esc_html($row['date']); ?></td><td><?php echo esc_html(self::label($row['status'],$state['complete'])); ?></td>
        <td><?php if ($row['materials']): ?><a href="<?php echo esc_url(self::url(array('run'=>$run,'group'=>$row['key'],'result'=>$filter))); ?>"><?php echo (int)$row['materials']; ?> · Джерела</a><?php else: ?>0<?php endif; ?></td>
        <td><?php echo (int)$row['links']; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <p><?php
        $args=array('run'=>$run,'group'=>$group,'result'=>$filter);
        if ($page>1) echo '<a class="button" href="'.esc_url(self::url($args+array('paged'=>$page-1))).'">Назад</a> ';
        if ($more) echo '<a class="button" href="'.esc_url(self::url($args+array('paged'=>$page+1))).'">Далі</a>';
        ?></p>
        <?php endif; ?>
        </div>
        <?php
    }
}
