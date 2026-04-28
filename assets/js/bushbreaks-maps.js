(function () {
	'use strict';

	if (typeof window.L === 'undefined' || typeof window.BushbreaksMapsData === 'undefined') {
		return;
	}

	var data = window.BushbreaksMapsData;
	var mapEl = document.getElementById('bbm-map');
	if (!mapEl) {
		return;
	}

	var wrap = mapEl.closest('.bbm-wrap');
	var searchInput = wrap ? wrap.querySelector('.bbm-search-input') : null;
	var resultsEl = wrap ? wrap.querySelector('.bbm-results') : null;
	var resultsList = wrap ? wrap.querySelector('.bbm-results-list') : null;
	var listEl = wrap ? wrap.querySelector('.bbm-list') : null;

	var map = L.map(mapEl, {
		scrollWheelZoom: true,
	}).setView([data.center.lat, data.center.lng], data.zoom);

	L.tileLayer(data.tile.url, {
		attribution: data.tile.attr,
		maxZoom: 19,
	}).addTo(map);

	var markersById = {};
	var allMarkers = [];

	function popupHtml(item) {
		var parts = [];
		if (item.thumbnail) {
			parts.push('<div class="bbm-popup-thumb" style="background-image:url(\'' + escapeAttr(item.thumbnail) + '\')"></div>');
		}
		parts.push('<a class="bbm-popup-title" href="' + escapeAttr(item.permalink) + '">' + escapeHtml(item.title) + '</a>');
		if (item.address) {
			parts.push('<div class="bbm-card-address">' + escapeHtml(item.address) + '</div>');
		}
		if (item.excerpt) {
			parts.push('<p class="bbm-card-excerpt" style="-webkit-line-clamp:3;">' + escapeHtml(item.excerpt) + '</p>');
		}
		parts.push('<a class="bbm-popup-link" href="' + escapeAttr(item.permalink) + '">' + escapeHtml(data.i18n.viewDetails) + ' &rarr;</a>');
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

	function addMarker(item) {
		if (item.lat == null || item.lng == null) {
			return null;
		}
		var marker = L.marker([item.lat, item.lng], { title: item.title });
		marker.bindPopup(popupHtml(item), { minWidth: 220, maxWidth: 280 });
		marker.addTo(map);
		markersById[item.id] = marker;
		allMarkers.push(marker);
		return marker;
	}

	(data.locations || []).forEach(addMarker);

	if (allMarkers.length > 0) {
		var group = L.featureGroup(allMarkers);
		map.fitBounds(group.getBounds().pad(0.15));
	}

	function focusItem(id, lat, lng) {
		var marker = markersById[id];
		if (marker) {
			map.flyTo(marker.getLatLng(), Math.max(map.getZoom(), 11), { duration: 0.6 });
			marker.openPopup();
		} else if (lat != null && lng != null) {
			map.flyTo([parseFloat(lat), parseFloat(lng)], Math.max(map.getZoom(), 11), { duration: 0.6 });
		}
	}

	function bindCardInteractions(root) {
		if (!root) return;
		root.querySelectorAll('.bbm-card').forEach(function (card) {
			card.addEventListener('click', function (e) {
				if (e.target.closest('a')) {
					return;
				}
				var id = parseInt(card.getAttribute('data-id'), 10);
				var lat = card.getAttribute('data-lat');
				var lng = card.getAttribute('data-lng');
				root.querySelectorAll('.bbm-card.is-active').forEach(function (c) { c.classList.remove('is-active'); });
				card.classList.add('is-active');
				focusItem(id, lat, lng);
			});
		});
	}

	bindCardInteractions(listEl);

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
			html += '<a class="bbm-card-title" href="' + escapeAttr(item.permalink) + '">' + escapeHtml(item.title) + '</a>';
			if (item.address) {
				html += '<div class="bbm-card-address">' + escapeHtml(item.address) + '</div>';
			}
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
		if (resultsEl) resultsEl.hidden = false;
		if (listEl) listEl.hidden = true;
	}

	function showList() {
		if (resultsEl) resultsEl.hidden = true;
		if (listEl) listEl.hidden = false;
	}

	function fitToItems(items) {
		var pts = items
			.filter(function (i) { return i.lat != null && i.lng != null; })
			.map(function (i) { return [i.lat, i.lng]; });
		if (pts.length === 0) return;
		if (pts.length === 1) {
			map.flyTo(pts[0], Math.max(map.getZoom(), 10), { duration: 0.6 });
		} else {
			map.flyToBounds(L.latLngBounds(pts).pad(0.2), { duration: 0.6 });
		}
	}

	var searchTimer = null;
	var lastReq = 0;

	function runSearch(term) {
		term = term.trim();
		if (term === '') {
			showList();
			return;
		}

		var reqId = ++lastReq;
		var url = data.ajaxUrl + '?action=bushbreaks_maps_search&nonce=' + encodeURIComponent(data.nonce) + '&q=' + encodeURIComponent(term);

		fetch(url, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (reqId !== lastReq) return;
				if (!json || !json.success) return;
				var items = (json.data && json.data.results) || [];
				renderResults(items);
				showResults();
				fitToItems(items);
			})
			.catch(function () { /* ignore */ });
	}

	if (searchInput) {
		searchInput.addEventListener('input', function () {
			clearTimeout(searchTimer);
			var term = searchInput.value;
			searchTimer = setTimeout(function () { runSearch(term); }, 250);
		});
	}
})();
