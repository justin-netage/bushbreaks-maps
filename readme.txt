=== Bushbreaks Maps ===
Contributors: netage
Tags: map, lodges, accommodation, pods, leaflet
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.9.28
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

= 0.9.28 =
* The "Regenerate feed images" status now shows a running "X/Y images cropped" count (not just listings processed), so it's immediately visible whether the tool is finding and cropping anything at all.
* Adds opt-in diagnostic logging across the whole crop pipeline (raw field value read, URL-to-attachment resolution, and the crop itself) — enable with `define( 'BBM_FEED_DEBUG', true );` in wp-config.php and check the PHP error log.

= 0.9.27 =
* Fixes 0.9.26's raw-URL-to-attachment resolution still missing real attachments on sites where the stored URL's scheme/domain (e.g. www vs bare, http vs https) no longer matches the site's currently configured uploads URL — WordPress's own attachment_url_to_postid() requires an exact prefix match and silently fails otherwise. Now matches on the "wp-content/uploads/..." relative path directly, independent of scheme/domain.

= 0.9.26 =
* Fixes feed cropping being silently skipped for listings whose Pods image/gallery field stores a raw URL (e.g. a specific already-generated size) instead of an attachment reference — the requested feed size was never even consulted for those. The URL is now resolved back to its attachment where possible, so the 1200x1200 crop applies to it too; falls back to the stored URL unchanged only when it isn't a local attachment.

= 0.9.25 =
* Feed images smaller than 1200px get a mild GD-based sharpen after upscaling (no Imagick required), to offset the softness from stretching a small source up to the feed's crop size.
* New "Images below Meta's recommended size (500x500)" list under Settings → Tools, so you can see exactly which lodges need a better source photo — upscaling can't recover detail that isn't there.

= 0.9.24 =
* Fixes a feed timeout introduced in 0.9.23: cropping the new feed image size no longer happens inline during the feed request (a request can touch hundreds of images). Feed image resolution now falls back to an existing size until an image has been cropped. New "Regenerate feed images" tool under Settings → Tools pre-crops the whole catalog in small batches via AJAX, mirroring the existing coordinate-backfill tool.

= 0.9.23 =
* Feed images (main + gallery) are now emitted at a single hard-cropped 1200x1200 size instead of the raw original, so lodges with differently-sized/shaped photos no longer get cropped inconsistently by Meta's catalog and dynamic-ad placements.

= 0.9.22 =
* New "Gallery field (additional images)" setting on the Facebook feed tab (default "gallery"). Up to 10 images from the configured Pods gallery/multi-image field are emitted as additional_image_link per item in the Products feed, excluding the main image. Leave empty to omit.

= 0.9.21 =
* Products feed now emits a unique item_group_id per item (equal to the item id) so Meta's automatic item grouping can't merge similarly-named lodges into variants of one product, and identifier_exists=no so Meta doesn't flag lodges for missing GTIN/MPN identifiers.
* Feed custom labels are now plain text: HTML markup stored in the features field (e.g. <ul>/<li> lists) is stripped, and every custom_label value is capped at Meta's 100-character limit (cut on a word boundary).

= 0.9.20 =
* New "Break type field" setting on the Facebook feed tab (default "break_type"). The configured Pods field or taxonomy is emitted as custom_label_4 in the Products feed, comma-separated, for ad-set targeting on the type of break (e.g. "Bush Break", "Beach Break"). Leave empty to omit.

= 0.9.19 =
* Each feed now includes an XML comment with the generating plugin version (e.g. <!-- Bushbreaks Maps 0.9.19 -->), so you can confirm at a glance whether a plugin update and cache purge have taken effect.

= 0.9.18 =
* Destination taxonomy is now read as a three-level hierarchy Country > Province > Reserve. The address region and Hotels/Destinations neighborhood, and the Products feed custom_label_0 (province) and custom_label_1 (reserve), all map by level. The address country prefers the taxonomy's top-level term, falling back to the Country setting.

= 0.9.17 =
* Products feed custom labels remapped: custom_label_0 is now the location details (address/location text), custom_label_1 the reserve, and custom_label_2 the categories. New custom_label_3 for the lodge features / unique selling points (configurable field, default "unique_selling_points").
* Products feed product_type is now a fixed value set on the Facebook feed tab (default "Holiday Destinations") instead of one product_type per category. Categories remain available in custom_label_2.
* New "Feed title" setting (default "BushBreaks") used as the title in every feed, and a "Description field" setting so the item description across all feeds comes from a chosen Pods field (e.g. "About Lodge"), falling back to the excerpt then post content.

