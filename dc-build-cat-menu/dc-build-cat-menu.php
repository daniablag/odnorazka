<?php
/**
 * Plugin Name: DC Build Cat Menu
 * Description: Строит меню WordPress из иерархии WooCommerce категорий (product_cat). Умеет добавлять корневой пункт и сортировать по meta:order. Есть режим пересборки.
 * Author: DC Web Studio
 * Version: 1.2.0
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('DC_Build_Cat_Menu') ) {

class DC_Build_Cat_Menu {

    public static function build($args = []) {
        $defaults = [
            'menu_name'       => 'Главное меню',   // В какое меню добавляем
            'taxonomy'        => 'product_cat',
            'max_depth'       => 10,
            'skip_empty'      => false,
            // Сортировка: name | slug | id | meta:order (или meta:<любой_ключ>)
            'order_by'        => 'name',
            'order'           => 'ASC',            // ASC | DESC
            'verbose'         => false,
            'rebuild'         => false,            // удалить пункты категорий и пересобрать

            // Корневой пункт меню (опционально один из вариантов)
            'root_page_title' => null,             // создать/взять страницу и добавить её в меню
            'root_url'        => null,             // вместо страницы — кастомная ссылка
            'root_item_title' => null,             // подпись для root_url (если пусто — возьмём из root_page_title)
        ];
        $args = array_merge($defaults, $args);

        $tax = $args['taxonomy'];

        // Загружаем все термины таксономии
        $terms = get_terms([
            'taxonomy'   => $tax,
            'hide_empty' => $args['skip_empty'],
            'number'     => 0,
        ]);
        if ( is_wp_error($terms) ) {
            self::log("ERR: ".$terms->get_error_message(), true);
            return;
        }

        // Построим дерево parent -> [children]
        $children = []; $roots = [];
        foreach ($terms as $t) {
            $p = (int)$t->parent;
            if ($p === 0) $roots[] = $t;
            if (!isset($children[$p])) $children[$p] = [];
            $children[$p][] = $t;
        }

        // Компаратор с поддержкой meta:<key>
        $cmp = function($a, $b) use ($args) {
            $ob   = $args['order_by'];
            $desc = ($args['order'] === 'DESC');

            if (preg_match('~^meta:(.+)$~', $ob, $m)) {
                $meta_key = $m[1];
                $av = (int) get_term_meta($a->term_id, $meta_key, true);
                $bv = (int) get_term_meta($b->term_id, $meta_key, true);
                if ($av !== $bv) return $desc ? ($bv <=> $av) : ($av <=> $bv);
                // стабильный фолбэк по имени
                $fallback = strnatcasecmp($a->name, $b->name);
                return $desc ? -$fallback : $fallback;
            }

            if ($ob === 'id') {
                $res = ($a->term_id <=> $b->term_id);
                return $desc ? -$res : $res;
            }

            $av = ($ob === 'slug') ? $a->slug : $a->name;
            $bv = ($ob === 'slug') ? $b->slug : $b->name;
            $res = strnatcasecmp((string)$av, (string)$bv);
            return $desc ? -$res : $res;
        };

        // Отсортируем детей каждого родителя и корни
        foreach ($children as &$arr) usort($arr, $cmp);
        unset($arr);
        usort($roots, $cmp);

        // Получим/создадим меню
        $menu = wp_get_nav_menu_object($args['menu_name']);
        if ( ! $menu ) {
            $menu_id = wp_create_nav_menu($args['menu_name']);
            $menu = wp_get_nav_menu_object($menu_id);
            self::log("Создано меню: {$args['menu_name']} (ID={$menu_id})", $args['verbose']);
        }
        $menu_id = (int) $menu->term_id;

        // Все пункты меню — чтобы не дублировать
        $existing_items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache'=>false]) ?: [];

        // (Опционально) пересобрать: удалить все пункты таксономии из этого меню
        if ($args['rebuild']) {
            $deleted = 0;
            foreach ($existing_items as $item) {
                if ($item->type === 'taxonomy' && $item->object === $tax) {
                    wp_delete_post((int)$item->ID, true);
                    $deleted++;
                }
            }
            self::log("Пересборка: удалено пунктов таксономии {$tax}: {$deleted}", true);
            $existing_items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache'=>false]) ?: [];
        }

        // Индексы существующих пунктов
        $by_term_object = []; // term_id -> menu_item_id (для taxonomy)
        $page_items     = []; // page_id -> menu_item_id
        $custom_items   = []; // norm_url -> menu_item_id
        foreach ($existing_items as $item) {
            if ($item->type === 'taxonomy' && $item->object === $tax) {
                $by_term_object[(int)$item->object_id] = (int)$item->ID;
            } elseif ($item->type === 'post_type' && $item->object === 'page') {
                $page_items[(int)$item->object_id] = (int)$item->ID;
            } elseif ($item->type === 'custom' && !empty($item->url)) {
                $custom_items[ untrailingslashit($item->url) ] = (int)$item->ID;
            }
        }

        // ===== Корневой пункт меню (опционально) =====
        $root_menu_item_id = 0;

        // Вариант A: custom link
        if ( !empty($args['root_url']) ) {
            $root_title   = $args['root_item_title'] ?: ($args['root_page_title'] ?: 'Каталог');
            $root_url_norm = untrailingslashit($args['root_url']);

            if ( isset($custom_items[$root_url_norm]) ) {
                $root_menu_item_id = (int)$custom_items[$root_url_norm];
                self::log("Корневой пункт (custom) уже есть: ID={$root_menu_item_id} URL={$root_url_norm}", $args['verbose']);
            } else {
                $root_menu_item_id = wp_update_nav_menu_item($menu_id, 0, [
                    'menu-item-title'     => $root_title,
                    'menu-item-url'       => $root_url_norm,
                    'menu-item-type'      => 'custom',
                    'menu-item-status'    => 'publish',
                    'menu-item-parent-id' => 0,
                ]);
                if ( ! is_wp_error($root_menu_item_id) ) {
                    $root_menu_item_id = (int) $root_menu_item_id;
                    self::log("Создан корневой пункт (custom): '{$root_title}' → {$root_url_norm} (menu_item_id={$root_menu_item_id})", true);
                } else {
                    self::log("ERROR create root custom: ".$root_menu_item_id->get_error_message(), true);
                    $root_menu_item_id = 0;
                }
            }
        }
        // Вариант B: страница
        elseif ( !empty($args['root_page_title']) ) {
            $page_title = $args['root_page_title'];
            $page = get_page_by_title($page_title);
            if ( ! $page ) {
                $page_id = wp_insert_post([
                    'post_title'   => $page_title,
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_content' => '',
                ]);
                if ( ! is_wp_error($page_id) ) {
                    self::log("Создана страница: '{$page_title}' (ID={$page_id})", true);
                    $page = get_post($page_id);
                } else {
                    self::log("ERROR create page '{$page_title}': ".$page_id->get_error_message(), true);
                }
            }
            if ( $page && isset($page->ID) ) {
                if ( isset($page_items[$page->ID]) ) {
                    $root_menu_item_id = (int)$page_items[$page->ID];
                    self::log("Корневой пункт-страница уже в меню: '{$page_title}' (menu_item_id={$root_menu_item_id})", $args['verbose']);
                } else {
                    $root_menu_item_id = wp_update_nav_menu_item($menu_id, 0, [
                        'menu-item-title'     => $page_title,
                        'menu-item-object'    => 'page',
                        'menu-item-object-id' => (int)$page->ID,
                        'menu-item-type'      => 'post_type',
                        'menu-item-status'    => 'publish',
                        'menu-item-parent-id' => 0,
                    ]);
                    if ( ! is_wp_error($root_menu_item_id) ) {
                        $root_menu_item_id = (int)$root_menu_item_id;
                        self::log("Добавлена в меню страница '{$page_title}' (menu_item_id={$root_menu_item_id})", true);
                    } else {
                        self::log("ERROR add page to menu: ".$root_menu_item_id->get_error_message(), true);
                        $root_menu_item_id = 0;
                    }
                }
            }
        }

        // Перестроение пунктов таксономии по отсортированному дереву
        $created = 0; $matched = 0;

        $add_term = function($term, $depth, $parent_menu_item_id) use (&$add_term, $children, $tax, $menu_id, &$created, &$matched, $args, &$by_term_object) {
            if ($depth > $args['max_depth']) return;

            $term_id = (int)$term->term_id;

            // Если пересборка выключена и пункт уже есть — переиспользуем его
            if ( !$args['rebuild'] && isset($by_term_object[$term_id]) ) {
                $menu_item_id = (int) $by_term_object[$term_id];
                $matched++;
            } else {
                $menu_item_id = wp_update_nav_menu_item($menu_id, 0, [
                    'menu-item-title'     => $term->name,
                    'menu-item-object'    => $tax,
                    'menu-item-object-id' => $term_id,
                    'menu-item-type'      => 'taxonomy',
                    'menu-item-status'    => 'publish',
                    'menu-item-parent-id' => $parent_menu_item_id ? (int)$parent_menu_item_id : 0,
                ]);
                if ( is_wp_error($menu_item_id) ) {
                    self::log('ERROR: '.$term->name.' → '.$menu_item_id->get_error_message(), true);
                    return;
                }
                $menu_item_id = (int)$menu_item_id;
                $by_term_object[$term_id] = $menu_item_id;
                $created++;
                if ($args['verbose']) {
                    self::log("Создан пункт меню: {$term->name} (term_id={$term_id}, menu_item_id={$menu_item_id}, parent_item_id=".(int)$parent_menu_item_id.")", true);
                }
            }

            // Дети в уже отсортированном порядке
            $kids = isset($children[$term_id]) ? $children[$term_id] : [];
            foreach ($kids as $kid) {
                $add_term($kid, $depth+1, $menu_item_id);
            }
        };

        // Корневые элементы цепляем под root_menu_item_id (если он задан)
        foreach ($roots as $r) {
            $add_term($r, 1, $root_menu_item_id);
        }

        self::log("Готово. Совпало существующих: {$matched}. Создано новых пунктов: {$created}. Меню: '{$args['menu_name']}' (ID={$menu_id})", true);
    }

    private static function log($msg, $force=false) {
        if ( defined('WP_CLI') && WP_CLI ) {
            \WP_CLI::log($msg);
        } else {
            if ($force) error_log('[DC_Build_Cat_Menu] '.$msg);
        }
    }
}

// ===== WP-CLI команда =====
if ( defined('WP_CLI') && WP_CLI ) {
    \WP_CLI::add_command('dc cat-menu', function($args, $assoc_args){
        DC_Build_Cat_Menu::build([
            'menu_name'       => $assoc_args['menu']        ?? 'Главное меню',
            'taxonomy'        => $assoc_args['taxonomy']    ?? 'product_cat',
            'max_depth'       => isset($assoc_args['max-depth']) ? intval($assoc_args['max-depth']) : 10,
            'skip_empty'      => isset($assoc_args['skip-empty']),
            'order_by'        => $assoc_args['order-by']    ?? 'name',   // поддерживает meta:order
            'order'           => $assoc_args['order']       ?? 'ASC',
            'verbose'         => isset($assoc_args['verbose']),
            'root_page_title' => $assoc_args['root-page']   ?? null,
            'root_url'        => $assoc_args['root-url']    ?? null,
            'root_item_title' => $assoc_args['root-title']  ?? null,
            'rebuild'         => isset($assoc_args['rebuild']),
        ]);
    });
}

}
