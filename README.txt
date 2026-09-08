=== Sneerly Coherent Random Post ===
Contributors: edequalsawesome
Donate link: https://edequalsaweso.me/
Tags: random, posts, redirect, block, button
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2026.08.001
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Redirects ?random URLs to a random post and adds a Random Post Button block for the editor.

== Description ==

Sneerly Coherent Random Post gives your visitors a "surprise me" button.

Two ways to use it:

*   **URL parameter** — add `?random` to any frontend URL on your site and the visitor is redirected to a random published post.
*   **Random Post Button block** — insert the "Random Post Button" block in the editor for a customizable button (colors, border radius, font size, width) that links to a random post, or to a custom URL if you prefer.

Features:

*   Remembers recently shown posts per visitor (configurable history size, 1-100) so people don't land on the same post twice in a row.
*   Choose which public post types are included in the random selection.
*   Plays nicely with caching plugins: the redirect request sets the standard `DONOTCACHEPAGE`/`DONOTCACHEOBJECT`/`DONOTCACHEDB` constants and sends no-cache headers, while destination pages stay fully cacheable.

== Installation ==

1. Upload the `sneerly-coherent-random-url` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Configure history size and post types under Settings → Sneerly Coherent Random
1. Add `?random` to any frontend URL, or insert the "Random Post Button" block in your content

== Frequently Asked Questions ==

= Does the same visitor see repeats? =

Not until they've cycled through the configured history size (default 10 posts). History is remembered per logged-in user, or per hashed IP address for anonymous visitors, for one hour.

= Which post types are included? =

Just posts by default. Any public post type can be enabled on the settings page.

= What happens if there are no published posts? =

The `?random` request does nothing and the page loads normally.

== Screenshots ==

1. The Random Post Button block in the editor
2. The settings page under Settings → Sneerly Coherent Random

== Development ==

Building requires Node. Releasing additionally requires [WP-CLI](https://wp-cli.org/) on your PATH, because the i18n steps shell out to `wp`:

* `npm run build` — compile the block assets. Node only.
* `npm run i18n:pot` — regenerate the translation template. Needs WP-CLI.
* `npm run i18n:json` — build editor translation JSON from any .po files. Needs WP-CLI.
* `npm run zip` — runs `i18n:json`, then packages the plugin. Needs WP-CLI.

`npm run zip` will fail with `wp: command not found` if WP-CLI is missing. Contributors who only need to build assets can use `npm run build` and never touch WP-CLI.

`i18n:json` passes `--use-map` so strings extracted from `src/index.js` are emitted under the hash of `build/index.js`, which is the filename WordPress actually looks up. The argument is single-quoted deliberately: npm runs scripts through `sh`, which would otherwise strip the inner quotes and hand WP-CLI invalid JSON.

== Changelog ==

= Unreleased =
* Fixed: random redirects wait for late-registered post types and leave destination URLs cacheable
* Improved: random redirects leave the request alone when no eligible post exists, its permalink cannot be resolved, or a redirect filter cancels the redirect
* Changed: random redirects run on frontend requests only; REST and login requests no longer redirect

= 2026.08.001 =
* Fixed: translations could never load — the plugin header declared the text domain `sneer-campaign-random` while every translation call in PHP and JavaScript used `sneerly-coherent-random`
* Fixed: the plugin never called load_plugin_textdomain(), so bundled translations were not loaded even once the domain matched
* Fixed: block editor strings had no translation hand-off; wp_set_script_translations() is now wired to the editor script
* Fixed: the "Block editor assets not found" admin notice was never wrapped for translation
* Fixed: the generated right-to-left editor stylesheet was never served, because the style was not tagged for RTL replacement
* Added: a translation template at languages/sneerly-coherent-random.pot, shipped with the plugin
* Added: `npm run i18n:pot` and `npm run i18n:json` release steps; `npm run zip` now regenerates editor translation JSON before packaging

Note: this release makes translation *possible* — it does not ship any translations. No locale is translated until a .po/.mo is contributed.

Known limitation: several settings-page strings (History Size, the Usage and History sections, Clear History) are still not wrapped for translation and are absent from the .pot. That sweep is a follow-up.

= 2026.07.001 =
* Fixed: block validation error ("unexpected or invalid content") every time a post containing the Random Post Button was reopened in the editor
* Fixed: cache-compatibility code never ran (registered on a hook that fires before regular plugins load)
* Fixed: editor styles never loaded (looked for build/editor.css; the build outputs build/index.css)
* Fixed: "Clear History" button on the settings page did nothing; now works, with CSRF protection
* Fixed: random selection could repeat recently shown posts via its fallback path
* Improved: random post selection now runs one indexed query instead of up to eleven ORDER BY RAND() full-table sorts per click
* Improved: destination pages are no longer forced to skip caching — only the redirect request itself bypasses caches
* Improved: SQL now uses prepared statements; redirects use wp_safe_redirect(); query parameters are unslashed and type-checked
* Improved: redirect no longer fires in admin, AJAX, or cron contexts
* Improved: post types that are no longer registered are excluded from selection
* Improved: history size is clamped server-side to the documented 1-100 range
* Accessibility: contrast checker on button color settings, fieldset/legend grouping for post-type checkboxes, description properly associated with the History Size field
* Removed: dead setup-notice and activation scaffolding (built assets ship with the plugin)

= 2.1.1 =
* Previous release (version history before 2026.07.001 was untracked)

== Upgrade Notice ==

= 2026.08.001 =
Makes the plugin translatable. Translations could never load before this release. No translations ship with it yet, so English-language sites see no change.

= 2026.07.001 =
Fixes editor block validation errors, a broken Clear History button, and a serious query performance problem on large sites. Recommended for all users.
