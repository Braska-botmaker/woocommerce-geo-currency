# Changelog

All notable changes to this project are documented in this file.

## [3.0.1] - Universal plugin + legacy CZ plugin

### Fixed

- **Critical: added products were immediately removed from the cart.** The `add_action('wp', ...)` safety-net hook ran on every frontend request, including `wc-ajax=add_to_cart` (WooCommerce defines `DOING_AJAX` for these too, but the hook never checked for it). It called WCML's `set_client_currency()` a second time in the middle of the add-to-cart request; WCML treats that as a currency change and empties the cart to avoid mixing prices from different currencies — deleting the item that had just been added. Fixed in both `plugin/crx-geo-currency.php` (v1.0.1) and `plugin-cz/crx-geo-lang-currency.php` (v2.0.1) by skipping the hook entirely on `wp_doing_ajax()`. The primary fix (the `wcml_client_currency` filter) already sets the currency correctly during `init`, before the cart is touched, so nothing is lost by skipping the safety net on AJAX requests. See [issue #1](https://github.com/Braska-botmaker/woocomerce-geo-lang-currency/issues/1).

### Changed

- `plugin-cz/` is no longer guaranteed byte-for-byte identical to the original snippet — this bug affected the live production deployment of that variant, so it received the same fix as the universal plugin. No other logic, naming, or comments were touched.

## [3.0.0] - Universal currency plugin + repo restructure

### Added

- Repository restructured into `plugin/` (universal, English, currency-only) and `plugin-cz/` (legacy, Czech, currency + language) variants.
- New **universal** plugin (`plugin/crx-geo-currency.php`): fully in English, and configurable for any country/currency combination through a single `crx_glc_config` filter instead of hardcoded values. Scope is intentionally currency-only.
- Currency mapping, default currency, cookie TTL, and the bot-detection pattern are all filterable — no code edits required for typical setups.
- Valid, importable Code Snippets JSON export for the universal plugin (`plugin/crx-geo-currency.code-snippets.json`).
- MIT `LICENSE`, `.gitignore`, and this `CHANGELOG.md`.
- Credited the original author, Matěj Horák, and noted that the original snippet was built for Crystalex (crystalexcz.com).

## [2.0.0] - Legacy CZ plugin
- Fix for currency not being applied correctly on a visitor's very first pageview (see `plugin-cz/`).

## [1.0.0] - Legacy CZ plugin
- Initial release: geolocation-based currency switching (CZ→CZK, else EUR) and browser-language-based language switching (cs/sk→cs, else en) for WooCommerce + WPML/WCML.
