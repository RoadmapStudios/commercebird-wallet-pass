---
applyTo: "includes/Plugin.php"
description: "Use when changing lifecycle hooks, cron scheduling, cleanup of expired passes, or event-save cache invalidation"
---

# Plugin Lifecycle Instructions

## Scope
- Applies to plugin-level runtime orchestration in `Plugin`.
- Covers registration of hooks, cron scheduling, cleanup jobs, and event-save invalidation.

## Requirements
- Keep registration centralized in `Plugin::bootstrap()`.
- Keep cron hook names as constants and avoid string duplication.
- Preserve idempotent scheduling and explicit unscheduling on deactivation.
- Use early returns when no work is required.

## Data and Cleanup Behavior
- Cleanup should remove stale pass URL meta and linked pass attachments only when ticket/event is expired or invalid.
- Event save invalidation should target only related tickets.
- Avoid broad invalidations unless explicitly requested.

## Compatibility and Style
- Preserve strict typing and existing static method signatures unless required by task scope.
- Keep WordPress query usage conservative to minimize unnecessary load.
