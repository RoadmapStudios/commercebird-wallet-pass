# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`commercebird-wallet-pass`) that adds Apple Wallet and Google Wallet passes to **Tickera** event tickets sold through WooCommerce. It's an add-on to the **CommerceBird** plugin (`Requires Plugins: commercebird`). The passes are generated remotely by the CommerceBird API; this plugin doesn't sign or build `.pkpass` files itself.

Requires PHP >= 8.2 and WordPress >= 7.0. The code is namespaced `CommerceBird\WalletPass`, which Composer's PSR-4 autoloader maps to `includes/`.

More detailed, file-scoped rules live in `.github/copilot-instructions.md` and `.github/instructions/*.instructions.md` (one each for `Admin.php`, `Api.php`, `Plugin.php` and the bootstrap file). Follow them.

## Commands

```bash
composer install
composer run phpcs        # WordPress-Extra + WordPress-Docs via phpcs.xml
composer run phpcbf       # auto-fix phpcs issues
composer run phpstan      # level 1, includes/ only (phpstan.neon -> phpstan.neon.dist)
composer run analyse      # phpstan + phpcs
vendor/bin/phpcs includes/Api.php   # lint a single file
```

CI (`.github/workflows/static-analysis.yml`) runs `php -l` and PHPStan with `phpstan-ci.neon.dist` on PHP 8.2/8.3. PHPCS is disabled in CI. There's no test suite.

Packaging: `./build-mac.sh` / `./build-ubuntu.sh` / `./build-windows.sh` rsync the repo into `build-zip/<slug>/`, skipping everything listed in `.distignore`. The Ubuntu script, which the deploy workflow uses, also runs `composer install --no-dev`, adds `index.php` silence files, and writes a `vendor/.htaccess`. Pushing a tag deploys to WordPress.org SVN (`push-to-deploy.yml`). When you bump the version, update both the `commercebird-wallet-pass.php` header and `readme.txt`.

## Architecture

- **`commercebird-wallet-pass.php`**: a minimal procedural entry point. It loads the autoloader, calls `Plugin::bootstrap()`, registers the activation/deactivation hooks (pkpass mime type and cron scheduling), and adds the `pkpass` mime-type filter. Keep logic out of this file.
- **`Plugin`**: orchestrates the plugin. `bootstrap()` calls `Admin::register()` and `Api::register()`. It also:
  - shows a notice when Tickera is missing (it detects either `Tickera\TC` or `TC`)
  - invalidates the cached pass URLs for an event's tickets on `save_post_tc_events`
  - runs a daily cron (`cmbird_wallet_cleanup_expired_passes`) that deletes stale pass meta and pass attachments for expired tickets
- **`Admin`**: adds a "Wallet" tab to Tickera settings through the `tc_settings_new_menus` and `tc_settings_menu_wallet` hooks. Settings are stored in the `cmbird_apple_wallet_settings` option, and `getSettings()` returns them with defaults filled in. Saving settings invalidates *all* cached passes. The admin page also warns when no CommerceBird application password exists.
- **`Api`**: generates and renders the passes.
  - Pass URLs are cached in ticket post meta: `_tc_wallet_pass_url` for Apple and `_tc_wallet_google_pass_url` for Google. The cache check only looks at the Apple URL.
  - Generation calls `CommerceBird\Admin\Connectors\Connector::request('customs/wallet/pass', 'POST', $payload)`, a class that comes from the CommerceBird plugin. The WooCommerce order id is sent so that tickets from the same order stack together in Google Wallet.
  - **Generation happens only on click.** No page render or email calls the API. The processing email is sent during WooCommerce's payment handling, so an API call there breaks checkout. Every badge links to the signed proxy, and the proxy generates the pass on the first click, but only for a paid order (the order is the ticket's `post_parent`).
  - **Queue quirk:** Tickera passes the `field_name` string to callable-array column callbacks, not the meta value. So `queueTicketsForOrder`, hooked to `woocommerce_thankyou` at priority 1 and to `woocommerce_account_view_order`, pushes every ticket id into a static `$ticket_queue`. `renderWalletButton` takes one id per row. Keep this ordering and hook priority intact.
  - Both badges go through a signed proxy on the front end, not under `/wp-admin/` (firewalls often block that path). The link is `home_url('/?commercebird_wallet_pass=1&ticket=<id>&sig=…')`, plus `&wallet=google` for Google. `sig` is HMAC-SHA256 of `ticket:<id>` with `wp_salt('auth')`. `maybeServeWalletPassProxy` catches the request on `parse_request`. For Apple it re-serves the `.pkpass` file with the headers iOS requires. For Google, and for any request from an Android device, it redirects to the Google save link, because Android shows a `.pkpass` as a blank page. Errors end with a `wp_die()` message, never an empty response. When no ticket id is known, the link carries a signed `pass` URL instead. The old `admin-post.php?action=commercebird_wallet_pass` handlers stay registered so links in already-sent emails keep working.
  - Pages show one badge per ticket: Google on Android, Apple everywhere else. Order emails get both badges through `woocommerce_email_after_order_table`. The Google badge is hidden once a generated pass came back without a Google URL.
  - Errors are logged with `wc_get_logger()` under source `tickera-wallet-pass`.

## Conventions

- Namespaced files use `declare(strict_types=1);` and an ABSPATH guard, and each is a `final class` with static `register()`/`bootstrap()` methods. Hook callbacks are written as `array( self::class, 'method' )`.
- Indent with tabs and use Yoda conditions. Escape and sanitize with WordPress functions, and check capability and nonce on admin POST handlers. The text domain is `commercebird-wallet-pass`.
- Any change that affects generated pass content must invalidate the cached pass URLs, using `Api::invalidatePassCache()` or `Admin::invalidateAllPassCaches()`.
- Don't edit `vendor/`. `fix-inline-comments.php` and `fix-yoda-directories.php` are one-off codemod scripts, not runtime code.
