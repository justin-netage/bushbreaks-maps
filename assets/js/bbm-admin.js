(function () {
	'use strict';

	// Media Library picker for icon URL fields.
	if (typeof window.jQuery !== 'undefined' && typeof window.wp !== 'undefined' && window.wp.media) {
		jQuery(document).on('click', '.bbm-media-picker', function (e) {
			e.preventDefault();
			var $btn = jQuery(this);
			var target = $btn.data('target');
			var widthTarget = $btn.data('width-target');
			var heightTarget = $btn.data('height-target');

			var frame = wp.media({
				title: $btn.data('title') || 'Choose image',
				button: { text: 'Use this image' },
				library: { type: 'image' },
				multiple: false,
			});

			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				if (target) jQuery('#' + target).val(att.url);
				if (widthTarget && att.width) jQuery('#' + widthTarget).val(att.width);
				if (heightTarget && att.height) jQuery('#' + heightTarget).val(att.height);
			});

			frame.open();
		});
	}

	var cfg = window.BushbreaksMapsAdmin || null;

	// Tab switcher for the settings page
	(function () {
		var tabs = document.querySelectorAll('.bbm-tabs .nav-tab');
		var panes = document.querySelectorAll('.bbm-tab-content');
		var submitRow = document.querySelector('.bbm-settings-form .bbm-submit-row');
		if (!tabs.length || !panes.length) return;

		function activate(name) {
			tabs.forEach(function (t) {
				t.classList.toggle('nav-tab-active', t.getAttribute('data-tab') === name);
			});
			panes.forEach(function (p) {
				p.classList.toggle('is-active', p.getAttribute('data-tab') === name);
			});
			if (submitRow) submitRow.style.display = (name === 'tools') ? 'none' : '';
			if (window.history && history.replaceState) {
				history.replaceState(null, '', '#' + name);
			}
		}

		tabs.forEach(function (t) {
			t.addEventListener('click', function (e) {
				e.preventDefault();
				activate(t.getAttribute('data-tab'));
			});
		});

		var initial = (location.hash || '').replace('#', '');
		if (initial && document.querySelector('.bbm-tabs .nav-tab[data-tab="' + initial + '"]')) {
			activate(initial);
		}
	})();

	// Featured accommodations picker: search + add + sortable remove list
	(function () {
		var picker = document.querySelector('.bbm-featured-picker');
		if (!picker || !cfg || typeof window.jQuery === 'undefined') return;

		var $          = window.jQuery;
		var search     = picker.querySelector('.bbm-featured-search');
		var suggestEl  = picker.querySelector('.bbm-featured-suggestions');
		var selected   = picker.querySelector('.bbm-featured-selected');
		if (!search || !suggestEl || !selected) return;

		var debounceId = null;
		var lastReq    = 0;

		search.addEventListener('input', function () {
			var term = search.value.trim();
			clearTimeout(debounceId);
			if (term.length < 2) {
				suggestEl.innerHTML = '';
				suggestEl.hidden = true;
				return;
			}
			debounceId = setTimeout(function () { runQuery(term); }, 250);
		});

		search.addEventListener('focus', function () {
			if (suggestEl.children.length > 0) suggestEl.hidden = false;
		});

		document.addEventListener('mousedown', function (e) {
			if (suggestEl.hidden) return;
			if (!picker.contains(e.target)) {
				suggestEl.hidden = true;
			}
		});

		suggestEl.addEventListener('click', function (e) {
			var btn = e.target.closest('.bbm-featured-suggest');
			if (!btn) return;
			e.preventDefault();
			var id    = parseInt(btn.getAttribute('data-id'), 10);
			var title = btn.textContent;
			if (id > 0 && getSelectedIds().indexOf(id) === -1) {
				addSelected(id, title);
			}
			search.value = '';
			suggestEl.innerHTML = '';
			suggestEl.hidden = true;
			search.focus();
		});

		selected.addEventListener('click', function (e) {
			var btn = e.target.closest('.bbm-featured-remove');
			if (!btn) return;
			e.preventDefault();
			var li = btn.closest('li');
			if (li) li.remove();
		});

		if (typeof $.fn.sortable === 'function') {
			$(selected).sortable({
				items: '> li',
				handle: '.bbm-featured-handle',
				axis: 'y',
				placeholder: 'bbm-featured-placeholder',
				forcePlaceholderSize: true,
			}).disableSelection();
		}

		function runQuery(term) {
			var reqId = ++lastReq;
			$.get(cfg.ajaxUrl, {
				action: 'bushbreaks_maps_lodge_search',
				nonce: cfg.lodgeSearchNonce,
				q: term,
			}).done(function (json) {
				if (reqId !== lastReq) return;
				if (!json || !json.success) {
					suggestEl.innerHTML = '<div class="bbm-featured-suggest-empty">' + escapeHtml(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Search failed.') + '</div>';
					suggestEl.hidden = false;
					return;
				}
				renderSuggestions((json.data && json.data.items) || []);
			}).fail(function () {
				if (reqId !== lastReq) return;
				suggestEl.innerHTML = '<div class="bbm-featured-suggest-empty">' + escapeHtml(cfg.i18n && cfg.i18n.networkError ? cfg.i18n.networkError : 'Network error.') + '</div>';
				suggestEl.hidden = false;
			});
		}

		function renderSuggestions(items) {
			suggestEl.innerHTML = '';
			var picked = getSelectedIds();
			var shown  = 0;
			items.forEach(function (it) {
				if (!it || !it.id) return;
				if (picked.indexOf(parseInt(it.id, 10)) !== -1) return;
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'bbm-featured-suggest';
				btn.setAttribute('data-id', String(it.id));
				btn.textContent = String(it.title || '');
				suggestEl.appendChild(btn);
				shown++;
			});
			if (shown === 0) {
				var empty = document.createElement('div');
				empty.className = 'bbm-featured-suggest-empty';
				empty.textContent = 'No more matches.';
				suggestEl.appendChild(empty);
			}
			suggestEl.hidden = false;
		}

		function getSelectedIds() {
			var ids = [];
			selected.querySelectorAll('li').forEach(function (li) {
				var id = parseInt(li.getAttribute('data-id'), 10);
				if (id > 0) ids.push(id);
			});
			return ids;
		}

		function addSelected(id, title) {
			var li = document.createElement('li');
			li.setAttribute('data-id', String(id));

			var handle = document.createElement('span');
			handle.className = 'bbm-featured-handle';
			handle.setAttribute('aria-hidden', 'true');
			handle.innerHTML = '&#8801;';

			var name = document.createElement('span');
			name.className = 'bbm-featured-name';
			name.textContent = title;

			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = (cfg.optionKey || 'bushbreaks_maps_settings') + '[featured_post_ids][]';
			hidden.value = String(id);

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'bbm-featured-remove';
			remove.setAttribute('aria-label', 'Remove');
			remove.innerHTML = '&times;';

			li.appendChild(handle);
			li.appendChild(name);
			li.appendChild(hidden);
			li.appendChild(remove);
			selected.appendChild(li);
		}

		function escapeHtml(str) {
			return String(str == null ? '' : str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}
	})();

	// Drag-and-drop category ordering
	if (cfg && typeof window.jQuery !== 'undefined' && typeof jQuery.fn.sortable === 'function') {
		var $list = jQuery('#bbm-category-order-list');
		if ($list.length) {
			var $status = jQuery('#bbm-order-status');
			$list.sortable({
				items: '> li',
				handle: '.bbm-sort-handle',
				axis: 'y',
				cursor: 'grabbing',
				placeholder: 'bbm-sort-placeholder',
				forcePlaceholderSize: true,
				update: function () {
					var order = $list.children('li').map(function () {
						return jQuery(this).data('id');
					}).get();

					if (cfg.i18n && cfg.i18n.saving) $status.text(cfg.i18n.saving);

					jQuery.post(cfg.ajaxUrl, {
						action: 'bushbreaks_maps_reorder_categories',
						nonce: cfg.reorderNonce,
						order: order,
					}).done(function (json) {
						if (json && json.success) {
							$status.text(cfg.i18n && cfg.i18n.saved ? cfg.i18n.saved : 'Saved');
							setTimeout(function () { $status.text(''); }, 1500);
						} else {
							$status.text(cfg.i18n && cfg.i18n.saveFailed ? cfg.i18n.saveFailed : 'Save failed');
						}
					}).fail(function () {
						$status.text(cfg.i18n && cfg.i18n.saveFailed ? cfg.i18n.saveFailed : 'Save failed');
					});
				},
			}).disableSelection();
		}

		// Destinations: nested sortable (provinces + reserves)
		var $destStatus = jQuery('#bbm-dest-order-status');
		jQuery('.bbm-dest-sortable').each(function () {
			var $u = jQuery(this);
			$u.sortable({
				items: '> li.bbm-sort-item',
				handle: '> .bbm-sort-row > .bbm-sort-handle',
				axis: 'y',
				cursor: 'grabbing',
				placeholder: 'bbm-sort-placeholder',
				forcePlaceholderSize: true,
				update: function (e, ui) {
					if (ui && ui.item.parent()[0] !== $u[0]) return;

					var order = $u.children('li.bbm-sort-item').map(function () {
						return jQuery(this).data('id');
					}).get();

					if (cfg.i18n && cfg.i18n.saving) $destStatus.text(cfg.i18n.saving);

					jQuery.post(cfg.ajaxUrl, {
						action: 'bushbreaks_maps_reorder_destinations',
						nonce: cfg.reorderDestNonce,
						order: order,
					}).done(function (json) {
						if (json && json.success) {
							$destStatus.text(cfg.i18n && cfg.i18n.saved ? cfg.i18n.saved : 'Saved');
							setTimeout(function () { $destStatus.text(''); }, 1500);
						} else {
							$destStatus.text(cfg.i18n && cfg.i18n.saveFailed ? cfg.i18n.saveFailed : 'Save failed');
						}
					}).fail(function () {
						$destStatus.text(cfg.i18n && cfg.i18n.saveFailed ? cfg.i18n.saveFailed : 'Save failed');
					});
				},
			}).disableSelection();
		});
	}

	var btn = document.getElementById('bbm-backfill');
	var statusEl = document.getElementById('bbm-backfill-status');
	if (!btn || !statusEl || !cfg) {
		return;
	}

	function format(template, a, b) {
		return template.replace('%1$s', a).replace('%2$s', b);
	}

	btn.addEventListener('click', function (e) {
		e.preventDefault();
		btn.disabled = true;
		statusEl.textContent = cfg.i18n.starting;
		runBatch(0);
	});

	function runBatch(offset) {
		var fd = new FormData();
		fd.append('action', 'bushbreaks_maps_backfill');
		fd.append('nonce', cfg.nonce);
		fd.append('offset', String(offset));

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		})
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					statusEl.textContent = cfg.i18n.error;
					btn.disabled = false;
					return;
				}
				var d = json.data;
				if (d.done) {
					statusEl.textContent = format(cfg.i18n.done, d.next, d.total);
					btn.disabled = false;
				} else {
					statusEl.textContent = format(cfg.i18n.progress, d.next, d.total);
					runBatch(d.next);
				}
			})
			.catch(function () {
				statusEl.textContent = cfg.i18n.networkError;
				btn.disabled = false;
			});
	}

	var regenBtn = document.getElementById('bbm-regen-feed-images');
	var regenStatusEl = document.getElementById('bbm-regen-feed-images-status');
	if (!regenBtn || !regenStatusEl || !cfg) {
		return;
	}

	var regenFound = 0;
	var regenWarmed = 0;

	regenBtn.addEventListener('click', function (e) {
		e.preventDefault();
		regenBtn.disabled = true;
		regenFound = 0;
		regenWarmed = 0;
		regenStatusEl.textContent = cfg.i18n.starting;
		runRegenBatch(0);
	});

	function runRegenBatch(offset) {
		var fd = new FormData();
		fd.append('action', 'bushbreaks_maps_regen_feed_images');
		fd.append('nonce', cfg.regenFeedImagesNonce);
		fd.append('offset', String(offset));

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		})
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					regenStatusEl.textContent = cfg.i18n.error;
					regenBtn.disabled = false;
					return;
				}
				var d = json.data;
				regenFound += (d.found || 0);
				regenWarmed += (d.warmed || 0);
				// Not a translated string — this is a diagnostic count
				// (images found vs. actually cropped) so it's clear at a
				// glance whether the regen tool is finding anything at all.
				var suffix = ' (' + regenWarmed + '/' + regenFound + ' images cropped so far)';
				if (d.done) {
					regenStatusEl.textContent = format(cfg.i18n.done, d.next, d.total) + suffix;
					regenBtn.disabled = false;
				} else {
					regenStatusEl.textContent = format(cfg.i18n.progress, d.next, d.total) + suffix;
					runRegenBatch(d.next);
				}
			})
			.catch(function () {
				regenStatusEl.textContent = cfg.i18n.networkError;
				regenBtn.disabled = false;
			});
	}
})();
