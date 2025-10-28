<?php
/**
 * Plugin Name: DC Loyalty (Накопичувальна знижка)
 * Description: Вкладка "Накопичувальна знижка" у кабінеті WooCommerce з прогрес-баром. Пороги: від 10 000 грн — 5%, від 50 000 грн — 10%. Без будь-яких валютних конверсій — лише базова валюта магазину.
 * Author: DC Web Studio
 * Version: 1.0.1
 */

if ( ! defined('ABSPATH') ) exit;

// ---------------- Налаштування порогів ----------------
function dc_loyalty_get_tiers(): array {
    // Ключ = поріг (у базовій валюті магазину), значення = знижка (частка)
    return [
        10000 => 0.05, // від 10 000 — 5%
        50000 => 0.10, // від 50 000 — 10%
    ];
}
function dc_loyalty_endpoint_slug(): string { return 'loyalty'; }

// ---------------- Endpoint & меню ----------------
add_action('init', function () {
    add_rewrite_endpoint( dc_loyalty_endpoint_slug(), EP_ROOT | EP_PAGES );
}, 9);

add_filter('woocommerce_account_menu_items', function($items){
    $new = [];
    foreach ($items as $key => $label) {
        if ($key === 'customer-logout') {
            $new[ dc_loyalty_endpoint_slug() ] = 'Накопичувальна знижка';
        }
        $new[$key] = $label;
    }
    if (!isset($new[ dc_loyalty_endpoint_slug() ])) {
        $new[ dc_loyalty_endpoint_slug() ] = 'Накопичувальна знижка';
    }
    return $new;
});

add_filter('query_vars', function($vars){
    $vars[] = dc_loyalty_endpoint_slug();
    return $vars;
});

// ---------------- Підключення CSS лише на сторінці акаунту ----------------
add_action('wp_enqueue_scripts', function () {
    if ( function_exists('is_account_page') && is_account_page() ) {
        wp_enqueue_style(
            'dc-loyalty',
            plugins_url('assets/loyalty.css', __FILE__),
            [],
            '1.0.1'
        );
    }
});

// ---------------- Хелпер форматування суми ----------------
function dc_loyalty_wc_price_display($amount){
    return wc_price( (float)$amount );
}

// ---------------- Админка: настройки исключений ----------------
// Храним два текстовых поля (CSV ID): исключённые категории и товары.
add_action('admin_init', function(){
    register_setting(
        'dc_loyalty_settings',
        'dc_loyalty_excluded_category_ids',
        [ 'type' => 'string', 'sanitize_callback' => 'dc_loyalty_sanitize_id_csv', 'default' => '' ]
    );
    register_setting(
        'dc_loyalty_settings',
        'dc_loyalty_excluded_product_ids',
        [ 'type' => 'string', 'sanitize_callback' => 'dc_loyalty_sanitize_id_csv', 'default' => '' ]
    );
});

add_action('admin_menu', function(){
    if ( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) return;
    add_submenu_page(
        'woocommerce',
        'DC Loyalty',
        'DC Loyalty',
        'manage_woocommerce',
        'dc-loyalty-settings',
        'dc_loyalty_render_settings_page'
    );
});

// Профиль пользователя (админка): блок с прогрессом
add_action( 'show_user_profile', 'dc_loyalty_render_admin_user_progress' );
add_action( 'edit_user_profile', 'dc_loyalty_render_admin_user_progress' );
function dc_loyalty_render_admin_user_progress( WP_User $user ){
    if ( ! class_exists('WooCommerce') ) return;
    $state = dc_loyalty_calculate_state( $user->ID );
    if ( ! $state ) return;

    $pct = (int) $state['progress_pct'];
    $label5_active  = $state['current_discount'] >= 0.05 ? 'active' : '';
    $label10_active = $state['current_discount'] >= 0.10 ? 'active' : '';
    ?>
    <h2>Накопичувальна знижка</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th>Прогрес</th>
            <td>
                <div class="dc-loyalty__progress" aria-label="Прогрес до наступного рівня">
                    <div class="dc-loyalty__progress-bar" style="width: <?php echo (int)$pct; ?>%"></div>
                </div>
                <div class="dc-loyalty__progress-labels">
                    <span class="label <?php echo esc_attr($label5_active); ?>">5%</span>
                    <span class="label <?php echo esc_attr($label10_active); ?>">10%</span>
                </div>
                <div class="dc-loyalty__muted">Прогрес: <?php echo (int)$pct; ?>%</div>
            </td>
        </tr>
    </table>
    <?php
}

