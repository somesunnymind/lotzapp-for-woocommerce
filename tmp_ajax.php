<?php
define('DOING_AJAX', true);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_SCHEME'] = 'http';
require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'wp-load.php';
$_REQUEST['action'] = 'lotzwoo_menu_plan_list';
$_REQUEST['nonce'] = wp_create_nonce('lotzwoo_menu_planning');
ob_start();
do_action('wp_ajax_lotzwoo_menu_plan_list');
$out = ob_get_clean();
var_dump($out);
?>