= 0.9.16 =
* New Products catalog feed at /bushbreaks-feed/products.xml (RSS 2.0 + Google product namespace), alongside the Hotels and Destinations feeds. Each lodge's categories are emitted as product_type, and province, reserve and categories as custom_label_0/1/2 for ad-set targeting. All three feed URLs are listed on the Facebook feed settings tab.

= 0.9.15 =
* New Meta Destinations catalog feed at /bushbreaks-feed/destinations.xml (alongside the Hotels feed). Each lodge appears in both feeds; the Destinations feed uses destination_id, name, address, latitude/longitude, price, url and image. Both URLs are listed on the Facebook feed settings tab.

= 0.9.14 =
* The Facebook feed now outputs Meta's Hotel catalog format (a <listings>/<listing> document with hotel_id, name, structured address, latitude/longitude, base_price, url and image) instead of the generic product catalog, so it can be used as a Hotels catalog / hotel ads data source. New settings: Country, optional City field and optional Star rating field. Region and neighborhood are derived from the destination taxonomy. Listings without coordinates, an image, or a price are skipped. The feed URL is unchanged.

= 0.9.13 =
* New Facebook feed settings: Availability (in stock, out of stock, etc.), Condition (new/used/refurbished), an optional Google product category applied to every item, and a "Region as product type" toggle that maps each listing's destination taxonomy term (with its parent path) to g:product_type. Brand and currency were already configurable.

= 0.9.12 =
* Fix: the Facebook feed URL (/bushbreaks-feed/facebook.xml) was 301-redirected by WordPress to a trailing-slash variant, which crawlers like Facebook's then saw as a 404 / "format isn't supported". The feed now cancels the canonical trailing-slash redirect and the rewrite rule also accepts an optional trailing slash. After updating, re-save Permalinks once if the feed still redirects.
* Fix: the Facebook feed could fail to load with "XML declaration allowed only at the start of the document" when another plugin or the theme emitted stray whitespace before the feed rendered. The feed now discards any pending output so the XML declaration is always the first byte.

= 0.9.10 =
* New "Facebook feed" settings tab with a Facebook / Meta product catalog feed built from your listings. The feed is RSS 2.0 with the Google product namespace, so the same URL works for Meta Commerce Manager, Google Merchant Center and Pinterest catalogues. Copy the feed URL into Commerce Manager as a "Scheduled feed".
* Feed URL is /bushbreaks-feed/facebook.xml with pretty permalinks (falls back to ?bbm_feed=facebook otherwise). Configure the ISO currency code (default ZAR) and an optional brand. Listings missing an image or a price are skipped so Facebook doesn't reject the feed.

= 0.9.9 =
* New "Featured accommodations" picker in Settings → Bushbreaks Maps → General. Search and pick any number of lodges to appear at the top of the default list (before any search or filter). Drag the rows to reorder; remaining slots fill with the rest of the accommodations alphabetically.
* Search suggestions dropdown now includes lodge titles too, ranked beneath regions and categories. Typing a lodge name surfaces a suggestion even if no place/region matches it.

= 0.9.8 =
* Fix: search + filter chips no longer revert the map to every pin when their intersection is empty. With active chips, a search that returns zero results now keeps the map empty (matching the sidebar's "no matches" state) instead of looking like the search and filters were combined as a union. With no chips active, the existing "show all pins on zero results" behaviour stays.

= 0.9.7 =
* New "List heading label" setting in Settings → Bushbreaks Maps → General. Override the front-end "Lodges" heading per install (e.g. "Accommodations").
* Reorganised the settings page into tabs (General, Field mapping, Pricing, Filters, Map & Theme, Tools) so the save button is reachable from any group without scrolling past unrelated rows.

= 0.9.6 =
* New "Primary color" setting in Settings → Bushbreaks Maps. Pick any hex colour and the chips, special-price text, discount pill, "View details" link, loader spinner, suggestion hover, "+N more" pill, card hover border and checkbox accent all rebrand to it. Darker/softer shades for text and tinted backgrounds are derived automatically from the chosen colour.

= 0.9.5 =
* Clicking a search suggestion now closes the dropdown for good. Previously the dropdown re-opened because runSearch re-ran the suggestion match against the just-picked term; the suggestion-click path now skips that re-render.

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
