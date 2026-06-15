(function () {
	'use strict';

	var cfg     = window.pfAnalyticsInit || { formId: 0, days: 30 };
	var nonce   = (window.pfAnalytics || {}).nonce   || '';
	var ajaxUrl = (window.pfAnalytics || {}).ajaxUrl || '/wp-admin/admin-ajax.php';

	var chartDaily  = null;
	var chartSteps  = null;
	var choiceCharts = [];

	var COLORS = {
		view:    '#2271b1',
		start:   '#B58A00',
		submit:  '#338B45',
		abandon: '#C44545',
		accent:  '#B5654A',
	};

	/* ---------------------------------------------------------
	 *  Format sekundi u "X min Y sec"
	 * --------------------------------------------------------- */
	function formatTime(sec) {
		if (!sec || sec <= 0) return '—';
		if (sec < 60) return sec + ' sek';
		var m = Math.floor(sec / 60);
		var s = sec % 60;
		return m + ' min' + (s ? ' ' + s + ' sek' : '');
	}

	/* ---------------------------------------------------------
	 *  Postavi stat kartice
	 * --------------------------------------------------------- */
	function setStats(data) {
		var f = data.funnel || {};

		var map = {
			pf_form_view:    f.pf_form_view    || 0,
			pf_form_start:   f.pf_form_start   || 0,
			pf_form_submit:  f.pf_form_submit  || 0,
			pf_form_abandon: f.pf_form_abandon || 0,
			conversion_rate: (f.conversion_rate || 0) + '%',
			avg_time:        formatTime(data.avg_time_sec),
		};

		Object.keys(map).forEach(function (key) {
			var el = document.querySelector('[data-key="' + key + '"].pf-stat-value');
			if (el) el.textContent = map[key];
		});
	}

	/* ---------------------------------------------------------
	 *  Chart: Trend po danima
	 * --------------------------------------------------------- */
	function renderDaily(daily) {
		var days    = Object.keys(daily).sort();
		var views   = days.map(function (d) { return daily[d].pf_form_view   || 0; });
		var starts  = days.map(function (d) { return daily[d].pf_form_start  || 0; });
		var submits = days.map(function (d) { return daily[d].pf_form_submit || 0; });
		var abandons= days.map(function (d) { return daily[d].pf_form_abandon|| 0; });

		// Format datuma: "05.06" umjesto "2025-06-05"
		var labels = days.map(function (d) {
			var parts = d.split('-');
			return parts[2] + '.' + parts[1] + '.';
		});

		var ctx = document.getElementById('pf-chart-daily');
		if (!ctx) return;

		if (chartDaily) chartDaily.destroy();

		chartDaily = new Chart(ctx, {
			type: 'line',
			data: {
				labels: labels,
				datasets: [
					{ label: 'Pregledi',  data: views,    borderColor: COLORS.view,    backgroundColor: COLORS.view    + '18', tension: 0.35, fill: true, pointRadius: 3 },
					{ label: 'Počeli',    data: starts,   borderColor: COLORS.start,   backgroundColor: COLORS.start   + '18', tension: 0.35, fill: false, pointRadius: 3 },
					{ label: 'Poslali',   data: submits,  borderColor: COLORS.submit,  backgroundColor: COLORS.submit  + '18', tension: 0.35, fill: false, pointRadius: 3 },
					{ label: 'Napustili', data: abandons, borderColor: COLORS.abandon, backgroundColor: COLORS.abandon + '18', tension: 0.35, fill: false, pointRadius: 3 },
				]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { display: false } },
				scales: {
					y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#F4EEE4' } },
					x: { grid: { color: '#F4EEE4' }, ticks: { maxTicksLimit: 12 } }
				}
			}
		});
	}

	/* ---------------------------------------------------------
	 *  Chart: Drop-off po koraku
	 * --------------------------------------------------------- */
	function renderSteps(stepsRaw, avgFill) {
		var ctx = document.getElementById('pf-chart-steps');
		if (!ctx) return;

		var note = document.getElementById('pf-avg-fill-note');

		if (!stepsRaw || !stepsRaw.length) {
			ctx.closest('.pf-chart-body').innerHTML = '<p class="pf-chart-empty">Nema podataka o napuštanju po koraku.</p>';
			if (note) note.textContent = '';
			return;
		}

		var labels = stepsRaw.map(function (r) { return 'Korak ' + r.step; });
		var values = stepsRaw.map(function (r) { return parseInt(r.cnt, 10); });

		if (chartSteps) chartSteps.destroy();

		chartSteps = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [{
					label: 'Napuštanja',
					data: values,
					backgroundColor: COLORS.abandon + 'CC',
					borderRadius: 6,
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { display: false } },
				scales: {
					y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#F4EEE4' } },
					x: { grid: { display: false } }
				}
			}
		});

		if (note) {
			note.textContent = avgFill !== null
				? 'Prosječna ispunjenost pri napuštanju: ' + avgFill + '%'
				: '';
		}
	}

	/* ---------------------------------------------------------
	 *  Charts: Popularnost odabira (po jedno za svako polje)
	 * --------------------------------------------------------- */
	function renderChoices(choiceStats) {
		var wrap = document.getElementById('pf-choice-charts');
		if (!wrap) return;

		// Uništi stare chartove
		choiceCharts.forEach(function (c) { c.destroy(); });
		choiceCharts = [];
		wrap.innerHTML = '';

		if (!choiceStats || !choiceStats.length) {
			wrap.innerHTML = '<p class="pf-chart-empty">Nema podataka o odabirima.<br><small>Vidljivo samo za forme s Select/Radio/Checkbox poljima.</small></p>';
			return;
		}

		choiceStats.forEach(function (field) {
			var labels = Object.keys(field.counts);
			var values = labels.map(function (l) { return field.counts[l]; });

			var canvasWrap = document.createElement('div');
			canvasWrap.className = 'pf-choice-chart-wrap';

			var title = document.createElement('p');
			title.className = 'pf-choice-title';
			title.textContent = field.label;
			canvasWrap.appendChild(title);

			var canvas = document.createElement('canvas');
			canvas.className = 'pf-choice-canvas';
			canvasWrap.appendChild(canvas);
			wrap.appendChild(canvasWrap);

			var palette = [
				COLORS.accent + 'CC', COLORS.view + 'CC', COLORS.start + 'CC',
				COLORS.submit + 'CC', COLORS.abandon + 'CC',
				'#7E8A6A' + 'CC', '#9C9182' + 'CC'
			];

			var chart = new Chart(canvas, {
				type: 'bar',
				data: {
					labels: labels,
					datasets: [{
						data: values,
						backgroundColor: labels.map(function (_, i) { return palette[i % palette.length]; }),
						borderRadius: 6,
					}]
				},
				options: {
					indexAxis: 'y',
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#F4EEE4' } },
						y: { grid: { display: false } }
					}
				}
			});

			choiceCharts.push(chart);
		});
	}

	/* ---------------------------------------------------------
	 *  Fetch i render svega
	 * --------------------------------------------------------- */
	function loadData(formId, days) {
		var loading = document.getElementById('pf-analytics-loading');
		if (loading) loading.style.display = 'inline-flex';

		fetch(ajaxUrl + '?action=pf_get_analytics_data&form_id=' + formId + '&days=' + days + '&nonce=' + encodeURIComponent(nonce), {
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (loading) loading.style.display = 'none';
				if (!json.success) return;

				var data = json.data;
				setStats(data);
				renderDaily(data.daily || {});
				renderSteps(data.steps_raw, data.avg_fill);
				renderChoices(data.choice_stats);
			})
			.catch(function () {
				if (loading) loading.style.display = 'none';
			});
	}

	/* ---------------------------------------------------------
	 *  Kontrole: forma i period
	 * --------------------------------------------------------- */
	document.addEventListener('DOMContentLoaded', function () {
		var formSel  = document.getElementById('pf-analytics-form');
		var dayBtns  = document.querySelectorAll('.pf-day-btn');

		var currentFormId = cfg.formId;
		var currentDays   = cfg.days;

		function reload() {
			loadData(currentFormId, currentDays);
		}

		if (formSel) {
			formSel.addEventListener('change', function () {
				currentFormId = parseInt(this.value, 10);
				reload();
			});
		}

		dayBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				dayBtns.forEach(function (b) { b.classList.remove('is-active'); });
				this.classList.add('is-active');
				currentDays = parseInt(this.dataset.days, 10);
				reload();
			});
		});

		// Inicijalno učitavanje
		reload();
	});

})();

