---
applyTo: "includes/Api.php"
description: "Use when changing wallet pass generation, cache behavior, proxy downloads, or Tickera/WooCommerce order hooks"
---

# API Integration Instructions

## Scope
- Applies to wallet pass API generation and rendering behavior in `Api`.
- Covers order hooks, email wallet links, cache invalidation, and proxy download logic.

## Requirements
- Keep existing hook behavior intact unless the task explicitly requires changing hook timing or callback signatures.
- Preserve queue-based rendering flow for Tickera callable-array field rendering.
- Prefer cached pass URLs first; regenerate only when cache is missing/invalidated.
- Keep signed proxy URL validation in place (`pass` + HMAC `sig`) for unauthenticated access.
- Continue using WordPress HTTP APIs (`wp_remote_get`, `wp_remote_retrieve_*`) and return early on error states.

## Safety and Compatibility
- Sanitize all request input before use.
- Escape every rendered URL, attribute, and text string.
- Keep behavior compatible with both WooCommerce thank-you and My Account order contexts.
- Do not hard-code environment-specific endpoints; keep connector endpoint constants centralized.

## Implementation Notes
- Use `Api::PASS_URL_META_KEY` for pass URL metadata operations.
- Keep new helpers private unless they must be shared externally.
- If changing payload fields sent to CommerceBird connector, maintain backward compatibility and null-safe defaults.
