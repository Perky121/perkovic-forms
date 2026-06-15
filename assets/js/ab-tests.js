(function () {
	'use strict';

	var cfg     = window.pfAbInit || {};
	var nonce   = cfg.nonce   || '';
	var ajaxUrl = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
	var days    = cfg.days    || 30;

	/* ---------------------------------------------------------
	 *  Render rezultata jednog testa
	 * --------------------------------------------------------- */
	function renderTestResults(container, data) {
		var va = data.variants['A'];
		var vb = data.variants['B'];

		var winnerA = va.conversion_rate > vb.conversion_rate;
		var winnerB = vb.conversion_rate > va.conversion_rate;

		var html = '<div class="pf-ab-results-inner">';

		// Usporedba kartica A vs B
		html += '<div class="pf-ab-compare-grid">';

		['A', 'B'].forEach(function (v) {
			var vd      = data.variants[v];
			var isWin   = ( v === 'A' && winnerA ) || ( v === 'B' && winnerB );
			var crColor = vd.conversion_rate >= 10 ? '#338B45' : vd.conversion_rate >= 3 ? '#B58A00' : '#C44545';

			html += '<div class="pf-ab-variant-card' + ( isWin && data.significant ? ' is-winner' : '' ) + '">';
			html += '<div class="pf-ab-variant-label pf-variant-' + v.toLowerCase() + '">Varijanta ' + v + ( isWin && data.significant ? ' 🏆' : '' ) + '</div>';
			html += '<div class="pf-ab-variant-stats">';
			html += '<div class="pf-ab-stat"><span class="pf-ab-stat-val">' + vd.views + '</span><span class="pf-ab-stat-lbl">Pregledi</span></div>';
			html += '<div class="pf-ab-stat"><span class="pf-ab-stat-val">' + vd.starts + '</span><span class="pf-ab-stat-lbl">Počeli</span></div>';
			html += '<div class="pf-ab-stat"><span class="pf-ab-stat-val">' + vd.submits + '</span><span class="pf-ab-stat-lbl">Konverzije</span></div>';
			html += '<div class="pf-ab-stat"><span class="pf-ab-stat-val" style="color:' + crColor + '">' + vd.conversion_rate + '%</span><span class="pf-ab-stat-lbl">Stopa konv.</span></div>';
			html += '</div></div>';
		});

		html += '</div>'; // pf-ab-compare-grid

		// Statistika
		html += '<div class="pf-ab-stats-row">';
		if ( data.chi2 !== null ) {
			var sigColor = data.significant ? '#338B45' : '#9C9182';
			var sigText  = data.significant ? 'Statistički značajno' : 'Nije statistički značajno';
			html += '<span class="pf-ab-sig-badge" style="color:' + sigColor + ';background:' + sigColor + '15">';
			html += sigText + ' (p ' + ( data.pvalue || '—' ) + ', χ²=' + data.chi2 + ')';
			html += '</span>';
		} else {
			html += '<span class="pf-ab-sig-badge" style="color:#9C9182;background:#9C918215">Nedovoljno podataka za statističku analizu</span>';
		}
		html += '</div>';

		// Preporuka
		if ( data.recommendation ) {
			var recColor = data.significant ? '#338B45' : '#B58A00';
			html += '<div class="pf-ab-recommendation" style="border-color:' + recColor + ';color:' + recColor + '">';
			html += '<span class="dashicons dashicons-lightbulb"></span> ' + data.recommendation;
			html += '</div>';
		}

		html += '</div>'; // pf-ab-results-inner
		container.innerHTML = html;
	}

	/* ---------------------------------------------------------
	 *  Učitaj rezultate za svaki test
	 * --------------------------------------------------------- */
	function loadTestResults(container, testId) {
		fetch(ajaxUrl + '?action=pf_get_ab_data&test_id=' + testId + '&days=' + days + '&nonce=' + encodeURIComponent(nonce), {
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json.success) {
					container.innerHTML = '<p style="color:#9C9182;padding:16px;">Nema podataka za ovaj test.</p>';
					return;
				}
				renderTestResults(container, json.data);
			})
			.catch(function () {
				container.innerHTML = '<p style="color:#C44545;padding:16px;">Greška pri učitavanju rezultata.</p>';
			});
	}

	/* ---------------------------------------------------------
	 *  Kreiranje novog testa
	 * --------------------------------------------------------- */
	function initCreate() {
		var splitInput = document.getElementById('pf-ab-split');
		var splitLabel = document.getElementById('pf-ab-split-label');

		if (splitInput && splitLabel) {
			splitInput.addEventListener('input', function () {
				var v = parseInt(this.value, 10);
				splitLabel.textContent = v + '% A / ' + (100 - v) + '% B';
			});
		}

		var createBtn = document.getElementById('pf-ab-create-btn');
		if (!createBtn) return;

		createBtn.addEventListener('click', function () {
			var name   = (document.getElementById('pf-ab-name')   || {}).value || '';
			var form_a = (document.getElementById('pf-ab-form-a') || {}).value || '';
			var form_b = (document.getElementById('pf-ab-form-b') || {}).value || '';
			var split  = (document.getElementById('pf-ab-split')  || {}).value || '50';

			if (!name.trim() || !form_a || !form_b) {
				alert('Popuni naziv testa i odaberi obje forme.');
				return;
			}
			if (form_a === form_b) {
				alert('Varijanta A i B moraju biti različite forme.');
				return;
			}

			createBtn.disabled = true;
			createBtn.textContent = 'Kreiranje...';

			var fd = new FormData();
			fd.append('action', 'pf_save_ab_test');
			fd.append('nonce', nonce);
			fd.append('ab_action', 'create');
			fd.append('name', name);
			fd.append('form_a', form_a);
			fd.append('form_b', form_b);
			fd.append('traffic_split', split);

			fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (json.success) {
						window.location.reload();
					} else {
						alert('Greška: ' + (json.data || 'Pokušaj ponovno.'));
						createBtn.disabled = false;
						createBtn.textContent = 'Pokreni test';
					}
				});
		});
	}

	/* ---------------------------------------------------------
	 *  Završi / Obriši test akcije
	 * --------------------------------------------------------- */
	function initTestActions() {
		document.querySelectorAll('.pf-ab-end-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var testId = this.dataset.testId;
				var winner = prompt('Upiši pobjednika (A ili B), ili ostavi prazno za automatsku odluku:');
				if (winner === null) return; // cancel
				winner = winner.trim().toUpperCase();
				if (winner && winner !== 'A' && winner !== 'B') {
					alert('Unesi A ili B.');
					return;
				}

				var fd = new FormData();
				fd.append('action', 'pf_save_ab_test');
				fd.append('nonce', nonce);
				fd.append('ab_action', 'end');
				fd.append('test_id', testId);
				fd.append('winner', winner);

				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function () { window.location.reload(); });
			});
		});

		document.querySelectorAll('.pf-ab-delete-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (!confirm('Obrisati ovaj A/B test? Analitički podaci ostaju, samo se briše definicija testa.')) return;

				var testId = this.dataset.testId;
				var fd = new FormData();
				fd.append('action', 'pf_save_ab_test');
				fd.append('nonce', nonce);
				fd.append('ab_action', 'delete');
				fd.append('test_id', testId);

				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function () { window.location.reload(); });
			});
		});
	}

	/* ---------------------------------------------------------
	 *  Init
	 * --------------------------------------------------------- */
	document.addEventListener('DOMContentLoaded', function () {
		initCreate();
		initTestActions();

		// Učitaj rezultate za svaki prikazani test
		document.querySelectorAll('.pf-ab-results[data-test-id]').forEach(function (el) {
			var testId = el.dataset.testId;
			loadTestResults(el, testId);
		});
	});
})();
