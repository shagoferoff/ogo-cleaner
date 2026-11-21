<?php
if (!defined('ABSPATH')) exit;

/** Existing private flags are used only to protect content during cleanup. */
final class OGO_MC_Paid_Posts {
    private const OPTION='ogo_mc_paid_articles';
    private const FIELD='paid_article';

    public static function paid_ids($ids) {
        global $wpdb;
        $marked=array_intersect(array_filter(get_option(self::OPTION,array())),$ids);
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
}
