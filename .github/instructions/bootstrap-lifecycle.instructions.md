---
applyTo: "commercebird-wallet-pass.php"
description: "Use when changing plugin bootstrap, activation/deactivation hooks, cron scheduling, or global lifecycle wiring"
---

# Bootstrap and Lifecycle Instructions

## Scope
- Applies to plugin entrypoint bootstrap and lifecycle behavior.
- Covers activation/deactivation hooks, cron setup/cleanup, and event-driven cache invalidation.

## Entrypoint Rules
- Keep `commercebird-wallet-pass.php` minimal and procedural:
  - ABSPATH guard
  - Composer autoload include
  - plugin bootstrap invocation
  - activation/deactivation hook registration
- Do not move core logic from classes into the root plugin file.

## Lifecycle Rules
- Register hooks in `Plugin::bootstrap()` and keep callbacks static where possible.
- For cron updates, preserve idempotent scheduling (`wp_next_scheduled`) and clean unscheduling on deactivation.
- Keep cleanup behavior focused on stale pass metadata and related media attachments.

## Reliability
- Favor early returns and null-safe checks around WP object/meta access.
- Avoid broad queries or behavior changes that can cause unnecessary regeneration work.
