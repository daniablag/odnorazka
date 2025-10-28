<?php
/**
 * Plugin Name:  DC Extra Product Tabs
 * Description:  Дополнительные вкладки (Доставка / Оплата / Гарантія) в конце .entry-summary на странице товара. Единый текст для всего сайта + страница настроек в «Товары».
 * Version:      1.0.0
 * Author:       DC Web Studio
 * License:      GPLv2 or later
 * Text Domain:  dc-extra-product-tabs
 */

if (!defined('ABSPATH')) exit;

/** === Проверка зависимости WooCommerce === */
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>DC Extra Product Tabs:</strong> нужен активный WooCommerce.</p></div>';
        });
    }
});

/** === 1) Админка: подменю «Товары → Додаткові вкладки» === */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=product',
        'Додаткові вкладки',
        'Додаткові вкладки',
        'manage_woocommerce',
        'dc-extra-product-tabs',
        'dc_ept_settings_page_cb'
    );
});

add_action('admin_init', function () {
    register_setting('dc_ept_group', 'dc_extra_tabs', [
        'type' => 'array',
        'sanitize_callback' => function ($input) {
            $out = [
                'shipping_title' => 'Доставка',
                'payment_title'  => 'Оплата',
                'warranty_title' => 'Гарантія',
                'shipping' => '',
                'payment'  => '',
                'warranty' => '',
            ];
            $input = is_array($input) ? $input : [];
            foreach (['shipping','payment','warranty'] as $k) {
                $out[$k] = isset($input[$k]) ? wp_kses_post($input[$k]) : '';
            }
            foreach (['shipping_title','payment_title','warranty_title'] as $k) {
                $out[$k] = isset($input[$k]) ? sanitize_text_field($input[$k]) : $out[$k];
            }
            return $out;
        }
    ]);
});