/* ==========================================================
 *  ATTRIBUTION TAB
 * ========================================================== */

var chartSourcePie = null;

function renderAttrTable(containerId, rows, columns) {
	var wrap = document.getElementById(containerId);
	if (!wrap) return;

	if (!rows || !rows.length) {
		wrap.innerHTML = '<p class="pf-chart-empty">Nema podataka za odabrani period.</p>';
		return;
	}

	var html = '<table class="pf-attr-table wp-list-table widefat fixed"><thead><tr>';
	columns.forEach(function (col) {
		html += '<th>' + col.label + '</th>';
	});
	html += '</tr></thead><tbody>';

	rows.forEach(function (row) {
		html += '<tr>';
		columns.forEach(function (col) {
			var val = row[col.key];
			if (col.type === 'rate') {
				var color = val >= 10 ? '#338B45' : val >= 3 ? '#B58A00' : '#C44545';
				val = '<span style="color:' + color + ';font-weight:600;">' + val + '%</span>';
			} else if (col.type === 'url') {
				val = '<span title="' + val + '" style="display:block;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + val + '</span>';
			}
			html += '<td>' + (val !== null && val !== undefined ? val : '—') + '</td>';
		});
		html += '</tr>';
	});

	html += '</tbody></table>';
	wrap.innerHTML = html;
}

