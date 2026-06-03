---
applyTo: "includes/Admin.php"
description: "Use when changing Tickera wallet settings UI, option persistence, admin assets, or settings-page cache invalidation"
---

# Admin Settings Instructions

## Scope
- Applies to Tickera settings menu integration and wallet settings page rendering.
- Covers saving options, media/color picker usage, and admin script enqueueing.

## Requirements
- Keep capability and nonce checks before processing POST data.
- Continue sanitizing each saved field with WordPress sanitizers.
- Preserve `Admin::OPTION_KEY` option structure and default shape returned by `getSettings()`.
- Keep wallet settings page detection conservative to avoid loading assets outside relevant screens.

## UI and i18n
- Keep translatable strings in the `commercebird-wallet-pass` text domain.
- Escape all HTML output in settings forms.
- Maintain compatibility with WordPress script loading strategy differences (pre/post 6.3 behavior).

## Cache Invalidation
- Any setting that can affect generated passes must trigger pass cache invalidation.
- Use existing invalidation patterns (`invalidateAllPassCaches`) instead of ad-hoc cleanup loops.