function dc_ept_settings_page_cb() {
    $opt = wp_parse_args(get_option('dc_extra_tabs', []), [
        'shipping_title' => 'Доставка',
        'payment_title'  => 'Оплата',
        'warranty_title' => 'Гарантія',
        'shipping' => '',
        'payment'  => '',
        'warranty' => '',
    ]);
    ?>
    <div class="wrap">
        <h1>Додаткові вкладки (єдиний текст для всього сайту)</h1>
        <form method="post" action="options.php">
            <?php settings_fields('dc_ept_group'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label>Заголовок вкладки 1</label></th>
                    <td><input type="text" name="dc_extra_tabs[shipping_title]" value="<?php echo esc_attr($opt['shipping_title']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label>Вміст “<?php echo esc_html($opt['shipping_title']); ?>”</label></th>
                    <td><?php wp_editor($opt['shipping'], 'dc_ept_shipping', [
                        'textarea_name' => 'dc_extra_tabs[shipping]',
                        'textarea_rows' => 10,
                    ]); ?></td>
                </tr>

                <tr>
                    <th scope="row"><label>Заголовок вкладки 2</label></th>
                    <td><input type="text" name="dc_extra_tabs[payment_title]" value="<?php echo esc_attr($opt['payment_title']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label>Вміст “<?php echo esc_html($opt['payment_title']); ?>”</label></th>
                    <td><?php wp_editor($opt['payment'], 'dc_ept_payment', [
                        'textarea_name' => 'dc_extra_tabs[payment]',
                        'textarea_rows' => 10,
                    ]); ?></td>
                </tr>

                <tr>
                    <th scope="row"><label>Заголовок вкладки 3</label></th>
                    <td><input type="text" name="dc_extra_tabs[warranty_title]" value="<?php echo esc_attr($opt['warranty_title']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label>Вміст “<?php echo esc_html($opt['warranty_title']); ?>”</label></th>
                    <td><?php wp_editor($opt['warranty'], 'dc_ept_warranty', [
                        'textarea_name' => 'dc_extra_tabs[warranty]',
                        'textarea_rows' => 10,
                    ]); ?></td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Превратить текст из опций в HTML с абзацами/переносами и шорткодами
function dc_ept_render_content($raw){
    if (!is_string($raw) || $raw === '') return '';
    // 1) сначала шорткоды
    $html = do_shortcode($raw);
    // 2) затем абзацы/переносы как в WP: двойной перевод строки → <p>, одинарный → <br>
    $html = wpautop($html);
    // 3) финальная очистка допустимым HTML
    return wp_kses_post($html);
}


/** === 2) Фронт: вывод блока вкладок в самом конце .entry-summary === */
add_action('woocommerce_single_product_summary', function () {
    if (!is_product() || !class_exists('WooCommerce')) return;

    $opt = wp_parse_args(get_option('dc_extra_tabs', []), [
        'shipping_title' => 'Доставка',
        'payment_title'  => 'Оплата',
        'warranty_title' => 'Гарантія',
        'shipping' => '',
        'payment'  => '',
        'warranty' => '',
    ]);

    // Пусто — не выводим
    if (empty($opt['shipping']) && empty($opt['payment']) && empty($opt['warranty'])) return;

    $uid = 'dc-extra-tabs-' . wp_generate_uuid4();

    echo '<div id="'.esc_attr($uid).'" class="dc-extra-tabs" data-dc-tabs>';
    echo '  <div class="dc-extra-tabs__nav" role="tablist" aria-label="Додатково">';

    $tabs = [
        ['key'=>'shipping','title'=>$opt['shipping_title']],
        ['key'=>'payment','title'=>$opt['payment_title']],
        ['key'=>'warranty','title'=>$opt['warranty_title']],
    ];

    $firstActivePrinted = false;
    foreach ($tabs as $t) {
        if (empty($opt[$t['key']])) continue;
        $active = $firstActivePrinted ? '' : ' aria-selected="true" data-active';
        $firstActivePrinted = $firstActivePrinted ?: true;
        $tid = $uid.'-'.$t['key'];
        echo '<button class="dc-extra-tabs__tab" role="tab" data-target="#'.esc_attr($tid).'"'.$active.'>'.esc_html($t['title']).'</button>';
    }
    echo '  </div>';

    // Панели
    $printedFirst = false;
    foreach ($tabs as $t) {
        if (empty($opt[$t['key']])) continue;
        $tid = $uid.'-'.$t['key'];
        $hidden = $printedFirst ? ' hidden' : '';
        echo '<div id="'.esc_attr($tid).'" class="dc-extra-tabs__panel" role="tabpanel"'.$hidden.'>';
        echo dc_ept_render_content( $opt[$t['key']] );
        echo '</div>';
        $printedFirst = true;
    }

    echo '</div>';

}, 1000); // высокий приоритет — в самом конце .entry-summary

/** === 3) Мини-стили и JS (без зависимостей) === */
add_action('wp_head', function () {
    ?>
    <style>
    .dc-extra-tabs{margin-top:1.25rem;border-top:1px solid var(--ast-border-color,#e5e7eb);padding-top:1rem}
    .dc-extra-tabs__nav{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem}
    .dc-extra-tabs__tab{border:1px solid var(--ast-border-color,#e5e7eb);background:#fff;border-radius:9999px;padding:.4rem .75rem;cursor:pointer;line-height:1}
    .dc-extra-tabs__tab[data-active]{font-weight:600;border-color:currentColor;background-color:var(--ast-global-color-0)}
    .dc-extra-tabs__panel{animation:dcTabFade .15s ease}
    @keyframes dcTabFade{from{opacity:.6}to{opacity:1}}
    </style>
    <script>
    document.addEventListener('click', function(e){
      var btn = e.target.closest('.dc-extra-tabs__tab');
      if(!btn) return;
      var wrap = btn.closest('[data-dc-tabs]');
      if(!wrap) return;

      wrap.querySelectorAll('.dc-extra-tabs__tab[data-active]').forEach(function(b){
        b.removeAttribute('data-active');
        b.setAttribute('aria-selected','false');
      });
      btn.setAttribute('data-active','');
      btn.setAttribute('aria-selected','true');

      var targetSel = btn.getAttribute('data-target');
      wrap.querySelectorAll('.dc-extra-tabs__panel').forEach(function(p){ p.hidden = true; });
      var panel = wrap.querySelector(targetSel);
      if(panel) panel.hidden = false;
    });
    </script>
    <?php
}, 99);
