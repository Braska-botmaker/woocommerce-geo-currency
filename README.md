# 🌍 WooCommerce Geo Lang Currency

> **CRX Geo Lang Currency** — WordPress snippet pro inteligentní přepínání měny a jazyka na základě geolokace a nastavení prohlížeče.

Automaticky přepíná **měnu podle geolokace IP** a **jazyk podle jazyka prohlížeče** na WooCommerce e-shopech s [WPML](https://wpml.org/) + [WooCommerce Multilingual (WCML)](https://woocommerce.com/products/woocommerce-multilingual/) pluginem.

---

## 📋 Obsah

- [✨ Co snippet dělá](#-co-snippet-dělá)
- [📦 Závislosti](#-závislosti)
- [⚙️ Jak to funguje — měna](#️-jak-to-funguje--měna)
- [🗣️ Jak to funguje — jazyk](#️-jak-to-funguje--jazyk)
- [🔧 Proč nestačí jen set_client_currency()](#-proč-nestačí-jen-set_client_currency)
- [📐 Struktura kódu](#-struktura-kódu)
- [🚀 Instalace](#-instalace)
- [⚙️ Konfigurace](#️-konfigurace)

---

## ✨ Co snippet dělá

| 🎯 Funkce | 📝 Chování |
|-----------|-----------|
| **Měna** | CZ IP → **CZK**, všechny ostatní země → **EUR**. Platí vždy, na každém requestu. |
| **Jazyk** | Při první návštěvě detekuje jazyk prohlížeče (cs/sk → česky, vše ostatní → anglicky). Po manuálním přepnutí přes WPML switcher se preference uloží do cookie a detekce se už nespustí. |

---

## 📦 Závislosti

- ✅ **WordPress** (4.0+)
- ✅ **WooCommerce** (pro WC_Geolocation)
- ✅ **WPML** + **WooCommerce Multilingual / WCML** (pro wcml_client_currency filter a ICL_LANGUAGE_CODE)
- ✅ **Plugin Code Snippets** (nebo jiný způsob vložení PHP kódu)

---

## ⚙️ Jak to funguje — měna

### ❌ Problém, který snippet řeší

WCML určuje měnu pro každý HTTP request tímto způsobem:

1. Při `init` hooku přečte měnu z cookies (`wcml_client_currency`, `woocommerce_current_currency`)
2. Pokud cookie neexistuje, použije výchozí měnu shopu (zpravidla CZK)
3. Zaregistruje cenové filtry (`woocommerce_product_get_price` apod.) jako PHP closure s uzavřenou hodnotou měny
4. Veškerá konverze cen probíhá přes tyto filtry

**Důsledek:** Pokud se měna změní po registraci filtrů, filtry ji nerespektují — mají měnu zachycenou v closure z kroku 3.

#### 🔴 Scénář první návštěvy (bez opravy)

1. Zahraničí zákazník přijde na web (žádné cookies)
2. `init`: WCML přečte cookies → žádné → nastaví CZK
3. WCML zaregistruje cenové filtry s `currency = 'CZK'` (closure)
4. `wp` hook: náš kód volá `set_client_currency('EUR')`
   - → aktualizuje property objektu, ALE closure v filtrech stále drží `'CZK'`
5. Stránka se zobrazí v EUR (set_client_currency to vizuálně přepne), ale...
6. Zákazník klikne "Přidat do košíku" (wc-ajax request)
7. WooCommerce načte cenu přes filtry → closure říká CZK → produkt za 600 CZK se přidá jako 600 (bez konverze)
8. **Košík zobrazí 600 EUR** ← 🐛 **BUG**

Na druhé a dalších návštěvách cookies existují (nastavené JS ve footeru), WCML přečte EUR z cookies, closure dostane správnou měnu → vše funguje.

### ✅ Řešení: wcml_client_currency filter

WCML před finálním commitnutím měny aplikuje filter `wcml_client_currency`. Pokud do tohoto filtru vstoupíme, ovlivníme měnu **před registrací cenových filtrů** — tedy ve správný čas.

```php
add_filter( 'wcml_client_currency', function( $currency ) {
    if ( is_admin() ) return $currency;
    if ( crx_is_bot_request() ) return $currency;

    $country = crx_geo_country();
    if ( $country === '' ) return $currency;

    return crx_geo_target_currency( $country );
}, 20 );
```

Tento filter:

- ✅ Funguje pro všechny typy requestů — běžné stránky i wc-ajax add-to-cart
- ✅ Funguje při první návštěvě bez cookies
- ✅ Nepotřebuje cookies — rozhoduje čistě podle IP

`add_action('wp', ...)` zůstává jako záloha a zajišťuje nastavení JS cookies přes `wp_footer`, aby měly cookies správnou hodnotu pro případy, kdy WCML filter z nějakého důvodu neběží.

#### 🟢 Tok měny po opravě

1. Zahraničí zákazník přijde na web (žádné cookies)
2. `init`: WCML volá `get_client_currency()`
   - → aplikuje `wcml_client_currency` filter
   - → náš filter vrátí `'EUR'` podle geo IP
   - → WCML zaregistruje cenové filtry s `currency = 'EUR'` ✓
3. `wp_footer`: JS nastaví cookies `wcml_client_currency=EUR`
4. Zákazník klikne "Přidat do košíku"
5. WooCommerce načte cenu → filtry konvertují CZK→EUR ✓
6. Košík zobrazí správnou EUR cenu ✓

---

## 🗣️ Jak to funguje — jazyk
## 🗣️ Jak to funguje — jazyk

Detekce jazyka běží **výhradně v JavaScriptu** ve `wp_footer` (priority 20), protože k `navigator.languages` má přístup jen prohlížeč.

### 📊 Logika

1. Přečti cookie `crx_manual_lang`
2. Pokud cookie **NEEXISTUJE** → první návštěva:
   - Projdi `navigator.languages`
   - `cs-*` nebo `sk-*` → targetLang = `'cs'`, jinak `'en'`
   - Ulož do cookie
   - Pokud currentLang ≠ targetLang → přesměruj
3. Pokud cookie **EXISTUJE**:
   - Aktualizuj cookie na aktuální jazyk stránky (tím zachytíme manuální přepnutí přes WPML switcher)
   - Nic dalšího — uživatel je na správném jazyce nebo vědomě přepnul

### 🔄 Detekce manuálního přepnutí

WPML při přepnutí jazyka načte stránku ve zvoleném jazyce. Snippet tuto situaci pozná: 
- Cookie obsahuje starý jazyk
- Stránka se načetla v novém

Snippet cookie aktualizuje na nový jazyk a detekci znovu nespustí.

---

## 🔧 Proč nestačí jen set_client_currency()

Podrobné vysvětlení naleznete výše v kapitole [⚙️ Jak to funguje — měna](#️-jak-to-funguje--měna). Krátce: `set_client_currency()` se volá příliš pozdě, takže price filtry již mají uzavřenu starou měnu.

---

## 📐 Struktura kódu

```
defined('ABSPATH') || exit          ← bezpečnostní guard

CONFIG
├── crx_geo_target_currency()       ← CZ→CZK, ostatní→EUR
└── crx_geo_cookie_ttl()            ← 30 dní

HELPERS
├── crx_is_bot_request()            ← detekce botů/crawlerů
├── crx_is_front_get()              ← jen frontend GET requesty
├── crx_geo_country()               ← WC_Geolocation, static cache
├── crx_get_cookie()                ← wrapper pro $_COOKIE
└── crx_get_current_currency_from_cookies()

JS COOKIE HELPERS
├── crx_output_js_cookie_helpers()  ← vypíše crxSetCookie/crxGetCookie
│                                      (static guard, tiskne jen jednou)
├── crx_set_wcml_runtime_currency() ← PHP: volá WCML set_client_currency()
└── crx_force_geo_currency()        ← PHP: set + naplánuje JS cookies
                                       do wp_footer (priority 5)

HOOKS
├── add_filter('wcml_client_currency', ..., 20)   ← PRIMÁRNÍ FIX měny
├── add_action('wp', ..., 10)                     ← záloha + JS cookies
└── add_action('wp_footer', ..., 20)              ← detekce jazyka JS
```

### 🎯 Priorita wp_footer hooků

| Priorita | 📝 Co dělá |
|----------|-----------|
| **5** | `crx_force_geo_currency` → vypíše JS na nastavení měnových cookies |
| **20** | Detekce jazyka → čte `crxGetCookie` (musí být až po priority 5) |

---

## 🚀 Instalace

### 📥 Přes plugin Code Snippets

1. WordPress admin → **Code Snippets** → **Add New**
2. Vložte celý PHP kód (bez `<?php` tagu)
3. **Scope:** Run everywhere
4. Uložte a aktivujte

### 📤 Přes import JSON souboru

1. WordPress admin → **Code Snippets** → **Import**
2. Nahrajte `.json` soubor (`crx-geo-lang-currency-clone.code-snippets.json`)
3. Aktivujte snippet

---

## ⚙️ Konfigurace

Vše se nastavuje ve **dvou funkcích** na začátku snippetu:

### 🌍 Mapování měn podle zemí

```php
// Která měna pro kterou zemi
function crx_geo_target_currency( string $country ): string {
    return ( $country === 'CZ' ) ? 'CZK' : 'EUR';
}
```

### 🍪 Platnost cookies

```php
// Platnost cookies (výchozí: 30 dní)
function crx_geo_cookie_ttl(): int {
    return DAY_IN_SECONDS * 30;
}
```

### 📝 Příklad: Přidání další měny

Pokud chcete přidat další měnu (např. GBP pro Velkou Británii):

```php
function crx_geo_target_currency( string $country ): string {
    return match( $country ) {
        'CZ'    => 'CZK',
        'GB'    => 'GBP',
        default => 'EUR',
    };
}
```

---

## 💡 Tipy & Triky

- **Testování**: Použijte DevTools k inspekciji cookies `wcml_client_currency` a `crx_manual_lang`
- **Debug**: Zkontrolujte browser console a Network tab pro JS chyby
- **Performance**: Snippet používá static cache pro `crx_geo_country()` — je efektivní i na vysoké návštěvnosti

---

## 📄 Licence

Volně používejte a modifikujte dle potřeb.