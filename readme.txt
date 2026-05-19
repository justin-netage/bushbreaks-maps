=== Bushbreaks Maps ===
Contributors: netage
Tags: map, lodges, accommodation, pods, leaflet
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.9.4
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

= 0.9.4 =
* Fix: the "Region filter" toggle now actually hides the front-end region/reserve dropdown. The previous fix used a boolean flag that wp_localize_script silently cast to an empty string, so the JS guard never triggered. Sent as `'yes'`/`'no'` strings now.

= 0.9.3 =
* Suggestions dropdown now keeps populating destination names even when the "Region filter" toggle is off — the region/reserve list still seeds the suggestion pool, just the front-end dropdown stays hidden.
* Moved the "Accommodations missing coordinates" panel directly below the Backfill section so the two coordinate workflows live together.

= 0.9.2 =
* New "Region filter" toggle in Settings → Bushbreaks Maps. Uncheck to hide the front-end region/reserve dropdown without removing the taxonomy or term ordering data.

= 0.9.1 =
* Removing a chip from inside the "+N more" popover no longer closes it — the popover stays open with the remaining items until none are left.
* Renamed the destination filter label from "Filter by Region…" to "Filter by Region or Reserve…" (and the corresponding remove aria-label).

= 0.9.0 =
* Selected-filter chips truncate when there are many: the first 3 show inline, with a "+N more" pill that opens a popover containing the rest. The popover is bounded (max 200px) and scrollable, so the layout never gets pushed down by a long selection list. Chip removal works the same in both places.

= 0.8.9 =
* Parent destination check no longer auto-selects its children. Checking a parent only selects the parent and expands the subtree so the user can pick individual children; unchecking it collapses the subtree without altering child selections.

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
