# Copilot Instructions for commercebird-wallet-pass

## Project Context
- This repository is a WordPress plugin: CommerceBird Wallet Pass for Tickera.
- Main plugin bootstrap file is `commercebird-wallet-pass.php`.
- Primary PHP code lives in `includes/` under namespace `CommerceBird\\WalletPass`.
- Autoloading is PSR-4 via Composer: `CommerceBird\\WalletPass\\` -> `includes/`.

## Tech and Runtime Expectations
- Target runtime in `composer.json` is PHP >= 8.2.
- WordPress plugin context is required for most runtime behavior.
- Integrations include Tickera, WooCommerce, and CommerceBird connector classes.

## Coding Standards (Must Follow)
- Follow WordPress coding standards defined in `phpcs.xml`.
- Use tabs for indentation in PHP files (not spaces).
- Keep/enforce Yoda conditions for comparisons.
- Use WordPress escaping/sanitization functions for output and input.
- Keep strict types in namespaced PHP files when present: `declare(strict_types=1);`.
- Match existing naming and structure:
  - `final class` for service-style classes in `includes/`.
  - Static registration methods like `register()` / `bootstrap()`.
  - WordPress hooks should use callable arrays with `self::class` when inside classes.

## Architectural Conventions
- Put new plugin behavior in focused classes under `includes/`, not in the root plugin file.
- Keep `commercebird-wallet-pass.php` minimal: load autoloader, bootstrap plugin, activation/deactivation hooks.
- For settings/admin UI changes, use `includes/Admin.php` conventions.
- For wallet pass generation/API behavior, follow patterns in `includes/Api.php`.
- For lifecycle/cron/cache invalidation behavior, follow patterns in `includes/Plugin.php`.

## WordPress Safety and Compatibility
- Guard direct file access with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Check capability and nonce before processing admin POST requests.
- Prefer core WP functions (`get_post_meta`, `update_option`, `wp_remote_get`, etc.) over raw PHP alternatives in plugin runtime code.
- Keep text domain as `commercebird-wallet-pass` for translatable strings.

## What to Avoid
- Do not introduce framework patterns that conflict with current plugin style.
- Do not add unrelated refactors outside the requested task.
- Do not edit `vendor/` manually.
- Do not commit local-only config changes unless explicitly requested.

## Validation Workflow
When code changes are made, run the relevant Composer scripts:

```bash
composer run phpcs
composer run phpstan
```

Use these before considering work complete (unless the user asks not to run checks).

## Build and Packaging Notes
- Windows build helper exists at `build-windows.sh`.
- Production packaging excludes development-only content via `.distignore` and installs `--no-dev` dependencies in build output.

## Response Style for This Repo
- Prefer minimal, targeted diffs that preserve existing style and behavior.
- Reference concrete files and hooks being changed.
- Call out compatibility impacts for WordPress, WooCommerce, and Tickera when relevant.
