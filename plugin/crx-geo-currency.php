<?php
/**
 * Plugin Name: CRX Geo Currency (Universal)
 * Plugin URI: https://github.com/Braska-CX/woocommerce-geo-lang-currency
 * Description: Switches WooCommerce currency by visitor geolocation, for any WPML + WooCommerce Multilingual (WCML) setup. Every country/currency mapping is configurable via a filter - nothing is hardcoded.
 * Version: 1.0.1
 * Author: Matěj Horák
 * License: MIT
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------
 * CONFIG
 *
 * Everything below is controlled through a single filter so this
 * file never has to be edited for day-to-day configuration:
 *
 *     add_filter( 'crx_glc_config', function ( array $config ): array {
 *         $config['currency_map']['US'] = 'USD';
 *         $config['currency_map']['GB'] = 'GBP';
 *         return $config;
 *     } );
 * ------------------------------------------------------------ */

function crx_glc_config(): array {
    $defaults = [
        // ISO 3166-1 alpha-2 country code => ISO 4217 currency code.
        // Countries not listed here fall back to 'default_currency'.
        'currency_map' => [
            // 'CZ' => 'CZK',
            // 'GB' => 'GBP',
        ],

        // Currency used for visitors whose country isn't in 'currency_map'.
        'default_currency' => get_option( 'woocommerce_currency' ),

        // Lifetime of the currency preference cookies.
        'cookie_ttl' => DAY_IN_SECONDS * 30,

        // Bots/crawlers matching this pattern always see the shop's default
        // currency instead of a geolocated one.
        'bot_pattern' => '/bot|crawl|slurp|spider|google|bing|yandex|baidu|duckduck|facebookexternalhit|twitterbot|lighthouse|gptbot|chatgpt|claudebot|ccbot|semrush|ahrefs|mj12bot|crawler|monitor|uptime/i',
    ];

    return apply_filters( 'crx_glc_config', $defaults );
}

/* ------------------------------------------------------------
 * HELPERS
 * ------------------------------------------------------------ */

function crx_glc_is_bot_request(): bool {
    $ua = strtolower( $_SERVER['HTTP_USER_AGENT'] ?? '' );
    if ( $ua === '' ) return false;
    return (bool) preg_match( crx_glc_config()['bot_pattern'], $ua );
}

function crx_glc_country(): string {
    static $country = null;
    if ( $country !== null ) return $country;
    if ( ! class_exists( 'WC_Geolocation' ) ) {
        $country = '';
        return $country;
    }
    $geo     = WC_Geolocation::geolocate_ip();
    $country = strtoupper( $geo['country'] ?? '' );
    return $country;
}

function crx_glc_target_currency( string $country ): string {
    $config = crx_glc_config();
    return $config['currency_map'][ $country ] ?? $config['default_currency'];
}

function crx_glc_get_cookie( string $name ): string {
    return (string) ( $_COOKIE[ $name ] ?? '' );
}

/* ------------------------------------------------------------
 * JS COOKIE HELPERS
 * ------------------------------------------------------------ */

function crx_glc_output_js_cookie_helpers(): void {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    $ttl = crx_glc_config()['cookie_ttl'];
    ?>
    <script>
    (function() {
        window.crxGlcSetCookie = function(name, value, ttlSeconds) {
            var exp = new Date();
            exp.setTime(exp.getTime() + ttlSeconds * 1000);
            document.cookie = name + '=' + encodeURIComponent(value)
                + '; expires=' + exp.toUTCString()
                + '; path=/'
                + '; secure'
                + '; SameSite=Lax';
        };
        window.crxGlcGetCookie = function(name) {
            var v = document.cookie.match('(^|;) ?' + name + '=([^;]*)(;|$)');
            return v ? decodeURIComponent(v[2]) : '';
        };
        window.crxGlcCookieTtl = <?php echo (int) $ttl; ?>;
    })();
    </script>
    <?php
}

/* ------------------------------------------------------------
 * CURRENCY: resolved on every request, straight from geolocation
 * ------------------------------------------------------------ */

function crx_glc_set_wcml_runtime_currency( string $currency ): void {
    if ( $currency === '' ) return;
    if ( ! isset( $GLOBALS['woocommerce_wpml'] ) || ! is_object( $GLOBALS['woocommerce_wpml'] ) ) return;
    $wpml_woo = $GLOBALS['woocommerce_wpml'];
    if (
        isset( $wpml_woo->multi_currency )
        && is_object( $wpml_woo->multi_currency )
        && method_exists( $wpml_woo->multi_currency, 'set_client_currency' )
    ) {
        $wpml_woo->multi_currency->set_client_currency( $currency );
    }
}

function crx_glc_force_geo_currency( string $currency ): void {
    if ( $currency === '' ) return;
    crx_glc_set_wcml_runtime_currency( $currency );
    add_action( 'wp_footer', function () use ( $currency ) {
        crx_glc_output_js_cookie_helpers();
        ?>
        <script>
        (function() {
            var c = <?php echo wp_json_encode( $currency ); ?>;
            if (window.crxGlcGetCookie('wcml_client_currency') !== c) {
                window.crxGlcSetCookie('wcml_client_currency', c, window.crxGlcCookieTtl);
            }
            if (window.crxGlcGetCookie('woocommerce_current_currency') !== c) {
                window.crxGlcSetCookie('woocommerce_current_currency', c, window.crxGlcCookieTtl);
            }
        })();
        </script>
        <?php
    }, 5 );
}

// Primary fix: hooking into `wcml_client_currency` runs before WCML
// registers its price filters, so the geolocated currency is applied
// consistently - including on the very first pageview and on wc-ajax
// add-to-cart requests. See README.md for the full explanation.
add_filter( 'wcml_client_currency', function ( $currency ) {
    if ( is_admin() ) return $currency;
    if ( crx_glc_is_bot_request() ) return $currency;

    $country = crx_glc_country();
    if ( $country === '' ) return $currency;

    return crx_glc_target_currency( $country );
}, 20 );

// Safety net: keeps the client-side currency cookies in sync so that any
// code that reads them directly (rather than through the WCML filter)
// still sees the correct value.
add_action( 'wp', function () {
    if ( is_admin() ) return;
    // Skip AJAX requests (this includes wc-ajax=add_to_cart): the
    // wcml_client_currency filter above already set the correct currency
    // during init, before WooCommerce processed the cart action. Calling
    // set_client_currency() again here, mid-request, makes WCML think the
    // currency just changed and empties the cart it was just added to.
    if ( wp_doing_ajax() ) return;
    if ( crx_glc_is_bot_request() ) return;

    $country = crx_glc_country();
    if ( $country === '' ) return;

    crx_glc_force_geo_currency( crx_glc_target_currency( $country ) );
}, 10 );
