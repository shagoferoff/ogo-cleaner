<?php
/**
 * Plugin Name: OGO Miralinks Audit
 * Description: Ревізія зовнішніх посилань у текстах 2215 статей ОГО з видимих аркушів таблиці. Звіт і CSV; без зміни статей та anons.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ОГО
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/includes/report.php';
register_activation_hook(__FILE__,array('OGO_MLA_Report','activate'));
OGO_MLA_Report::init();
add_action('admin_menu',function(){add_management_page('OGO Miralinks Audit','OGO Miralinks Audit','edit_others_posts','ogo-miralinks-audit',array('OGO_MLA_Report','page'));});
