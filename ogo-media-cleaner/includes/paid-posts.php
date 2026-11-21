<?php
if (!defined('ABSPATH')) exit;

/** Private register of paid articles, independent of the public disclosure. */
final class OGO_MC_Paid_Posts {
    private const OPTION='ogo_mc_paid_articles';
    private const ACTION='ogo_mc_import_paid';
    private const FIELD='paid_article';

    public static function init() {
        add_action('admin_post_'.self::ACTION,array(__CLASS__,'upload'));
    }

    public static function records() { return get_option(self::OPTION,array()); }
    public static function revision() { return hash('sha256',serialize(self::records())); }

    private static function path($url) {
        $parts=wp_parse_url(trim($url));
        if (!is_array($parts) || !in_array(strtolower($parts['scheme']??''),array('http','https'),true)
            || !in_array(strtolower($parts['host']??''),array('ogo.ua','www.ogo.ua'),true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query'])) return '';
        $path=trim($parts['path']??'','/');
        return $path!=='' && !preg_match('~[\x00-\x20<>\\\\]~',$path)?'/'.$path:'';
    }

    public static function read_csv($stream) {
        $header=fgetcsv($stream,0,',','"','');
        if (!$header) throw new RuntimeException('CSV порожній.');
        $header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);
        $column=array_search('Публікація',array_map('trim',$header),true);
        if ($column===false) throw new RuntimeException('У CSV потрібна колонка «Публікація».');
        $paths=array();
        while (($row=fgetcsv($stream,0,',','"',''))!==false) {
            if ($row===array(null)) continue;
            $path=self::path($row[$column]??'');
            if ($path==='') throw new RuntimeException('Знайдено рядок без коректної адреси публікації OGO.ua.');
            $paths[$path]=true;
        }
        if (!$paths) throw new RuntimeException('У CSV немає публікацій.');
        return array_keys($paths);
    }

    public static function resolve($path) {
        // The legacy route carries the original ID. Check its date or stored original URL.
        if (preg_match('~^/articles/view/(\d{4}-\d{2}-\d{2})/(\d+)\.html$~D',$path,$parts)) {
            $post=get_post((int)$parts[2]);
            if (!$post || !in_array($post->post_type,array('post','press'),true)) return 0;
            return substr($post->post_date,0,10)===$parts[1]
                || self::path((string)get_post_meta($post->ID,'old_url',true))===$path?(int)$post->ID:0;
        }
        $id=url_to_postid(home_url($path.'/'));
        $post=$id?get_post($id):null;
        return $post && in_array($post->post_type,array('post','press'),true)?(int)$id:0;
    }

    public static function resolve_paths($paths) {
        global $wpdb;
        $records=array_fill_keys($paths,0);
        $candidates=array();
        foreach ($paths as $path) {
            // The site's current publication routes are /slug/ and /press/slug/.
            if (!preg_match('~^/(press/)?([^/]+)$~D',$path,$parts)) {
                $records[$path]=self::resolve($path);
                continue;
            }
            $type=$parts[1]===''?'post':'press';
            $slug=sanitize_title(rawurldecode($parts[2]));
            if ($slug!=='' && rawurldecode($slug)===rawurldecode($parts[2])) $candidates[$slug][$type][]=$path;
        }
        foreach (array_chunk(array_keys($candidates),200) as $batch) {
            $slots=implode(',',array_fill(0,count($batch),'%s'));
            $posts=$wpdb->get_results($wpdb->prepare(
                "SELECT ID,post_name,post_type FROM {$wpdb->posts} WHERE post_name IN ($slots) AND post_type IN ('post','press') AND post_status='publish'",$batch
            ));
            if ($wpdb->last_error) throw new RuntimeException('Не вдалося зіставити адреси публікацій.');
            $matched=array();
            foreach ($posts as $post) $matched[$post->post_name][$post->post_type][]=(int)$post->ID;
            foreach ($matched as $slug=>$types) {
                foreach ($types as $type=>$ids) {
                    if (count($ids)!==1) continue;
                    foreach ($candidates[$slug][$type]??array() as $path) $records[$path]=$ids[0];
                }
            }
        }
        return $records;
    }

    public static function paid_ids($ids) {
        global $wpdb;
        $marked=array_intersect(array_filter(self::records()),$ids);
        if ($ids) {
            $posts=implode(',',array_map('absint',$ids));
            $field=$wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id IN ($posts) AND meta_key=%s AND meta_value='1'",self::FIELD
            ));
            if ($wpdb->last_error) throw new RuntimeException('Не вдалося перевірити позначки платних статей.');
            $marked=array_merge($marked,$field);
        }
        return array_fill_keys($marked,true);
    }

    public static function protects($project) {
        return $project==='miralinks_unwrap' || isset(OGO_MC_Engine::projects()[$project]['link_action']);
    }

    private static function url($args=array()) {
        return add_query_arg(array_merge(array('page'=>'ogo-media-cleaner','project'=>'paid_posts'),$args),admin_url('tools.php'));
    }

    public static function upload() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.','',array('response'=>403));
        check_admin_referer(self::ACTION);
        $file=$_FILES['paid_csv']??array();
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) wp_die('Не вдалося отримати CSV.');
        $lock=array('time'=>time(),'token'=>wp_generate_uuid4());
        if (!add_option('ogo_mc_lock',$lock,'','no')) wp_die('Інша операція вже виконується. Повторіть згодом.');
        register_shutdown_function(function() use ($lock){ if (get_option('ogo_mc_lock')===$lock) delete_option('ogo_mc_lock'); });
        $stream=null;
        try {
            $stream=fopen($file['tmp_name'],'r');
            if (!$stream) throw new RuntimeException('Не вдалося прочитати CSV.');
            $records=self::records();
            $paths=array_diff(self::read_csv($stream),array_keys(array_filter($records)));
            $records=array_replace($records,self::resolve_paths($paths));
            if (!update_option(self::OPTION,$records,false) && self::records()!==$records) {
                throw new RuntimeException('Не вдалося зберегти список платних статей.');
            }
        } catch (RuntimeException $error) {
            $failure=$error->getMessage();
        } finally {
            if ($stream) fclose($stream);
            if (get_option('ogo_mc_lock')===$lock) delete_option('ogo_mc_lock');
        }
        if (isset($failure)) wp_die(esc_html($failure));
        wp_safe_redirect(self::url());
        exit;
    }

    public static function report() {
        global $wpdb;
        $records=self::records();
        $field=$wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value='1'",self::FIELD
        ));
        if ($wpdb->last_error) throw new RuntimeException('Не вдалося прочитати платні статті.');
        $ids=array_unique(array_map('absint',array_merge(array_filter($records),$field)));
        rsort($ids,SORT_NUMERIC);
        $report=array('total'=>0,'missing'=>array(),'unresolved'=>array_keys(array_filter($records,function($id){return !$id;})));
        foreach (array_chunk($ids,200) as $batch) {
            $list=implode(',',$batch);
            $posts=$wpdb->get_results("SELECT ID,post_title,post_type,post_content FROM {$wpdb->posts} WHERE ID IN ($list) AND post_status='publish' AND post_type IN ('post','press') ORDER BY ID DESC");
            if ($wpdb->last_error) throw new RuntimeException('Не вдалося прочитати матеріали.');
            foreach ($posts as $post) {
                ++$report['total'];
                if (!OGO_MC_Site_Links_Engine::ad_marker($post->post_content,true)) {
                    $report['missing'][]=array('id'=>(int)$post->ID,'title'=>$post->post_title,'type'=>$post->post_type);
                }
            }
        }
        return $report;
    }

    public static function page() {
        if (!current_user_can('manage_options')) { echo '<p>Розділ доступний адміністратору сайту.</p>'; return; }
        $page=max(1,absint($_GET['paged']??1));
        try { $report=self::report(); }
        catch (RuntimeException $error) { echo '<p>'.esc_html($error->getMessage()).'</p>'; return; }
        ?>
        <div class="wrap"><h1>OGO Cleaner — Платні статті</h1>
        <?php OGO_Media_Cleaner::navigation('paid_posts'); ?>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
        <?php wp_nonce_field(self::ACTION); ?>
        <label>Список публікацій <input type="file" name="paid_csv" accept=".csv,text/csv" required></label>
        <button class="button" type="submit">Імпортувати CSV</button>
        </form>
        <p>Посилання статей зі списку й матеріалів із полем «Спеціально для OGO.ua» позначаються як «Платні» під час нової ревізії.</p>
        <p>Платних опублікованих матеріалів: <?php echo (int)$report['total']; ?>.
        Без видимої позначки в тексті: <?php echo count($report['missing']); ?>.
        Незіставлених адрес: <?php echo count($report['unresolved']); ?>.</p>
        <?php if ($report['unresolved']): ?>
        <details><summary>Адреси для ручного зіставлення</summary><ul>
        <?php foreach ($report['unresolved'] as $path) echo '<li>'.esc_html($path).'</li>'; ?>
        </ul></details>
        <?php endif; ?>
        <h2>Статті без позначки «На правах реклами» в тексті</h2>
        <table class="widefat striped"><thead><tr><th>Матеріал</th><th>Тип</th></tr></thead><tbody>
        <?php foreach (array_slice($report['missing'],($page-1)*40,40) as $row):
            $edit=get_edit_post_link($row['id']);
            $title=$row['title']?:'Стаття '.$row['id']; ?>
        <tr><td><?php echo $edit?'<a href="'.esc_url($edit).'">'.esc_html($title).'</a>':esc_html($title); ?></td>
        <td><?php echo $row['type']==='press'?'Пресреліз':'Стаття'; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <p><?php if ($page>1) echo '<a class="button" href="'.esc_url(self::url(array('paged'=>$page-1))).'">Назад</a> ';
        if ($page*40<count($report['missing'])) echo '<a class="button" href="'.esc_url(self::url(array('paged'=>$page+1))).'">Далі</a>'; ?></p></div>
        <?php
    }
}
