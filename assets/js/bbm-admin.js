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
})();
