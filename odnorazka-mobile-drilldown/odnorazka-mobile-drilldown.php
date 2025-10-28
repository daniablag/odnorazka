<?php
/**
 * Plugin Name: Odnorazka Mobile Drilldown
 * Description: Мобильное меню-дрилдаун для Astra (выделено из дочерней темы).
 * Version: 1.0.0
 * Author: Odnorazka Kiev
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ODMOBMENU_ACTIVE', true );
define( 'ODMOBMENU_VERSION', '1.0.0' );

add_action( 'wp_enqueue_scripts', function(){
    if ( is_admin() ) return;

    $base_url  = plugin_dir_url( __FILE__ );
    $base_path = plugin_dir_path( __FILE__ );

    // JS
    $js_rel  = 'assets/js/dd-ast-mobile-drilldown.js';
    $js_path = $base_path . $js_rel;
    $js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : ODMOBMENU_VERSION;
    wp_enqueue_script(
        'dd-ast-mobile-drilldown',
        $base_url . $js_rel,
        [],
        $js_ver,
        true
    );

    // CSS
    $css_rel  = 'assets/css/mobile-drilldown.css';
    $css_path = $base_path . $css_rel;
    $css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : ODMOBMENU_VERSION;
    wp_enqueue_style(
        'dd-ast-mobile-drilldown',
        $base_url . $css_rel,
        [],
        $css_ver,
        'all'
    );
}, 20 );


