(function () {
	'use strict';

	if (typeof window.BushbreaksMapsData === 'undefined') {
		return;
	}

	var data = window.BushbreaksMapsData;
	var isGoogle = data.provider === 'google';

	if (isGoogle) {
		// Google Maps API calls this once it has loaded.
		window.BushbreaksMapsBoot = function () {
			whenDom(initEverything);
		};
	} else {
		whenDom(function () {
			if (typeof window.L === 'undefined') return;
			initEverything();
		});
	}

	function whenDom(cb) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', cb);
		} else {
			cb();
		}
	}

	function initEverything() {
		var mapEl = document.getElementById('bbm-map');
		if (!mapEl) return;

		var wrap = mapEl.closest('.bbm-wrap');
		var searchInput = wrap ? wrap.querySelector('.bbm-search-input') : null;
		var resultsEl = wrap ? wrap.querySelector('.bbm-results') : null;
		var resultsList = wrap ? wrap.querySelector('.bbm-results-list') : null;
		var listEl = wrap ? wrap.querySelector('.bbm-list') : null;
		var loaderEl = wrap ? wrap.querySelector('.bbm-loader') : null;
		var resultCountEl = wrap ? wrap.querySelector('.bbm-result-count') : null;

		// Map handles — populated by mapInit().
		var lmap = null;       // Leaflet map
		var gmap = null;       // Google map
		var ginfo = null;      // Google InfoWindow (shared)
		var lcluster = null;   // L.markerClusterGroup
		var gcluster = null;   // markerClusterer.MarkerClusterer

		var markersById = {};
		var allMarkers = [];

		var icons = data.icons || {};
		var markerCfg = icons.marker || {};
		var clusterCfg = icons.cluster || {};

		function setResultCount(n) {
			if (!resultCountEl) return;
			var count = parseInt(n, 10) || 0;
			var template = count === 1
				? (data.i18n.resultsCountSingle || '1 lodge')
				: (data.i18n.resultsCountPlural || '%d lodges');
			resultCountEl.textContent = template.replace('%d', count);
		}

		mapInit(mapEl);
		(data.locations || []).forEach(addMarker);
		setResultCount((data.locations || []).length);
		if (isGoogle && allMarkers.length > 0) {
			gcluster = new markerClusterer.MarkerClusterer(buildGoogleClusterOptions());
		}
		if (allMarkers.length > 0) fitMarkers(allMarkers, /*animate*/ false);

		function buildGoogleClusterOptions() {
			var opts = { map: gmap, markers: allMarkers };
			if (clusterCfg.url) {
				var size = clusterCfg.size || 48;
				opts.renderer = {
					render: function (cluster) {
						return new google.maps.Marker({
							position: cluster.position,
							icon: {
								url: clusterCfg.url,
								scaledSize: new google.maps.Size(size, size),
								anchor: new google.maps.Point(size / 2, size / 2),
							},
							label: {
								text: String(cluster.count),
								color: '#ffffff',
								fontSize: '13px',
								fontWeight: '700',
							},
							zIndex: 1000 + cluster.count,
						});
					},
				};
			}
			return opts;
		}

		bindCardInteractions(listEl);

		if (searchInput) {
			searchInput.addEventListener('input', function () {
				clearTimeout(searchTimer);
				searchTimer = setTimeout(function () { runSearch(searchInput.value); }, 250);
			});
		}

		// Category filter
		var categoriesEl = wrap ? wrap.querySelector('.bbm-categories') : null;
		var categoryToggle = wrap ? wrap.querySelector('.bbm-category-toggle') : null;
		var categoryToggleLabel = wrap ? wrap.querySelector('.bbm-category-toggle-label') : null;
		var categoryPanel = wrap ? wrap.querySelector('.bbm-category-panel') : null;
		var chipsEl = wrap ? wrap.querySelector('.bbm-category-chips') : null;
		var allCategories = data.categories || [];
		var selectedCategoryIds = [];

		if (categoriesEl && categoryToggle && categoryPanel && chipsEl && allCategories.length > 0) {
			categoriesEl.hidden = false;
			renderCategoryPanel();
			updateCategoryToggleLabel();

			categoryToggle.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				var willOpen = categoryPanel.hidden;
				categoryPanel.hidden = !willOpen;
				categoryToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
			});

			categoryPanel.addEventListener('change', function (e) {
				var cb = e.target.closest('input[type="checkbox"]');
				if (!cb) return;
				var id = parseInt(cb.value, 10);
				if (cb.checked) {
					if (selectedCategoryIds.indexOf(id) === -1) selectedCategoryIds.push(id);
				} else {
					selectedCategoryIds = selectedCategoryIds.filter(function (cid) { return cid !== id; });
				}
				renderChips();
				updateCategoryToggleLabel();
				runSearch(searchInput ? searchInput.value : '');
			});

			chipsEl.addEventListener('click', function (e) {
				var btn = e.target.closest('.bbm-chip-remove');
				if (!btn) return;
				var id = parseInt(btn.getAttribute('data-id'), 10);
				selectedCategoryIds = selectedCategoryIds.filter(function (cid) { return cid !== id; });
				var cb = categoryPanel.querySelector('input[type="checkbox"][value="' + id + '"]');
				if (cb) cb.checked = false;
				renderChips();
				updateCategoryToggleLabel();
				runSearch(searchInput ? searchInput.value : '');
			});

			document.addEventListener('click', function (e) {
				if (categoryPanel.hidden) return;
				if (!categoriesEl.contains(e.target)) {
					categoryPanel.hidden = true;
					categoryToggle.setAttribute('aria-expanded', 'false');
				}
			});
		}

		function renderCategoryPanel() {
			if (!categoryPanel) return;
			categoryPanel.innerHTML = '';
			allCategories.forEach(function (cat) {
				var label = document.createElement('label');
				label.className = 'bbm-category-option';
				var cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.value = String(cat.id);
				cb.checked = selectedCategoryIds.indexOf(cat.id) !== -1;
				var span = document.createElement('span');
				span.textContent = cat.name;
				label.appendChild(cb);
				label.appendChild(span);
				categoryPanel.appendChild(label);
			});
		}

		function updateCategoryToggleLabel() {
			if (!categoryToggleLabel) return;
			var n = selectedCategoryIds.length;
			var base = data.i18n.categoryPlaceholder || 'Filter by category…';
			categoryToggleLabel.textContent = n > 0 ? base + ' (' + n + ')' : base;
		}

		function renderChips() {
			if (!chipsEl) return;
			chipsEl.innerHTML = '';
			selectedCategoryIds.forEach(function (id) {
				var cat = allCategories.find(function (c) { return c.id === id; });
				if (!cat) return;
				var chip = document.createElement('span');
				chip.className = 'bbm-chip';
				chip.setAttribute('role', 'listitem');
				chip.innerHTML = escapeHtml(cat.name)
					+ ' <button type="button" class="bbm-chip-remove" aria-label="'
					+ escapeAttr(data.i18n.removeCategory || 'Remove')
					+ '" data-id="' + id + '">&times;</button>';
				chipsEl.appendChild(chip);
			});
		}

		// ---- map abstraction ---------------------------------------------------

		function mapInit(el) {
			if (isGoogle) {
				var coarsePointer = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
				gmap = new google.maps.Map(el, {
					center: { lat: data.center.lat, lng: data.center.lng },
					zoom: data.zoom,
					mapTypeControl: false,
					streetViewControl: false,
					fullscreenControl: true,
					gestureHandling: coarsePointer ? 'cooperative' : 'greedy',
				});
				ginfo = new google.maps.InfoWindow({ maxWidth: 280 });
			} else {
				lmap = L.map(el, { scrollWheelZoom: true })
					.setView([data.center.lat, data.center.lng], data.zoom);
				L.tileLayer(data.tile.url, {
					attribution: data.tile.attr,
					maxZoom: 19,
				}).addTo(lmap);
				var clusterOpts = {};
				if (clusterCfg.url) {
					var size = clusterCfg.size || 48;
					clusterOpts.iconCreateFunction = function (cluster) {
						return L.divIcon({
							html: '<div class="bbm-cluster-inner" style="background-image:url(' + JSON.stringify(clusterCfg.url) + ');width:' + size + 'px;height:' + size + 'px;line-height:' + size + 'px;">' + cluster.getChildCount() + '</div>',
							className: 'bbm-cluster-icon',
							iconSize: L.point(size, size),
						});
					};
				}
				lcluster = L.markerClusterGroup(clusterOpts);
				lmap.addLayer(lcluster);
			}
		}

		function addMarker(item) {
			if (item.lat == null || item.lng == null) return null;
			var html = popupHtml(item);
			var marker;

			if (isGoogle) {
				var gOpts = {
					position: { lat: parseFloat(item.lat), lng: parseFloat(item.lng) },
					title: item.title,
				};
				if (markerCfg.url) {
					gOpts.icon = {
						url: markerCfg.url,
						scaledSize: new google.maps.Size(markerCfg.width, markerCfg.height),
						anchor: new google.maps.Point(markerCfg.width / 2, markerCfg.height),
					};
				}
				marker = new google.maps.Marker(gOpts);
				marker._bbm_popup_html = html;
				marker.addListener('click', function () {
					ginfo.setContent(marker._bbm_popup_html);
					ginfo.open({ anchor: marker, map: gmap });
				});
			} else {
				var lOpts = { title: item.title };
				if (markerCfg.url) {
					lOpts.icon = L.icon({
						iconUrl: markerCfg.url,
						iconSize: [markerCfg.width, markerCfg.height],
						iconAnchor: [markerCfg.width / 2, markerCfg.height],
						popupAnchor: [0, -markerCfg.height + 4],
					});
				}
				marker = L.marker([item.lat, item.lng], lOpts);
				marker.bindPopup(html, { minWidth: 220, maxWidth: 280 });
				lcluster.addLayer(marker);
			}

			markersById[item.id] = marker;
			allMarkers.push(marker);
			return marker;
		}

		function showMarker(marker) {
			if (isGoogle) {
				if (gcluster) gcluster.addMarker(marker);
				else if (!marker.getMap()) marker.setMap(gmap);
			} else {
				if (lcluster && !lcluster.hasLayer(marker)) lcluster.addLayer(marker);
			}
		}

		function hideMarker(marker) {
			if (isGoogle) {
				if (gcluster) gcluster.removeMarker(marker);
				else if (marker.getMap()) marker.setMap(null);
			} else {
				if (lcluster && lcluster.hasLayer(marker)) lcluster.removeLayer(marker);
			}
		}

		function openMarkerPopup(marker) {
			if (isGoogle) {
				ginfo.setContent(marker._bbm_popup_html);
				ginfo.open({ anchor: marker, map: gmap });
			} else {
				marker.openPopup();
			}
		}

		function panToMarker(marker) {
			if (isGoogle) {
				gmap.panTo(marker.getPosition());
				if (gmap.getZoom() < 11) gmap.setZoom(11);
			} else {
				lmap.flyTo(marker.getLatLng(), Math.max(lmap.getZoom(), 11), { duration: 0.6 });
			}
		}

		function panToLatLng(lat, lng) {
			if (isGoogle) {
				gmap.panTo({ lat: lat, lng: lng });
				if (gmap.getZoom() < 11) gmap.setZoom(11);
			} else {
				lmap.flyTo([lat, lng], Math.max(lmap.getZoom(), 11), { duration: 0.6 });
			}
		}

		function fitMarkers(markers, animate) {
			if (!markers.length) return;
			if (markers.length === 1) {
				panToMarker(markers[0]);
				return;
			}
			if (isGoogle) {
				var bounds = new google.maps.LatLngBounds();
				markers.forEach(function (m) { bounds.extend(m.getPosition()); });
				gmap.fitBounds(bounds, 40);
			} else {
				var group = L.featureGroup(markers);
				if (animate === false) {
					lmap.fitBounds(group.getBounds().pad(0.15));
				} else {
					lmap.flyToBounds(group.getBounds().pad(0.15), { duration: 0.6 });
				}
			}
		}

		function fitLatLngs(pts, animate) {
			if (!pts.length) return;
			if (pts.length === 1) {
				panToLatLng(pts[0][0], pts[0][1]);
				return;
			}
			if (isGoogle) {
				var bounds = new google.maps.LatLngBounds();
				pts.forEach(function (p) { bounds.extend({ lat: p[0], lng: p[1] }); });
				gmap.fitBounds(bounds, 40);
			} else {
				if (animate === false) {
					lmap.fitBounds(L.latLngBounds(pts).pad(0.2));
				} else {
					lmap.flyToBounds(L.latLngBounds(pts).pad(0.2), { duration: 0.6 });
				}
			}
		}

		// ---- shared logic ------------------------------------------------------

		function showOnlyMarkers(ids) {
			var allow = {};
			(ids || []).forEach(function (id) { allow[String(id)] = true; });
			var keep = [];
			Object.keys(markersById).forEach(function (id) {
				if (allow[String(id)]) keep.push(markersById[id]);
			});

			if (isGoogle && gcluster) {
				gcluster.clearMarkers();
				gcluster.addMarkers(keep);
			} else {
				Object.keys(markersById).forEach(function (id) {
					var marker = markersById[id];
					if (allow[String(id)]) showMarker(marker);
					else hideMarker(marker);
				});
			}
		}

		function showAllMarkers() {
			if (isGoogle && gcluster) {
				gcluster.clearMarkers();
				gcluster.addMarkers(allMarkers);
				return;
			}
			Object.keys(markersById).forEach(function (id) {
				showMarker(markersById[id]);
			});
		}

		function fitToAllMarkers() {
			var visible = Object.keys(markersById).map(function (id) { return markersById[id]; });
			fitMarkers(visible, /*animate*/ true);
		}

		function focusItem(id, lat, lng) {
			var marker = markersById[id];
			if (marker) {
				if (isGoogle) {
					gmap.panTo(marker.getPosition());
					if (gmap.getZoom() < 13) gmap.setZoom(13);
					ginfo.setContent(marker._bbm_popup_html);
					ginfo.open({ anchor: marker, map: gmap });
				} else if (lcluster) {
					lcluster.zoomToShowLayer(marker, function () {
						marker.openPopup();
					});
				} else {
					panToMarker(marker);
					openMarkerPopup(marker);
				}
			} else if (lat != null && lng != null) {
				panToLatLng(parseFloat(lat), parseFloat(lng));
			}
		}

		function bindCardInteractions(root) {
			if (!root) return;
			root.querySelectorAll('.bbm-card').forEach(function (card) {
				card.addEventListener('click', function (e) {
					if (e.target.closest('.bbm-card-link')) return;
					var id = parseInt(card.getAttribute('data-id'), 10);
					var lat = card.getAttribute('data-lat');
					var lng = card.getAttribute('data-lng');
					root.querySelectorAll('.bbm-card.is-active').forEach(function (c) { c.classList.remove('is-active'); });
					card.classList.add('is-active');
					focusItem(id, lat, lng);
				});
			});
		}

		function renderResults(items) {
			if (!resultsList || !resultsEl) return;
			resultsList.innerHTML = '';
			if (!items.length) {
				var empty = document.createElement('li');
				empty.className = 'bbm-empty';
				empty.textContent = data.i18n.noResults;
				resultsList.appendChild(empty);
				return;
			}
			items.forEach(function (item) {
				var li = document.createElement('li');
				li.className = 'bbm-card';
				li.setAttribute('data-id', String(item.id));
				if (item.lat != null) li.setAttribute('data-lat', String(item.lat));
				if (item.lng != null) li.setAttribute('data-lng', String(item.lng));

				var html = '';
				if (item.thumbnail) {
					html += '<div class="bbm-card-thumb" style="background-image:url(\'' + escapeAttr(item.thumbnail) + '\')"></div>';
				}
				html += '<div class="bbm-card-body">';
				html += '<span class="bbm-card-title">' + escapeHtml(item.title) + '</span>';
				if (item.address) {
					html += '<div class="bbm-card-address">' + escapeHtml(item.address) + '</div>';
				}
				html += pricingHtml(item.pricing);
				if (item.excerpt) {
					html += '<p class="bbm-card-excerpt">' + escapeHtml(item.excerpt) + '</p>';
				}
				html += '<a class="bbm-card-link" href="' + escapeAttr(item.permalink) + '">' + escapeHtml(data.i18n.viewDetails) + ' &rarr;</a>';
				html += '</div>';
				li.innerHTML = html;
				resultsList.appendChild(li);
			});
			bindCardInteractions(resultsEl);
		}

		function showResults() {
			if (loaderEl) loaderEl.hidden = true;
			if (resultsEl) resultsEl.hidden = false;
			if (listEl) listEl.hidden = true;
		}

		function showList() {
			if (loaderEl) loaderEl.hidden = true;
			if (resultsEl) resultsEl.hidden = true;
			if (listEl) listEl.hidden = false;
		}

		function showLoader() {
			if (loaderEl) loaderEl.hidden = false;
			if (resultsEl) resultsEl.hidden = true;
			if (listEl) listEl.hidden = true;
		}

		var searchTimer = null;
		var lastReq = 0;

		function resetMapToAll() {
			showAllMarkers();
			fitToAllMarkers();
		}

		function runSearch(term) {
			term = (term || '').trim();
			var hasFilter = term !== '' || selectedCategoryIds.length > 0;
			if (!hasFilter) {
				showList();
				resetMapToAll();
				setResultCount((data.locations || []).length);
				return;
			}

			var reqId = ++lastReq;
			var url = data.ajaxUrl
				+ '?action=bushbreaks_maps_search'
				+ '&nonce=' + encodeURIComponent(data.nonce)
				+ '&q=' + encodeURIComponent(term);
			selectedCategoryIds.forEach(function (id) {
				url += '&cats[]=' + encodeURIComponent(id);
			});

			showLoader();

			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (reqId !== lastReq) return;
					if (!json || !json.success) {
						showList();
						resetMapToAll();
						setResultCount((data.locations || []).length);
						return;
					}
					var items = (json.data && json.data.results) || [];
					setResultCount(items.length);
					if (items.length === 0) {
						renderResults(items);
						showResults();
						showAllMarkers();
						return;
					}
					var ids = items.map(function (i) { return i.id; });
					renderResults(items);
					showResults();
					showOnlyMarkers(ids);
					var pts = items
						.filter(function (i) { return i.lat != null && i.lng != null; })
						.map(function (i) { return [parseFloat(i.lat), parseFloat(i.lng)]; });
					fitLatLngs(pts, true);
				})
				.catch(function () {
					if (reqId === lastReq) {
						showList();
						resetMapToAll();
						setResultCount((data.locations || []).length);
					}
				});
		}

		// ---- popup / card markup helpers --------------------------------------

		function pricingHtml(pricing) {
			if (!pricing) return '';
			var hasSpecial = !!pricing.special;
			var hasNormal = !!pricing.normal;
			if (!hasSpecial && !hasNormal) return '';
			var html = '<div class="bbm-card-pricing">';
			if (hasSpecial) {
				html += '<span class="bbm-price-special">' + escapeHtml(pricing.special) + '</span>';
				if (pricing.unit) {
					html += ' <span class="bbm-price-unit">' + escapeHtml(pricing.unit) + '</span>';
				}
				if (hasNormal) {
					html += ' <s class="bbm-price-was">' + escapeHtml(pricing.normal) + '</s>';
				}
				if (pricing.discount != null) {
					html += ' <span class="bbm-price-discount">&minus;' + parseInt(pricing.discount, 10) + '%</span>';
				}
				if (pricing.valid_label) {
					html += '<div class="bbm-price-valid">' + escapeHtml(pricing.valid_label) + '</div>';
				}
			} else {
				html += '<span class="bbm-price-normal">' + escapeHtml(pricing.normal) + '</span>';
				if (pricing.unit) {
					html += ' <span class="bbm-price-unit">' + escapeHtml(pricing.unit) + '</span>';
				}
			}
			html += '</div>';
			return html;
		}

		function popupHtml(item) {
			var parts = ['<div class="bbm-popup">'];
			if (item.thumbnail) {
				parts.push('<div class="bbm-popup-thumb" style="background-image:url(\'' + escapeAttr(item.thumbnail) + '\')"></div>');
			}
			parts.push('<a class="bbm-popup-title" href="' + escapeAttr(item.permalink) + '">' + escapeHtml(item.title) + '</a>');
			if (item.address) {
				parts.push('<div class="bbm-card-address">' + escapeHtml(item.address) + '</div>');
			}
			parts.push(pricingHtml(item.pricing));
			if (item.excerpt) {
				parts.push('<p class="bbm-card-excerpt" style="-webkit-line-clamp:3;">' + escapeHtml(item.excerpt) + '</p>');
			}
			parts.push('<a class="bbm-popup-link" href="' + escapeAttr(item.permalink) + '">' + escapeHtml(data.i18n.viewDetails) + ' &rarr;</a>');
			parts.push('</div>');
			return parts.join('');
		}

		function escapeHtml(str) {
			return String(str == null ? '' : str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}

		function escapeAttr(str) {
			return escapeHtml(str);
		}
	}
})();