function renderSourcePie(top5) {
	var ctx = document.getElementById('pf-chart-sources-pie');
	if (!ctx) return;

	if (!top5 || !top5.length) {
		ctx.closest('.pf-chart-body').innerHTML = '<p class="pf-chart-empty">Nema podataka.</p>';
		return;
	}

	var labels = top5.map(function (r) { return r.source + ' / ' + r.medium; });
	var values = top5.map(function (r) { return r.submits; });
	var colors = ['#B5654A', '#2271b1', '#338B45', '#B58A00', '#7E8A6A'];

	if (chartSourcePie) chartSourcePie.destroy();

	chartSourcePie = new Chart(ctx, {
		type: 'doughnut',
		data: {
			labels: labels,
			datasets: [{
				data: values,
				backgroundColor: colors,
				borderWidth: 2,
				borderColor: '#fff'
			}]
		},
		options: {
			responsive: true,
			maintainAspectRatio: false,
			plugins: {
				legend: {
					position: 'bottom',
					labels: { font: { size: 12 }, padding: 12 }
				}
			}
		}
	});
}

function loadAttrData(formId, days) {
	var loading = document.getElementById('pf-analytics-loading');
	if (loading) loading.style.display = 'inline-flex';

	fetch(ajaxUrl + '?action=pf_get_attribution_data&form_id=' + formId + '&days=' + days + '&nonce=' + encodeURIComponent(nonce), {
		credentials: 'same-origin'
	})
		.then(function (r) { return r.json(); })
		.then(function (json) {
			if (loading) loading.style.display = 'none';
			if (!json.success) return;

			var d = json.data;

			renderSourcePie(d.top5);

			renderAttrTable('pf-attr-source-table', d.by_source, [
				{ key: 'source',          label: 'Source' },
				{ key: 'medium',          label: 'Medium' },
				{ key: 'views',           label: 'Pregledi' },
				{ key: 'starts',          label: 'Počeli' },
				{ key: 'submits',         label: 'Konverzije' },
				{ key: 'conversion_rate', label: 'Stopa', type: 'rate' }
			]);

			renderAttrTable('pf-attr-campaign-table', d.by_campaign, [
				{ key: 'campaign',        label: 'Kampanja' },
				{ key: 'source',          label: 'Source' },
				{ key: 'views',           label: 'Pregledi' },
				{ key: 'submits',         label: 'Konverzije' },
				{ key: 'conversion_rate', label: 'Stopa', type: 'rate' }
			]);

			renderAttrTable('pf-attr-landing-table', d.by_landing, [
				{ key: 'page_short', label: 'Stranica', type: 'url' },
				{ key: 'views',      label: 'Pregledi' },
				{ key: 'submits',    label: 'Konverzije' },
				{ key: 'conversion_rate', label: 'Stopa', type: 'rate' }
			]);

			renderAttrTable('pf-attr-referrer-table', d.by_referrer, [
				{ key: 'referrer_domain', label: 'Referrer' },
				{ key: 'views',           label: 'Pregledi' },
				{ key: 'submits',         label: 'Konverzije' }
			]);
		})
		.catch(function () {
			if (loading) loading.style.display = 'none';
		});
}

/* Tab switch + attribution init */
document.addEventListener('DOMContentLoaded', function () {
	var tabBtns     = document.querySelectorAll('.pf-analytics-tab');
	var tabContents = document.querySelectorAll('.pf-analytics-tab-content');
	var attrLoaded  = false;

	tabBtns.forEach(function (btn) {
		btn.addEventListener('click', function () {
			var target = this.dataset.tab;

			tabBtns.forEach(function (b) { b.classList.remove('is-active'); });
			this.classList.add('is-active');

			tabContents.forEach(function (c) {
				c.style.display = c.dataset.tab === target ? 'block' : 'none';
			});

			// Učitaj attribution podatke pri prvom otvaranju taba
			if (target === 'attribution' && !attrLoaded) {
				attrLoaded = true;
				var formSel = document.getElementById('pf-analytics-form');
				var curForm = formSel ? parseInt(formSel.value, 10) : cfg.formId;
				var curDays = cfg.days;
				document.querySelectorAll('.pf-day-btn.is-active').forEach(function (b) {
					curDays = parseInt(b.dataset.days, 10);
				});
				loadAttrData(curForm, curDays);
			}
		});
	});

	// Export button
	var exportBtn = document.getElementById('pf-attr-export-btn');
	if (exportBtn && cfg.exportAttrUrl) {
		exportBtn.addEventListener('click', function (e) {
			e.preventDefault();
			var formSel = document.getElementById('pf-analytics-form');
			var fid = formSel ? parseInt(formSel.value, 10) : cfg.formId;
			var dbt = cfg.days;
			document.querySelectorAll('.pf-day-btn.is-active').forEach(function (b) { dbt = parseInt(b.dataset.days, 10); });
			window.location.href = cfg.exportAttrUrl + '&form_id=' + fid + '&days=' + dbt;
		});
	}
});