function dc_loyalty_sanitize_id_csv( $value ){
    $ids = dc_loyalty_parse_id_csv( (string) $value );
    return implode(',', $ids);
}

function dc_loyalty_parse_id_csv( string $raw ): array {
    // Разрешаем разделители: запятая, пробел, точка с запятой, табуляция, новая строка
    $parts = preg_split('/[^0-9]+/', $raw);
    $out   = [];
    if ( is_array($parts) ) {
        foreach ( $parts as $p ) {
            $id = (int) $p;
            if ( $id > 0 ) $out[$id] = true; // уникально
        }
    }
    return array_keys( $out );
}

function dc_loyalty_get_excluded_category_ids(): array {
    $raw = (string) get_option('dc_loyalty_excluded_category_ids', '');
    return dc_loyalty_parse_id_csv( $raw );
}

function dc_loyalty_get_excluded_product_ids(): array {
    $raw = (string) get_option('dc_loyalty_excluded_product_ids', '');
    return dc_loyalty_parse_id_csv( $raw );
}

/**
 * Проверка: товар/позиция корзины исключён из скидки?
 * Логика: исключение по товару ИЛИ по любой из его категорий. Поля пустые — никто не исключён.
 */
function dc_loyalty_get_product_base_id( WC_Product $product ): int {
    if ( $product->is_type( 'variation' ) ) {
        $parent_id = (int) $product->get_parent_id();
        return $parent_id > 0 ? $parent_id : (int) $product->get_id();
    }
    return (int) $product->get_id();
}

function dc_loyalty_is_product_excluded( WC_Product $product ): bool {
    $excluded_product_ids  = dc_loyalty_get_excluded_product_ids();
    $excluded_category_ids = dc_loyalty_get_excluded_category_ids();
    if ( empty($excluded_product_ids) && empty($excluded_category_ids) ) return false;

    $product_id    = (int) $product->get_id();
    $base_product  = dc_loyalty_get_product_base_id( $product );

    // Исключение по товару: учитываем и вариацию, и родителя
    if ( ! empty($excluded_product_ids) ) {
        if ( in_array( $product_id, $excluded_product_ids, true ) || in_array( $base_product, $excluded_product_ids, true ) ) {
            return true;
        }
    }

    // Исключение по категории: берём категории базового товара
    if ( ! empty($excluded_category_ids) ) {
        $terms = get_the_terms( $base_product, 'product_cat' );
        if ( is_array($terms) ) {
            foreach ( $terms as $term ) {
                if ( in_array( (int) $term->term_id, $excluded_category_ids, true ) ) {
                    return true;
                }
            }
        }
    }

    return false;
}

function dc_loyalty_is_cart_item_excluded( array $cart_item ): bool {
    $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
    if ( ! $product || ! $product instanceof WC_Product ) return false;
    return dc_loyalty_is_product_excluded( $product );
}

function dc_loyalty_cart_has_eligible_items(): bool {
    if ( ! function_exists('WC') || ! WC()->cart ) return false;
    $cart = WC()->cart;
    if ( $cart->is_empty() ) return false;
    foreach ( $cart->get_cart() as $cart_item ) {
        if ( ! dc_loyalty_is_cart_item_excluded( $cart_item ) ) {
            return true;
        }
    }
    return false;
}

