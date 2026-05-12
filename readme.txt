=== Bushbreaks Maps ===
Contributors: bushbreaks
Tags: map, lodges, accommodation, pods, leaflet
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.7.8
License: GPLv2 or later

Display lodge accommodations from a Pods custom post type on a map, with search and a featured list.

== Description ==

Bushbreaks Maps adds a `[bushbreaks_map]` shortcode that renders:

* A search bar
* A list of featured accommodations
* An interactive Leaflet map showing every accommodation with coordinates

The plugin reads from a Pods custom post type (default slug `accommodation`) and pulls latitude/longitude from configurable post meta fields. No API key required — uses OpenStreetMap tiles by default.

== Installation ==

1. Upload the `bushbreaks-maps` directory to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins**.
3. Visit **Settings → Bushbreaks Maps** to configure the post type and field names.
4. Add `[bushbreaks_map]` to any page.

== Shortcode ==

`[bushbreaks_map height="600px"]`

== Changelog ==

= 0.7.8 =
* Re-cut release so the tagged commit contains the bumped version metadata; PUC can now detect the update.

= 0.7.7 =
* Sync plugin header and readme version metadata so the GitHub-based update checker correctly detects new releases.

= 0.7.5 =
* Initial release.
