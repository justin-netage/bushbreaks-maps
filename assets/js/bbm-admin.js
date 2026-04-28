(function () {
	'use strict';

	var btn = document.getElementById('bbm-backfill');
	var status = document.getElementById('bbm-backfill-status');
	if (!btn || !status || typeof window.BushbreaksMapsAdmin === 'undefined') {
		return;
	}

	var cfg = window.BushbreaksMapsAdmin;

	function format(template, a, b) {
		return template.replace('%1$s', a).replace('%2$s', b);
	}

	btn.addEventListener('click', function (e) {
		e.preventDefault();
		btn.disabled = true;
		status.textContent = cfg.i18n.starting;
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
					status.textContent = cfg.i18n.error;
					btn.disabled = false;
					return;
				}
				var d = json.data;
				if (d.done) {
					status.textContent = format(cfg.i18n.done, d.next, d.total);
					btn.disabled = false;
				} else {
					status.textContent = format(cfg.i18n.progress, d.next, d.total);
					runBatch(d.next);
				}
			})
			.catch(function () {
				status.textContent = cfg.i18n.networkError;
				btn.disabled = false;
			});
	}
})();