function dc_loyalty_render_settings_page(){
    if ( ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options') ) return;
    ?>
    <div class="wrap">
        <h1>DC Loyalty — Настройки</h1>
        <form method="post" action="options.php">
            <?php settings_fields('dc_loyalty_settings'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="dc_loyalty_excluded_category_ids">Исключённые категории (ID)</label></th>
                    <td>
                        <input type="text" id="dc_loyalty_excluded_category_ids" name="dc_loyalty_excluded_category_ids" value="<?php echo esc_attr( get_option('dc_loyalty_excluded_category_ids', '') ); ?>" class="regular-text" placeholder="например: 12,34,56">
                        <p class="description">ID рубрик товара <code>product_cat</code>, через запятую. Пусто — все категории участвуют.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dc_loyalty_excluded_product_ids">Исключённые товары (ID)</label></th>
                    <td>
                        <input type="text" id="dc_loyalty_excluded_product_ids" name="dc_loyalty_excluded_product_ids" value="<?php echo esc_attr( get_option('dc_loyalty_excluded_product_ids', '') ); ?>" class="regular-text" placeholder="например: 101,202,303">
                        <p class="description">ID товаров через запятую. Пусто — все товары участвуют.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Подключаем наши стили в админке для профиля пользователя
add_action('admin_enqueue_scripts', function($hook){
    if ( $hook === 'profile.php' || $hook === 'user-edit.php' ) {
        wp_enqueue_style(
            'dc-loyalty-admin',
            plugins_url('assets/loyalty.css', __FILE__),
            [],
            '1.0.1'
        );
    }
});

/**
 * Возвращает текущую ставку накопительной скидки (доля, например 0.05).
 * Источники: сессия (быстро), либо применённый купон (на случай AJAX-фрагментов).
 */
function dc_loyalty_get_current_rate( bool $scan_cart_coupons = true ): float {
    $rates = [];

    // 1) То, что уже записано в сессии
    if ( function_exists('WC') && WC()->session ) {
        $rates[] = (float) WC()->session->get('dc_loyalty_rate', 0 );
    }

    // 2) То, что в уже применённом купоне (если есть)
    if ( $scan_cart_coupons && function_exists('WC') && WC()->cart ) {
        $coupons = WC()->cart->get_coupons();
        if ( ! empty( $coupons ) ) {
            foreach ( $coupons as $code => $coupon ) {
                $code_l = strtolower( (string) $code );
                if ( $code_l === DC_LOYALTY_COUPON_CODE && ( class_exists('WC_Coupon') ? ( $coupon instanceof WC_Coupon ) : true ) ) {
                    $amount = (float) $coupon->get_amount();
                    if ( $amount > 0 ) {
                        $rates[] = $amount / 100.0;
                    }
                }
            }
        }
    }

    // 3) Исторический уровень пользователя
    $historical = 0.0;
    if ( is_user_logged_in() ) {
        $state = dc_loyalty_calculate_state( get_current_user_id() );
        if ( $state && ! empty( $state['current_discount'] ) ) {
            $historical = (float) $state['current_discount'];
            $rates[] = $historical;
        }
    }

    // 4) Прогнозный уровень: исторический + сумма текущей корзины (только eligible позиции)
    $projected = 0.0;
    if ( is_user_logged_in() && function_exists('WC') && WC()->cart && ! WC()->cart->is_empty() ) {
        $user_id = get_current_user_id();
        $tiers   = dc_loyalty_get_tiers();
        ksort( $tiers, SORT_NUMERIC );

        $base_spent = (float) wc_get_customer_total_spent( $user_id );
        $eligible_cart_sum = 0.0;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( dc_loyalty_is_cart_item_excluded( $cart_item ) ) continue;
            $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
            if ( ! $product || ! $product instanceof WC_Product ) continue;
            $qty = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1;
            $price = (float) wc_get_price_to_display( $product );
            $eligible_cart_sum += max( 0, $price ) * max( 0, $qty );
        }

        $projected_spent = $base_spent + $eligible_cart_sum;
        $projected_level = 0.0;
        foreach ( $tiers as $threshold => $discount ) {
            if ( $projected_spent >= (float) $threshold ) {
                $projected_level = (float) $discount;
            } else {
                break;
            }
        }

        $projected = max( 0.0, (float) $projected_level );
        $rates[] = $projected;
    }

    $rate = 0.0;
    if ( ! empty( $rates ) ) {
        $rate = max( $rates );
    }

    return $rate > 0 ? (float) $rate : 0.0;
}

// ---------------- Розрахунок стану ----------------
function dc_loyalty_calculate_state($user_id){
    $tiers = dc_loyalty_get_tiers();
    if (empty($tiers)) return null;

    ksort($tiers, SORT_NUMERIC);

    // WooCommerce: total_spent з lookup-таблиці (швидко), у базовій валюті магазину
    $total_spent_base = (float) wc_get_customer_total_spent( $user_id );

    $current_discount = 0.0;
    $next_threshold   = null;
    foreach ($tiers as $threshold => $discount) {
        if ($total_spent_base >= $threshold) {
            $current_discount = (float)$discount;
        } else {
            $next_threshold = (float)$threshold;
            break;
        }
    }

    if ($next_threshold !== null) {
        $prev_threshold = 0.0;
        foreach ($tiers as $threshold => $d) {
            if ($threshold <= $total_spent_base) $prev_threshold = (float)$threshold;
        }
        $span = max(1.0, $next_threshold - $prev_threshold);
        $progress_pct = max(0, min(100, (($total_spent_base - $prev_threshold) / $span) * 100));
        $left_to_next_base = max(0, $next_threshold - $total_spent_base);
    } else {
        $progress_pct = 100;
        $left_to_next_base = 0.0;
    }

    return [
        'total_spent_base'   => $total_spent_base,
        'current_discount'   => $current_discount, // 0.10 = 10%
        'next_threshold'     => $next_threshold,   // null якщо топ-рівень
        'left_to_next_base'  => $left_to_next_base,
        'progress_pct'       => (int) round($progress_pct),
        'tiers'              => $tiers,
    ];
}

// ---------------- Рендер вкладки ----------------
add_action('woocommerce_account_' . 'loyalty' . '_endpoint', function () {
    if (!is_user_logged_in()) {
        echo '<p>Щоб переглянути накопичувальну знижку, увійдіть до облікового запису.</p>';
        return;
    }

    $user_id = get_current_user_id();
    $state   = dc_loyalty_calculate_state($user_id);

    if (!$state) {
        echo '<p>Накопичувальна програма ще не налаштована.</p>';
        return;
    }

    // Показуємо значення як є (базова валюта магазину)
    $total_spent_disp  = $state['total_spent_base'];
    $left_to_next_disp = $state['left_to_next_base'];

    $discount_label = $state['current_discount'] > 0
        ? ( (int)round($state['current_discount'] * 100) . '%' )
        : 'немає';

    $pct = (int)$state['progress_pct'];

    // Список рівнів
    $levels_html = '';
    foreach ($state['tiers'] as $threshold => $discount) {
        $thr_disp = dc_loyalty_wc_price_display( $threshold );
        $levels_html .= sprintf(
            '<li>від %s — %d%%</li>',
            $thr_disp,
            (int) round($discount * 100)
        );
    }

    ?>
    <div class="dc-loyalty">
        <h3 class="dc-loyalty__title">Накопичувальна знижка</h3>

        <div class="dc-loyalty__kpis">
            <div class="dc-loyalty__kpi">
                <strong>Сума покупок:</strong><br>
                <?php echo dc_loyalty_wc_price_display($total_spent_disp); ?>
                <div class="dc-loyalty__muted">Ураховуються оплачені / завершені замовлення.</div>
            </div>
            <div class="dc-loyalty__kpi">
                <strong>Поточна знижка:</strong><br>
                <span class="dc-loyalty__current-discount"><?php echo esc_html($discount_label); ?></span>
                <div class="dc-loyalty__muted">
                    <?php if ($state['next_threshold'] !== null): ?>
                        До наступного рівня залишилось:
                        <?php echo dc_loyalty_wc_price_display($left_to_next_disp); ?>
                    <?php else: ?>
                        Ви на максимальному рівні. 🔥
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="dc-loyalty__progress" aria-label="Прогрес до наступного рівня">
            <div class="dc-loyalty__progress-bar" style="width: <?php echo (int)$pct; ?>%">
                <span class="dc-loyalty__progress-val"><?php echo (int)$pct; ?>%</span>
            </div>
        </div>
        <?php
            $label5_active  = $state['current_discount'] >= 0.05 ? 'active' : '';
            $label10_active = $state['current_discount'] >= 0.10 ? 'active' : '';
        ?>
        <div class="dc-loyalty__progress-labels">
            <span class="label <?php echo esc_attr($label5_active); ?>">5%</span>
            <span class="label <?php echo esc_attr($label10_active); ?>">10%</span>
        </div>
        <div class="dc-loyalty__muted">Прогрес: <?php echo (int)$pct; ?>%</div>

        <h4 class="dc-loyalty__subtitle">Рівні програми</h4>
        <ul class="dc-loyalty__levels">
            <?php echo $levels_html; ?>
        </ul>
    </div>
    <?php
});

// ===== Применение накопительной скидки в корзине/чекауте =====
/**
 * ===== Авто-купон "Накопичувальна знижка" (видно в корзине и мини-корзине Astra) =====
 * - Купон виртуальный (через фильтр woocommerce_get_shop_coupon_data), в БД не создаётся.
 * - Процент скидки динамический: из dc_loyalty_calculate_state() (5% или 10%).
 * - Авто-применение: добавляем/удаляем купон в зависимости от уровня.
 *
 * Код рассчитан на базовую валюту (как и весь плагин).
 */

define('DC_LOYALTY_COUPON_CODE', 'dc_loyalty'); // код купона в корзине

// 1) Держим текущий процент в сессии, чтобы купон "знал" свою величину
add_action('woocommerce_before_calculate_totals', function($cart){
    if ( is_admin() && ! defined('DOING_AJAX') ) return;

    if ( ! is_user_logged_in() ) {
        WC()->session->__unset('dc_loyalty_rate');
        return;
    }

    $state = dc_loyalty_calculate_state( get_current_user_id() );
    $rate  = $state ? (float)$state['current_discount'] : 0.0; // 0.05 / 0.10 / 0

    // Сохраняем в сессию для последующего чтения купоном
    if ( $rate > 0 ) {
        WC()->session->set('dc_loyalty_rate', $rate);
    } else {
        WC()->session->__unset('dc_loyalty_rate');
    }
}, 5);

// 2) Описываем виртуальный купон с динамическим процентом
add_filter('woocommerce_get_shop_coupon_data', function($data, $code){
    if ( strcasecmp($code, DC_LOYALTY_COUPON_CODE) !== 0 ) {
        return $data; // не наш купон
    }

    // Берём актуальную ставку (max из исторической и прогнозной по текущей корзине)
    // Здесь НЕ сканируем уже применённые купоны, чтобы избежать рекурсии/роста памяти при расчёте купона
    $rate = dc_loyalty_get_current_rate( false );

    if ( $rate <= 0 ) {
        return false; // купона как бы не существует, если скидки нет
    }

    // Преобразуем долю в проценты
    $percent = round($rate * 100, 2); // 5 или 10

    // Исключения: категории/товары из настроек
    $excluded_cats = dc_loyalty_get_excluded_category_ids();
    $excluded_prods = dc_loyalty_get_excluded_product_ids();

    return [
        'id'                         => -1337,             // фиктивный ID
        'discount_type'              => 'percent',         // процентная скидка
        'amount'                     => (string)$percent,  // "5" или "10"
        'individual_use'             => false,
        'product_ids'                => [],
        'exclude_product_ids'        => array_map('strval', $excluded_prods ),
        'usage_limit'                => '',
        'usage_limit_per_user'       => '',
        'limit_usage_to_x_items'     => '',
        'free_shipping'              => false,
        'product_categories'         => [],
        'excluded_product_categories'=> array_map('strval', $excluded_cats ),
        'exclude_sale_items'         => false,             // если надо не трогать распродажи — поставь true
        'minimum_amount'             => '',
        'maximum_amount'             => '',
        'email_restrictions'         => [],
        'virtual'                    => true,
        'description'                => 'Накопичувальна знижка',
    ];
}, 10, 2);

// 3) Авто-применяем или снимаем купон в зависимости от уровня — выполняется в dc_loyalty_sync_coupon()

// 5) Красивое имя купона в интерфейсе
add_filter('woocommerce_cart_totals_coupon_label', function($label, $coupon){
    if ( strtolower($coupon->get_code()) === DC_LOYALTY_COUPON_CODE ) {
        return __('Накопичувальна знижка', 'dc-loyalty');
    }
    return $label;
}, 10, 2);

// Унифицированная функция: кладём процент в сессию и синхронизируем купон
function dc_loyalty_sync_coupon() {
    if ( ! function_exists('WC') || ! WC()->cart ) return;

    // Стартуем минимум после инициализации Woo.
    $rate = dc_loyalty_get_current_rate();

    // Сохраняем/очищаем сессию
    if ( WC()->session ) {
        if ( $rate > 0 ) {
            WC()->session->set('dc_loyalty_rate', $rate);
        } else {
            WC()->session->__unset('dc_loyalty_rate');
        }
    }

    // Синхронизируем купон
    $applied = array_map( 'strtolower', WC()->cart->get_applied_coupons() );
    $has     = in_array( DC_LOYALTY_COUPON_CODE, $applied, true );

    $eligible = dc_loyalty_cart_has_eligible_items();
    // Если нет ни одной подходящей позиции — никогда не применять купон
    if ( ! $eligible ) {
        if ( $has ) {
            WC()->cart->remove_coupon( DC_LOYALTY_COUPON_CODE );
        }
        return;
    }

    if ( $rate > 0 && ! $has ) {
        WC()->cart->apply_coupon( DC_LOYALTY_COUPON_CODE );
    } elseif ( $rate <= 0 && $has ) {
        WC()->cart->remove_coupon( DC_LOYALTY_COUPON_CODE );
    }
}

// Подключаем в нескольких местах, чтобы покрыть все сценарии (Astra мини-корзина, AJAX-фрагменты и т.д.)
add_action( 'wp', 'dc_loyalty_sync_coupon', 10 );
add_action( 'woocommerce_before_calculate_totals', 'dc_loyalty_sync_coupon', 1 );
add_action( 'woocommerce_cart_loaded_from_session', 'dc_loyalty_sync_coupon', 1 );
add_action( 'woocommerce_cart_updated', 'dc_loyalty_sync_coupon', 1 );

// Перерисовываем колонку "Проміжний підсумок" с учётом накопительной скидки
add_filter( 'woocommerce_cart_item_subtotal', function( $subtotal_html, $cart_item, $cart_item_key ) {
    $rate = dc_loyalty_get_current_rate();
    if ( $rate <= 0 ) return $subtotal_html;

    $product  = $cart_item['data'];
    if ( ! $product || ! $product instanceof WC_Product ) return $subtotal_html;

    // Исключённые товары/категории — не переопределяем отображение
    if ( dc_loyalty_is_cart_item_excluded( $cart_item ) ) return $subtotal_html;

    $qty = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1;
    // Цена с учётом настроек отображения (вкл. налоги и пр.)
    $display_price = (float) wc_get_price_to_display( $product );
    $discounted = max( 0, $display_price * ( 1 - $rate ) ) * $qty;

    return wc_price( $discounted );
}, 10, 3 );

// Перерисовываем цену единицы товара для мини-корзины/корзины с учётом накопительной скидки
add_filter( 'woocommerce_cart_item_price', function( $price_html, $cart_item, $cart_item_key ) {
    $rate = dc_loyalty_get_current_rate();
    if ( $rate <= 0 ) return $price_html;

    $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
    if ( ! $product || ! $product instanceof WC_Product ) return $price_html;

    // Исключённые — оставляем базовую цену
    if ( dc_loyalty_is_cart_item_excluded( $cart_item ) ) return $price_html;

    // Базовая цена для отображения (учитывает настройки налогов WooCommerce)
    $base_unit_price = (float) wc_get_price_to_display( $product );
    $discounted_unit = max( 0, $base_unit_price * ( 1 - $rate ) );

    return wc_price( $discounted_unit );
}, 10, 3 );

// --- Мини-корзина: показывать total с учётом купонов (а не subtotal) ---
if ( ! function_exists('dc_loyalty_widget_total_with_discounts') ) {
    function dc_loyalty_widget_total_with_discounts() {
        $cart = WC()->cart;
        if ( ! $cart ) return;

        // get_total() — уже с учётом купонов/скидок и настроек налогов.
        echo '<strong>' . wp_kses_post( $cart->get_total() ) . '</strong>';
    }
}

// Уберём дефолтный subtotal и выведем total с учётом скидки
add_action('init', function () {
    // по умолчанию Woo выполняет:
    // do_action( 'woocommerce_widget_shopping_cart_total' );
    // -> hooked: woocommerce_widget_shopping_cart_subtotal (10)
    remove_action( 'woocommerce_widget_shopping_cart_total', 'woocommerce_widget_shopping_cart_subtotal', 10 );
    add_action( 'woocommerce_widget_shopping_cart_total', 'dc_loyalty_widget_total_with_discounts', 10 );
}, 11);

// Скрываем сообщение об ошибке для нашего автокупона, если в корзине нет подходящих товаров
add_filter( 'woocommerce_coupon_error', function( $error, $error_code, $coupon ){
    try {
        $code_l = is_object($coupon) && method_exists($coupon,'get_code') ? strtolower( (string) $coupon->get_code() ) : strtolower( (string) $coupon );
    } catch ( \Throwable $e ) {
        $code_l = strtolower( (string) $coupon );
    }
    if ( $code_l === DC_LOYALTY_COUPON_CODE ) {
        if ( ! dc_loyalty_cart_has_eligible_items() ) {
            return '';
        }
    }
    return $error;
}, 10, 3 );

// Подменяем строку количества в стандартной мини-корзине (не Astra Addon off-canvas)
if ( ! class_exists( 'Astra_Ext_WooCommerce_Markup' ) ) {
    add_filter( 'woocommerce_widget_cart_item_quantity', function( $html, $cart_item, $cart_item_key ) {
        $rate = dc_loyalty_get_current_rate();
        if ( $rate <= 0 ) return $html;

        $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
        if ( ! $product || ! $product instanceof WC_Product ) return $html;

        if ( dc_loyalty_is_cart_item_excluded( $cart_item ) ) return $html;

        $qty = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1;
        $base_unit_price = (float) wc_get_price_to_display( $product );
        $discounted_unit = max( 0, $base_unit_price * ( 1 - $rate ) );

        return '<span class="quantity">' . sprintf( '%s &times; %s', wc_clean( $qty ), wc_price( $discounted_unit ) ) . '</span>';
    }, 20, 3 );
}
