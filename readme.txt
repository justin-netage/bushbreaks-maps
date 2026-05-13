=== Bushbreaks Maps ===
Contributors: netage
Tags: map, lodges, accommodation, pods, leaflet
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.8.8
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

= 0.8.8 =
* Tightened the suggestion dropdown footer so the sticky Close button sits flush against the bottom edge — no more gap revealing list items behind it when scrolling.

= 0.8.7 =
* Checking a parent destination now selects and expands all of its children; unchecking it deselects them and collapses the subtree.

= 0.8.6 =
* Sticky Close button: stays pinned to the bottom-right corner of the suggestion dropdown while scrolling through long lists.

= 0.8.5 =
* Reset theme-applied margins on the search input so the suggestion dropdown sits truly flush against it (some block themes add margin-bottom to form fields).

= 0.8.4 =
* Dropdown attaches flush under the search input (no gap), suppresses horizontal scroll, and adds a Close action in the bottom right corner. Hover color realigned to the site's brand green (#8AD000).

= 0.8.3 =
* Suggestions render as a dropdown panel under the search input (no longer pushes the layout down). Closes on Escape or click outside; reopens on focus.

= 0.8.2 =
* Faster server-side search: drops post_content from the search haystack so each query no longer strips and lowercases every lodge's full body copy. Search now matches on title, location field, and destination/category names only.
* Live suggestion chips under the search box: as you type, surfaces matching destination and category names (prefix > substring > fuzzy). Lodge titles are intentionally excluded so suggestions bias toward places. Clicking a chip runs the search.

= 0.8.1 =
* Typo-tolerant search: server-side Levenshtein fallback returns near-matches when an exact substring search finds nothing (e.g. "Pilanesbrug" -> "Pilanesberg"). Client also surfaces a "Did you mean?" suggestion below the search box for likely typos.

= 0.8.0 =
* Tokenized, case-insensitive search across title, content, location text, and destination term names. Multi-word queries match when each word appears in any searchable field; partial words match too (e.g. "krug" finds "Kruger").

= 0.7.9 =
* Change plugin author to Net Age.

= 0.7.8 =
* Re-cut release so the tagged commit contains the bumped version metadata; PUC can now detect the update.

= 0.7.7 =
* Sync plugin header and readme version metadata so the GitHub-based update checker correctly detects new releases.

= 0.7.5 =
* Initial release.
