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
		var suggestionEl = wrap ? wrap.querySelector('.bbm-suggestion') : null;
		var suggestionPool = buildSuggestionPool();

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
			if (n === null || n === undefined) {
				resultCountEl.textContent = '';
				return;
			}
			var count = parseInt(n, 10) || 0;
			var template = count === 1
				? (data.i18n.resultsCountSingle || '1 lodge')
				: (data.i18n.resultsCountPlural || '%d lodges');
			resultCountEl.textContent = template.replace('%d', count);
		}

		mapInit(mapEl);
		(data.locations || []).forEach(addMarker);
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
			searchInput.addEventListener('focus', function () {
				updateSuggestion(searchInput.value);
			});
			searchInput.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') hideSuggestions();
			});
			document.addEventListener('mousedown', function (e) {
				if (!suggestionEl || suggestionEl.hidden) return;
				var searchWrap = searchInput.closest('.bbm-search');
				if (searchWrap && !searchWrap.contains(e.target)) {
					hideSuggestions();
				}
			});
		}

		// Category + destination filters
		var selectedCategoryIds = [];
		var selectedDestinationIds = [];

		var categoryFilter = initFilter({
			wrapperSelector: '.bbm-categories',
			items: data.categories || [],
			placeholder: data.i18n.categoryPlaceholder || 'Filter by category…',
			removeLabel: data.i18n.removeCategory || 'Remove',
			selectedRef: function () { return selectedCategoryIds; },
			setSelected: function (next) { selectedCategoryIds = next; },
		});

		var destinationFilter = (data.enableRegionFilter === false)
			? { renderChips: function () {}, updateLabel: function () {}, reset: function () {} }
			: initFilter({
				wrapperSelector: '.bbm-destinations',
				items: data.destinations || [],
				placeholder: data.i18n.destinationPlaceholder || 'Filter by destination…',
				removeLabel: data.i18n.removeDestination || 'Remove',
				selectedRef: function () { return selectedDestinationIds; },
				setSelected: function (next) { selectedDestinationIds = next; },
			});

		function initFilter(opts) {
			var groupEl = wrap ? wrap.querySelector(opts.wrapperSelector) : null;
			if (!groupEl || !opts.items.length) {
				return { renderChips: function () {}, updateLabel: function () {} };
			}
			var toggle = groupEl.querySelector('.bbm-category-toggle');
			var label = groupEl.querySelector('.bbm-category-toggle-label');
			var panel = groupEl.querySelector('.bbm-category-panel');
			var chips = groupEl.querySelector('.bbm-category-chips');
			if (!toggle || !panel || !chips) {
				return { renderChips: function () {}, updateLabel: function () {} };
			}

			var overflowEl = document.createElement('div');
			overflowEl.className = 'bbm-chips-overflow';
			overflowEl.setAttribute('role', 'list');
			overflowEl.hidden = true;
			chips.parentNode.insertBefore(overflowEl, chips.nextSibling);

			var parentBadges = []; // [{ node, el }]
			var MAX_INLINE_CHIPS = 3;

			groupEl.hidden = false;
			renderPanel();
			updateLabel();
			updateBadges();

			toggle.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				var willOpen = panel.hidden;
				panel.hidden = !willOpen;
				toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
			});

			panel.addEventListener('change', function (e) {
				var cb = e.target.closest('input[type="checkbox"]');
				if (!cb) return;
				var id = parseInt(cb.value, 10);
				var node = findItemById(opts.items, id);
				var hasChildren = !!(node && node.children && node.children.length);

				var sel = opts.selectedRef();
				if (cb.checked) {
					if (sel.indexOf(id) === -1) sel.push(id);
				} else {
					sel = sel.filter(function (cid) { return cid !== id; });
				}

				if (hasChildren) {
					// Expand the subtree when the parent is checked so the user
					// can pick specific children; collapse it again on uncheck.
					// Child selection state is NOT modified — only visibility.
					var parentNode = cb.closest('.bbm-tree-node');
					if (parentNode) {
						var ownChildren = null;
						for (var i = 0; i < parentNode.children.length; i++) {
							if (parentNode.children[i].classList && parentNode.children[i].classList.contains('bbm-tree-children')) {
								ownChildren = parentNode.children[i];
								break;
							}
						}
						if (ownChildren) {
							ownChildren.hidden = !cb.checked;
							var row = parentNode.querySelector('.bbm-tree-row');
							var ownToggle = row ? row.querySelector('.bbm-tree-toggle') : null;
							if (ownToggle) {
								ownToggle.setAttribute('aria-expanded', cb.checked ? 'true' : 'false');
							}
						}
					}
				}

				opts.setSelected(sel);
				renderChips();
				updateLabel();
				updateBadges();
				runSearch(searchInput ? searchInput.value : '');
			});

			function removeSelected(id) {
				var sel = opts.selectedRef().filter(function (cid) { return cid !== id; });
				opts.setSelected(sel);
				var cb = panel.querySelector('input[type="checkbox"][value="' + id + '"]');
				if (cb) cb.checked = false;
				renderChips();
				updateLabel();
				updateBadges();
				runSearch(searchInput ? searchInput.value : '');
			}

			chips.addEventListener('click', function (e) {
				var btn = e.target.closest('.bbm-chip-remove');
				if (!btn) return;
				removeSelected(parseInt(btn.getAttribute('data-id'), 10));
			});

			overflowEl.addEventListener('click', function (e) {
				var btn = e.target.closest('.bbm-chip-remove');
				if (!btn) return;
				removeSelected(parseInt(btn.getAttribute('data-id'), 10));
			});

			document.addEventListener('click', function (e) {
				if (panel.hidden) return;
				if (!groupEl.contains(e.target)) {
					panel.hidden = true;
					toggle.setAttribute('aria-expanded', 'false');
				}
			});

			document.addEventListener('mousedown', function (e) {
				if (overflowEl.hidden) return;
				if (groupEl.contains(e.target)) return;
				closeOverflow();
			});

			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') closeOverflow();
			});

			function closeOverflow() {
				if (overflowEl.hidden) return;
				overflowEl.hidden = true;
				overflowEl.innerHTML = '';
				var more = chips.querySelector('.bbm-chip-more');
				if (more) more.setAttribute('aria-expanded', 'false');
			}

			function renderPanel() {
				panel.innerHTML = '';
				parentBadges = [];
				var isTreeMode = opts.items.some(function (i) { return i.children && i.children.length; });
				opts.items.forEach(function (it) {
					panel.appendChild(buildTreeNode(it, isTreeMode));
				});
			}

			function buildTreeNode(node, isTreeMode) {
				var wrapper = document.createElement('div');
				wrapper.className = 'bbm-tree-node';

				var row = document.createElement('div');
				row.className = 'bbm-tree-row';

				var hasChildren = node.children && node.children.length > 0;
				var toggle = null;
				if (hasChildren) {
					toggle = document.createElement('button');
					toggle.type = 'button';
					toggle.className = 'bbm-tree-toggle';
					toggle.setAttribute('aria-expanded', 'false');
					toggle.setAttribute('aria-label', 'Expand');
					row.appendChild(toggle);
				} else if (isTreeMode) {
					var spacer = document.createElement('span');
					spacer.className = 'bbm-tree-spacer';
					spacer.setAttribute('aria-hidden', 'true');
					row.appendChild(spacer);
				}

				var lbl = document.createElement('label');
				lbl.className = 'bbm-tree-label';
				var cb = document.createElement('input');
				cb.type = 'checkbox';
				cb.value = String(node.id);
				cb.checked = opts.selectedRef().indexOf(node.id) !== -1;
				var nameSpan = document.createElement('span');
				nameSpan.textContent = node.name;
				lbl.appendChild(cb);
				lbl.appendChild(nameSpan);

				if (hasChildren) {
					var badge = document.createElement('span');
					badge.className = 'bbm-tree-badge';
					badge.hidden = true;
					badge.setAttribute('aria-hidden', 'true');
					lbl.appendChild(badge);
					parentBadges.push({ node: node, el: badge });
				}

				row.appendChild(lbl);
				wrapper.appendChild(row);

				if (hasChildren) {
					var childContainer = document.createElement('div');
					childContainer.className = 'bbm-tree-children';
					childContainer.hidden = true;
					node.children.forEach(function (c) {
						childContainer.appendChild(buildTreeNode(c, isTreeMode));
					});
					wrapper.appendChild(childContainer);

					toggle.addEventListener('click', function (e) {
						e.preventDefault();
						e.stopPropagation();
						var isOpen = !childContainer.hidden;
						childContainer.hidden = isOpen;
						toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
					});
				}

				return wrapper;
			}

			function countSelectedDescendants(node, sel) {
				if (!node.children || !node.children.length) return 0;
				var count = 0;
				node.children.forEach(function (c) {
					if (sel.indexOf(c.id) !== -1) count++;
					count += countSelectedDescendants(c, sel);
				});
				return count;
			}

			function updateBadges() {
				var sel = opts.selectedRef();
				parentBadges.forEach(function (pb) {
					var n = countSelectedDescendants(pb.node, sel);
					if (n > 0) {
						pb.el.textContent = String(n);
						pb.el.hidden = false;
					} else {
						pb.el.textContent = '';
						pb.el.hidden = true;
					}
				});
			}

			function updateLabel() {
				if (!label) return;
				var n = opts.selectedRef().length;
				label.textContent = n > 0 ? opts.placeholder + ' (' + n + ')' : opts.placeholder;
			}

			function makeChip(it) {
				var chip = document.createElement('span');
				chip.className = 'bbm-chip';
				chip.setAttribute('role', 'listitem');
				chip.innerHTML = escapeHtml(it.name)
					+ ' <button type="button" class="bbm-chip-remove" aria-label="'
					+ escapeAttr(opts.removeLabel)
					+ '" data-id="' + it.id + '">&times;</button>';
				return chip;
			}

			function renderChips() {
				var wasOpen = !overflowEl.hidden;
				chips.innerHTML = '';
				overflowEl.innerHTML = '';

				var items = opts.selectedRef()
					.map(function (id) { return findItemById(opts.items, id); })
					.filter(function (it) { return !!it; });

				// Show everything inline if it fits in MAX_INLINE_CHIPS + 1
				// (avoids a "+1 more" popover for a single overflow item).
				var threshold = MAX_INLINE_CHIPS + 1;
				if (items.length <= threshold) {
					items.forEach(function (it) { chips.appendChild(makeChip(it)); });
					overflowEl.hidden = true;
					return;
				}

				var inline = items.slice(0, MAX_INLINE_CHIPS);
				var overflow = items.slice(MAX_INLINE_CHIPS);
				inline.forEach(function (it) { chips.appendChild(makeChip(it)); });

				var moreBtn = document.createElement('button');
				moreBtn.type = 'button';
				moreBtn.className = 'bbm-chip bbm-chip-more';
				moreBtn.textContent = '+' + overflow.length + ' more';
				moreBtn.setAttribute('aria-haspopup', 'true');
				moreBtn.addEventListener('click', function (e) {
					e.preventDefault();
					e.stopPropagation();
					if (!overflowEl.hidden) {
						closeOverflow();
						return;
					}
					populateOverflow(overflow);
					overflowEl.hidden = false;
					moreBtn.setAttribute('aria-expanded', 'true');
				});
				chips.appendChild(moreBtn);

				if (wasOpen) {
					populateOverflow(overflow);
					overflowEl.hidden = false;
					moreBtn.setAttribute('aria-expanded', 'true');
				} else {
					overflowEl.hidden = true;
					moreBtn.setAttribute('aria-expanded', 'false');
				}
			}

			function populateOverflow(items) {
				overflowEl.innerHTML = '';
				items.forEach(function (it) { overflowEl.appendChild(makeChip(it)); });
			}

			function findItemById(items, id) {
				for (var i = 0; i < items.length; i++) {
					if (items[i].id === id) return items[i];
					if (items[i].children && items[i].children.length) {
						var found = findItemById(items[i].children, id);
						if (found) return found;
					}
				}
				return null;
			}

			return {
				renderChips: renderChips,
				updateLabel: updateLabel,
				reset: function () {
					opts.setSelected([]);
					renderPanel();
					renderChips();
					updateLabel();
					updateBadges();
				},
			};
		}

		var clearAllBtn = wrap ? wrap.querySelector('.bbm-clear-all') : null;
		function updateClearAllVisibility() {
			if (!clearAllBtn) return;
			var hasFilter = (searchInput && searchInput.value.trim() !== '')
				|| selectedCategoryIds.length > 0
				|| selectedDestinationIds.length > 0;
			clearAllBtn.hidden = !hasFilter;
		}
		if (clearAllBtn) {
			clearAllBtn.addEventListener('click', function (e) {
				e.preventDefault();
				if (searchInput) searchInput.value = '';
				categoryFilter.reset();
				destinationFilter.reset();
				clearTimeout(searchTimer);
				runSearch('');
				if (searchInput) searchInput.focus();
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

		// Suggestion pool is terms only (destination + category names).
		// Lodge titles are intentionally excluded — searches should bias
		// toward places/regions, not specific accommodations.
		function buildSuggestionPool() {
			var pool = [];
			function walkDests(list) {
				(list || []).forEach(function (d) {
					if (d && d.name) pool.push(String(d.name));
					if (d && d.children) walkDests(d.children);
				});
			}
			walkDests(data.destinations);
			(data.categories || []).forEach(function (c) {
				if (c && c.name) pool.push(String(c.name));
			});
			var seen = {};
			return pool.filter(function (s) {
				var k = s.toLowerCase();
				if (seen[k]) return false;
				seen[k] = true;
				return true;
			});
		}

		function levDistance(a, b) {
			var m = a.length, n = b.length;
			if (m === 0) return n;
			if (n === 0) return m;
			var prev = new Array(n + 1);
			var curr = new Array(n + 1);
			for (var j = 0; j <= n; j++) prev[j] = j;
			for (var i = 1; i <= m; i++) {
				curr[0] = i;
				for (var k = 1; k <= n; k++) {
					var cost = a.charCodeAt(i - 1) === b.charCodeAt(k - 1) ? 0 : 1;
					curr[k] = Math.min(curr[k - 1] + 1, prev[k] + 1, prev[k - 1] + cost);
				}
				var tmp = prev; prev = curr; curr = tmp;
			}
			return prev[n];
		}

		// Returns up to MAX ranked term suggestions. Rank order:
		// 0 = prefix match, 1 = substring match, 2 = fuzzy match.
		// Within a rank, items keep their pool order.
		function findSuggestions(term) {
			var MAX = 6;
			term = term.trim().toLowerCase();
			if (term.length < 2) return [];

			var scored = [];
			for (var i = 0; i < suggestionPool.length; i++) {
				var cand = suggestionPool[i];
				var lower = cand.toLowerCase();
				var rank = -1;

				if (lower.indexOf(term) === 0) {
					rank = 0;
				} else if (lower.indexOf(term) !== -1) {
					rank = 1;
				} else if (term.length >= 3) {
					var words = lower.split(/\s+/);
					words.push(lower);
					for (var w = 0; w < words.length; w++) {
						var word = words[w];
						if (word.length < 3) continue;
						if (Math.abs(word.length - term.length) > 3) continue;
						var dist = levDistance(term, word);
						var ratio = dist / Math.max(term.length, word.length);
						if (ratio <= 0.3) {
							rank = 2;
							break;
						}
					}
				}

				if (rank !== -1) {
					scored.push({ name: cand, rank: rank, idx: i });
				}
			}

			scored.sort(function (a, b) {
				if (a.rank !== b.rank) return a.rank - b.rank;
				return a.idx - b.idx;
			});
			return scored.slice(0, MAX).map(function (s) { return s.name; });
		}

		var currentSuggestions = [];

		function hideSuggestions() {
			if (!suggestionEl) return;
			suggestionEl.hidden = true;
			suggestionEl.textContent = '';
			currentSuggestions = [];
		}

		function updateSuggestion(term) {
			if (!suggestionEl) return;
			term = (term || '').trim();
			suggestionEl.textContent = '';
			if (term === '') {
				suggestionEl.hidden = true;
				currentSuggestions = [];
				return;
			}
			var matches = findSuggestions(term);
			if (matches.length === 0 || (matches.length === 1 && matches[0].toLowerCase() === term.toLowerCase())) {
				suggestionEl.hidden = true;
				currentSuggestions = [];
				return;
			}
			currentSuggestions = matches;
			matches.forEach(function (name) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'bbm-suggestion-option';
				btn.setAttribute('role', 'option');
				btn.textContent = name;
				btn.addEventListener('mousedown', function (e) {
					// Prevent the input from losing focus before click fires.
					e.preventDefault();
				});
				btn.addEventListener('click', function () {
					if (!searchInput) return;
					searchInput.value = name;
					hideSuggestions();
					runSearch(name);
				});
				suggestionEl.appendChild(btn);
			});

			var footer = document.createElement('div');
			footer.className = 'bbm-suggestion-footer';
			var closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'bbm-suggestion-close';
			closeBtn.textContent = (data.i18n && data.i18n.suggestionsClose) || 'Close';
			closeBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
			closeBtn.addEventListener('click', hideSuggestions);
			footer.appendChild(closeBtn);
			suggestionEl.appendChild(footer);

			suggestionEl.hidden = false;
		}

		function runSearch(term) {
			term = (term || '').trim();
			updateSuggestion(term);
			var hasFilter = term !== ''
				|| selectedCategoryIds.length > 0
				|| selectedDestinationIds.length > 0;
			updateClearAllVisibility();
			if (!hasFilter) {
				showList();
				resetMapToAll();
				setResultCount(null);
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
			selectedDestinationIds.forEach(function (id) {
				url += '&dests[]=' + encodeURIComponent(id);
			});

			showLoader();

			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (reqId !== lastReq) return;
					if (!json || !json.success) {
						showList();
						resetMapToAll();
						setResultCount(null);
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
						setResultCount(null);
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
