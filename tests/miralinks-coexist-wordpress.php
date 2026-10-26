<?php
$root=getenv('OGO_TEST_WP_ROOT');
if(!$root || !is_file($root.'/wp-load.php'))exit(2);
require $root.'/wp-load.php';
if(DB_NAME!=='ogo_audit_test')throw new RuntimeException('Dedicated test database required');
require_once ABSPATH.'wp-admin/includes/plugin.php';
$old=get_option('ogo_mc_external_scan');$cleanup=get_option('ogo_mc_scan');$new=get_option('ogo_mla_scan');
foreach(array('ogo-miralinks-audit/ogo-miralinks-audit.php','ogo-media-cleaner/ogo-media-cleaner.php') as $plugin) {
    $r=activate_plugin($plugin);if(is_wp_error($r))throw new RuntimeException($r->get_error_message());
}
if(!class_exists('OGO_MLA_Report') || !class_exists('OGO_MC_External'))throw new RuntimeException('Both plugins must load without conflicts');
if(!has_action('wp_ajax_ogo_mla') || !has_action('wp_ajax_ogo_mc_external'))throw new RuntimeException('Independent AJAX endpoints required');
$a=new ReflectionMethod('OGO_MLA_Report','table');$b=new ReflectionMethod('OGO_MC_External','table');
$a->setAccessible(true);$b->setAccessible(true);
if($a->invoke(null)===$b->invoke(null))throw new RuntimeException('Independent report tables required');
if(get_option('ogo_mc_external_scan')!==$old || get_option('ogo_mc_scan')!==$cleanup || get_option('ogo_mla_scan')!==$new)throw new RuntimeException('Existing report and cleanup states must be preserved');
echo "Passed simultaneous activation, class/AJAX/table separation and existing-state preservation checks.\n";
