(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	/* ---------------------------------------------------------
	 *  Landing page - prva stranica posjeta (ne nužno forma)
	 * --------------------------------------------------------- */
	function getLandingPage() {
		var key = 'pf_landing_page';
		try {
			var existing = sessionStorage.getItem(key);
			if (existing) return existing;
			var landing = window.location.href;
			sessionStorage.setItem(key, landing);
			return landing;
		} catch (e) {
			return window.location.href;
		}
	}

	var pfLandingPage = getLandingPage();
	function getSessionId() {
		var key = 'pf_session_id';
		try {
			var id = sessionStorage.getItem(key);
			if (!id) {
				id = 'pf_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
				sessionStorage.setItem(key, id);
			}
			return id;
		} catch (e) {
			return 'pf_' + Date.now();
		}
	}

	var pfSessionId = getSessionId();

	/* ---------------------------------------------------------
	 *  GTM dataLayer push (centralno)
	 * --------------------------------------------------------- */
	function pushDataLayer(data) {
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push(data);
	}

	/* ---------------------------------------------------------
	 *  WP analytics endpoint - sprema event lokalno u bazu
	 * --------------------------------------------------------- */
	function trackToWP(payload) {
		var ajaxUrl = window.pfAjaxUrl || '/wp-admin/admin-ajax.php';
		var data    = Object.assign({}, payload, { session_id: pfSessionId });

		// sendBeacon za abandon (radi pri zatvaranju taba)
		if (payload.event === 'pf_form_abandon' && navigator.sendBeacon) {
			navigator.sendBeacon(
				ajaxUrl + '?action=pf_track_abandon',
				new Blob([JSON.stringify(data)], { type: 'application/json' })
			);
			return;
		}

		fetch(ajaxUrl + '?action=pf_track_event', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(data),
			credentials: 'same-origin',
			keepalive: true
		}).catch(function () {});
	}

	/* ---------------------------------------------------------
	 *  UTM auto-populate (iz URL-a ili sessionStorage)
	 * --------------------------------------------------------- */
	function getUtmParams() {
		var params = {};
		var utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

		var urlParams = new URLSearchParams(window.location.search);
		utmKeys.forEach(function (key) {
			var val = urlParams.get(key);
			if (val) {
				params[key] = val;
				try { sessionStorage.setItem('pf_' + key, val); } catch (e) {}
			}
		});

		utmKeys.forEach(function (key) {
			if (!params[key]) {
				try {
					var stored = sessionStorage.getItem('pf_' + key);
					if (stored) params[key] = stored;
				} catch (e) {}
			}
		});

		return params;
	}

	function populateUtmFields(form) {
		var utmParams = getUtmParams();
		form.querySelectorAll('.pf-hidden-field[data-utm]').forEach(function (input) {
			var key = input.getAttribute('data-utm');
			if (utmParams[key]) input.value = utmParams[key];
		});
	}

	/* ---------------------------------------------------------
	 *  Draft saving (sessionStorage)
	 * --------------------------------------------------------- */
	function getDraftKey(formId) { return 'pf_draft_' + formId; }

	function saveDraft(form) {
		var formId = form.getAttribute('data-form-id');
		if (!formId) return;
		var data = {};
		form.querySelectorAll('input:not([type="file"]):not([type="hidden"]):not([name="pf_nonce"]):not([name="pf_hp"]), select, textarea').forEach(function (el) {
			if (!el.name) return;
			if (el.type === 'checkbox') {
				if (!data[el.name]) data[el.name] = [];
				if (el.checked) data[el.name].push(el.value);
			} else if (el.type === 'radio') {
				if (el.checked) data[el.name] = el.value;
			} else {
				data[el.name] = el.value;
			}
		});
		try { sessionStorage.setItem(getDraftKey(formId), JSON.stringify(data)); } catch (e) {}
	}

	function restoreDraft(form) {
		var formId = form.getAttribute('data-form-id');
		if (!formId) return;
		var raw;
		try { raw = sessionStorage.getItem(getDraftKey(formId)); } catch (e) {}
		if (!raw) return;
		var data;
		try { data = JSON.parse(raw); } catch (e) { return; }
		Object.keys(data).forEach(function (name) {
			var val = data[name];
			form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]').forEach(function (el) {
				if (el.type === 'checkbox') {
					el.checked = Array.isArray(val) && val.indexOf(el.value) !== -1;
				} else if (el.type === 'radio') {
					el.checked = el.value === val;
				} else {
					el.value = val;
				}
			});
		});
	}

	function clearDraft(formId) {
		try { sessionStorage.removeItem(getDraftKey(formId)); } catch (e) {}
	}

	/* ---------------------------------------------------------
	 *  Validacija
	 * --------------------------------------------------------- */
	function validatePanel(panel) {
		var valid = true;
		panel.querySelectorAll('input, select, textarea').forEach(function (field) {
			field.classList.remove('pf-invalid');
			var fieldWrap = field.closest('.pf-field');
			if (fieldWrap && (fieldWrap.classList.contains('pf-hidden') || fieldWrap.classList.contains('pf-field-section-divider'))) return;
			if (!field.hasAttribute('required')) return;

			if (field.type === 'checkbox' || field.type === 'radio') {
				var group = panel.querySelectorAll('input[name="' + field.name + '"]');
				var checked = false;
				group.forEach(function (g) { if (g.checked) checked = true; });
				if (!checked) {
					valid = false;
					group.forEach(function (g) { g.closest('.pf-field').classList.add('pf-invalid'); });
				}
				return;
			}

			if (!field.value || !field.value.trim()) {
				valid = false;
				field.classList.add('pf-invalid');
			} else if (field.type === 'email') {
				if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
					valid = false;
					field.classList.add('pf-invalid');
				}
			}
		});
		return valid;
	}

	/* ---------------------------------------------------------
	 *  Multi-step navigation
	 * --------------------------------------------------------- */
	function goToStep(form, targetStep) {
		var panels = form.querySelectorAll('.pf-step-panel');
		var dots   = form.querySelectorAll('.pf-step-dot');
		var lines  = form.querySelectorAll('.pf-step-line');

		panels.forEach(function (p) {
			p.classList.toggle('is-active', p.getAttribute('data-step') === String(targetStep));
		});
		dots.forEach(function (d) {
			var step = parseInt(d.getAttribute('data-step'), 10);
			d.classList.remove('is-active', 'is-complete');
			if (step === targetStep) d.classList.add('is-active');
			else if (step < targetStep) d.classList.add('is-complete');
		});
		lines.forEach(function (line, idx) {
			var step = parseInt(dots[idx].getAttribute('data-step'), 10);
			line.classList.toggle('is-complete', step < targetStep);
		});
	}

	/* ---------------------------------------------------------
	 *  Smart Logic (conditional fields)
	 * --------------------------------------------------------- */
	function getFieldValue(form, name) {
		var els = form.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]');
		if (!els.length) return '';
		if (els[0].type === 'checkbox') {
			var vals = [];
			els.forEach(function (el) { if (el.checked) vals.push(el.value); });
			return vals;
		}
		if (els[0].type === 'radio') {
			var v = '';
			els.forEach(function (el) { if (el.checked) v = el.value; });
			return v;
		}
		return els[0].value;
	}

	function conditionMet(target, op, value) {
		if (Array.isArray(target)) {
			switch (op) {
				case 'not_equals': return target.indexOf(value) === -1;
				case 'contains':   return target.some(function (t) { return t.toLowerCase().indexOf(value.toLowerCase()) !== -1; });
				default:           return target.indexOf(value) !== -1;
			}
		}
		switch (op) {
			case 'not_equals': return target !== value;
			case 'contains':   return target.toLowerCase().indexOf(value.toLowerCase()) !== -1;
			default:           return target === value;
		}
	}

	function evaluateConditions(form) {
		form.querySelectorAll('[data-cond-field]').forEach(function (el) {
			var show = conditionMet(
				getFieldValue(form, el.getAttribute('data-cond-field')),
				el.getAttribute('data-cond-op'),
				el.getAttribute('data-cond-value')
			);
			el.classList.toggle('pf-hidden', !show);
			el.querySelectorAll('input, select, textarea').forEach(function (ctrl) {
				if (!show) {
					ctrl.dataset.pfRequiredDisabled = ctrl.hasAttribute('required') ? '1' : '0';
					ctrl.removeAttribute('required');
				} else if (ctrl.dataset.pfRequiredDisabled === '1') {
					ctrl.setAttribute('required', 'required');
					delete ctrl.dataset.pfRequiredDisabled;
				}
			});
		});
	}

	// Je li stranica (step panel) vidljiva prema svom uvjetu?
	function isStepVisible(form, panel) {
		var condField = panel.getAttribute('data-step-cond-field');
		if (!condField) return true; // bez uvjeta = uvijek vidljiva
		return conditionMet(
			getFieldValue(form, condField),
			panel.getAttribute('data-step-cond-op') || 'equals',
			panel.getAttribute('data-step-cond-value') || ''
		);
	}

	// Pronađi indeks sljedeće/prethodne vidljive stranice
	function findVisibleStep(form, panels, fromIdx, direction) {
		var i = fromIdx + direction;
		while (i >= 0 && i < panels.length) {
			if (isStepVisible(form, panels[i])) return i;
			i += direction;
		}
		return -1;
	}

	// Ažuriraj step indicator da prikazuje samo vidljive stranice
	function refreshStepIndicator(form, panels) {
		var dots = form.querySelectorAll('.pf-step-dot');
		var visibleCount = 0;
		panels.forEach(function (panel, idx) {
			var visible = isStepVisible(form, panel);
			if (dots[idx]) {
				dots[idx].style.display = visible ? '' : 'none';
				var line = dots[idx].nextElementSibling;
				if (line && line.classList.contains('pf-step-line')) {
					line.style.display = visible ? '' : 'none';
				}
			}
			if (visible) visibleCount++;
		});
	}

	/* ---------------------------------------------------------
	 *  Pomoćna: izračunaj % ispunjenosti forme
	 * --------------------------------------------------------- */
	function calcFillPercent(form) {
		var inputs = Array.prototype.slice.call(
			form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([name="pf_hp"]):not([name="pf_nonce"]), select, textarea')
		);
		if (!inputs.length) return 0;

		var filled = inputs.filter(function (el) {
			if (el.closest('.pf-field') && el.closest('.pf-field').classList.contains('pf-hidden')) return false;
			if (el.type === 'radio' || el.type === 'checkbox') return el.checked;
			return el.value && el.value.trim() !== '';
		});

		return Math.round((filled.length / inputs.length) * 100);
	}

	/* ---------------------------------------------------------
	 *  Pomoćna: dohvati UTM za enrichment eventi
	 * --------------------------------------------------------- */
	function getUtmPayload() {
		var utm = getUtmParams();
		return {
			utm_source:   utm.utm_source   || '',
			utm_medium:   utm.utm_medium   || '',
			utm_campaign: utm.utm_campaign || '',
			utm_term:     utm.utm_term     || '',
			utm_content:  utm.utm_content  || '',
			landing_page: pfLandingPage    || '',
			page_url:     window.location.href,
			referrer:     document.referrer || ''
		};
	}

	/* =========================================================
	 *  FUNNEL EVENTI
	 * =========================================================
	 *
	 *  EVENT 1: pf_form_view
	 *  Okida se kada forma uđe u viewport (≥50% vidljivo).
	 *  Razlikuje "stranica je otvorena" od "korisnik je vidio formu".
	 *  GTM trigger: Custom Event → event equals pf_form_view
	 *
	 *  EVENT 2: pf_form_start
	 *  Okida se jednom kada korisnik dotakne/klikne prvo polje.
	 *  Ključan za razlikovanje "vidio" vs "počeo ispunjavati".
	 *  GTM trigger: Custom Event → event equals pf_form_start
	 *
	 *  EVENT 3: pf_step_complete
	 *  Okida se kada korisnik uspješno prođe na sljedeći korak.
	 *  Sadrži step_from i step_to - gradi funnel po koracima.
	 *  GTM trigger: Custom Event → event equals pf_step_complete
	 *
	 *  EVENT 4: pf_form_abandon
	 *  Okida se pri napuštanju stranice ako forma NIJE poslana.
	 *  Sadrži zadnji korak i % ispunjenosti - otkriva gdje se gubi.
	 *  GTM trigger: Custom Event → event equals pf_form_abandon
	 *  Napomena: šalje se sendBeacon (radi i pri zatvaranju taba).
	 *
	 *  EVENT 5: pf_form_submit (enriched)
	 *  Postojeći event, sada s UTM podacima i brojem koraka.
	 *  GTM trigger: Custom Event → event equals pf_form_submit
	 *
	 * ========================================================= */

	function initFunnelTracking(form, panels) {
		var formId    = form.getAttribute('data-form-id');
		var formTitle = form.getAttribute('data-form-title') || formId;
		var abVariant = form.getAttribute('data-ab-variant') || null;
		var submitted = false;
		var started   = false;
		var currentStepIdx = 0;

		function basePayload(extra) {
			var p = Object.assign({
				form_id:    formId,
				form_title: formTitle,
				total_steps: panels.length
			}, extra || {});
			if (abVariant) p.ab_variant = abVariant;
			return p;
		}

		if ('IntersectionObserver' in window) {
			var viewFired = false;
			var observer = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting && !viewFired) {
						viewFired = true;
						var payload = basePayload({ event: 'pf_form_view' });
						pushDataLayer(payload);
						trackToWP(payload);
						observer.disconnect();
					}
				});
			}, { threshold: 0.5 });
			observer.observe(form);
		} else {
			var payload0 = basePayload({ event: 'pf_form_view' });
			pushDataLayer(payload0);
			trackToWP(payload0);
		}

		// -- EVENT 2: pf_form_start --
		function onFirstInteraction() {
			if (started) return;
			started = true;
			form.removeEventListener('focusin', onFirstInteraction);
			form.removeEventListener('click',   onFirstInteraction);

			var payload = Object.assign(basePayload({ event: 'pf_form_start' }), getUtmPayload());
			pushDataLayer(payload);
			trackToWP(payload);
		}
		form.addEventListener('focusin', onFirstInteraction);
		form.addEventListener('click',   onFirstInteraction);

		// -- EVENT 3: pf_step_complete --
		function trackStepComplete(fromIdx, toIdx) {
			currentStepIdx = toIdx;
			var payload = basePayload({
				event:        'pf_step_complete',
				step_from:    fromIdx + 1,
				step_to:      toIdx + 1,
				fill_percent: calcFillPercent(form)
			});
			pushDataLayer(payload);
			trackToWP(payload);
		}

		// -- EVENT 4: pf_form_abandon --
		function sendAbandon() {
			if (submitted || !started) return;

			var payload = basePayload({
				event:        'pf_form_abandon',
				last_step:    currentStepIdx + 1,
				fill_percent: calcFillPercent(form)
			});

			trackToWP(payload);
			pushDataLayer(payload);
		}

		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'hidden') sendAbandon();
		});
		window.addEventListener('pagehide', sendAbandon);

		function markSubmitted() { submitted = true; }

		return {
			trackStepComplete: trackStepComplete,
			markSubmitted:     markSubmitted
		};
	}

	/* ---------------------------------------------------------
	 *  GTM field tracking (postojeći, malo poboljšan)
	 * --------------------------------------------------------- */
	function initFieldTracking(form) {
		var formId  = form.getAttribute('data-form-id');
		var tracked = {};

		form.addEventListener('change', function (e) {
			var el    = e.target;
			var field = el.closest('.pf-field');
			if (!field || !el.name) return;

			// Dedupliciraj (isti name+value ne prijavljuj dvaput)
			var key = el.name + ':' + (el.value || '');
			if (tracked[key]) return;
			tracked[key] = true;

			pushDataLayer({
				event:       'pf_field_interaction',
				form_id:     formId,
				field_name:  el.name,
				field_value: el.type === 'password' ? '' : el.value
			});
		});
	}

	/* ---------------------------------------------------------
	 *  Init forme
	 * --------------------------------------------------------- */
	function initForm(form) {
		var panels = Array.prototype.slice.call(form.querySelectorAll('.pf-step-panel'));
		var formId = form.getAttribute('data-form-id');

		// Osvježi nonce (keširane stranice)
		var nonceField = form.querySelector('input[name="pf_nonce"]');
		if (nonceField && formId) {
			fetch((window.pfAjaxUrl || '/wp-admin/admin-ajax.php') + '?action=pf_refresh_nonce&form_id=' + encodeURIComponent(formId), {
				credentials: 'same-origin'
			})
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (json && json.success && json.data && json.data.nonce) {
						nonceField.value = json.data.nonce;
					}
				})
				.catch(function () {});
		}

		populateUtmFields(form);
		restoreDraft(form);
		evaluateConditions(form);
		refreshStepIndicator(form, panels);
		initFieldTracking(form);

		// Funnel tracking - vraća metode za Step i Submit
		var funnel = initFunnelTracking(form, panels);

		form.addEventListener('input',  function () { evaluateConditions(form); refreshStepIndicator(form, panels); saveDraft(form); });
		form.addEventListener('change', function () { evaluateConditions(form); refreshStepIndicator(form, panels); saveDraft(form); });

		// Multi-step navigacija (preskače stranice čiji uvjet nije ispunjen)
		function updateStepButtons() {
			panels.forEach(function (panel, idx) {
				var nextBtn = panel.querySelector('.pf-next');
				var submitBtn = panel.querySelector('.pf-submit');
				if (!nextBtn || !submitBtn) return;
				var hasNextVisible = findVisibleStep(form, panels, idx, +1) !== -1;
				nextBtn.style.display   = hasNextVisible ? '' : 'none';
				submitBtn.style.display = hasNextVisible ? 'none' : '';
			});
		}
		updateStepButtons();

		form.addEventListener('input',  updateStepButtons);
		form.addEventListener('change', updateStepButtons);

		panels.forEach(function (panel, panelIdx) {
			var nextBtn = panel.querySelector('.pf-next');
			var prevBtn = panel.querySelector('.pf-prev');

			if (nextBtn) {
				nextBtn.addEventListener('click', function () {
					if (!validatePanel(panel)) return;
					var nextIdx = findVisibleStep(form, panels, panelIdx, +1);
					if (nextIdx === -1) return;
					var nextStep = parseInt(panels[nextIdx].getAttribute('data-step'), 10);
					goToStep(form, nextStep);
					funnel.trackStepComplete(panelIdx, nextIdx);
				});
			}

			if (prevBtn) {
				prevBtn.addEventListener('click', function () {
					var prevIdx = findVisibleStep(form, panels, panelIdx, -1);
					if (prevIdx === -1) return;
					goToStep(form, parseInt(panels[prevIdx].getAttribute('data-step'), 10));
				});
			}
		});

		// Submit
		form.addEventListener('submit', function (e) {
			e.preventDefault();

			var lastPanel = panels[panels.length - 1];
			if (!validatePanel(lastPanel)) return;

			var submitBtn = form.querySelector('.pf-submit');
			var msgBox    = form.querySelector('.pf-message');

			if (submitBtn) {
				submitBtn.setAttribute('disabled', 'disabled');
				submitBtn.dataset.originalText = submitBtn.textContent;
				submitBtn.textContent = 'Slanje...';
			}

			var formData = new FormData(form);
			formData.append('action', 'pf_submit_form');
			formData.append('form_id', formId);

			fetch(window.pfAjaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			})
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (json.success) {
						msgBox.textContent = form.getAttribute('data-success') || 'Hvala na upitu!';
						msgBox.classList.remove('is-error');
						msgBox.classList.add('is-success');
						form.classList.add('pf-submitted');
						clearDraft(formId);

						// Označi kao završeno (blokira abandon event)
						funnel.markSubmitted();

						// EVENT 5: pf_form_submit (enriched)
						var submitPayload = Object.assign(basePayload({
							event:        'pf_form_submit',
							form_id:      json.data && json.data.form_id,
							fill_percent: 100
						}), getUtmPayload());
						submitPayload.form_title = json.data && json.data.form_title;

						pushDataLayer(submitPayload);
						trackToWP(submitPayload);

						// GA4 gtag (client-side, ako je konfiguriran)
						if (window.gtag && window.pfGa4EventName) {
							window.gtag('event', window.pfGa4EventName, {
								form_id:    submitPayload.form_id,
								form_title: submitPayload.form_title
							});
						}

						// Meta Pixel (client-side, ako je konfiguriran)
						if (window.fbq && window.pfMetaPixelEvent) {
							window.fbq('track', window.pfMetaPixelEvent, {
								content_name: submitPayload.form_title,
								content_type: 'form'
							});
						}

					} else {
						msgBox.textContent = (json.data && json.data.message) || 'Greška kod slanja. Pokušajte ponovno.';
						msgBox.classList.remove('is-success');
						msgBox.classList.add('is-error');
						if (submitBtn) {
							submitBtn.removeAttribute('disabled');
							submitBtn.textContent = submitBtn.dataset.originalText || 'Pošalji';
						}
					}
				})
				.catch(function () {
					msgBox.textContent = 'Greška u komunikaciji s poslužiteljem. Pokušajte ponovno.';
					msgBox.classList.remove('is-success');
					msgBox.classList.add('is-error');
					if (submitBtn) {
						submitBtn.removeAttribute('disabled');
						submitBtn.textContent = submitBtn.dataset.originalText || 'Pošalji';
					}
				});
		});
	}

	ready(function () {
		document.querySelectorAll('.pf-form').forEach(initForm);

		// is-checked klasa na label karticama (checkbox/radio)
		document.querySelectorAll('.pf-form').forEach(function (form) {
			function syncChecked() {
				form.querySelectorAll('.pf-inline-option').forEach(function (label) {
					var inp = label.querySelector('input[type="checkbox"], input[type="radio"]');
					if (inp) {
						label.classList.toggle('is-checked', inp.checked);
					}
				});
			}
			form.addEventListener('change', syncChecked);
			syncChecked();
		});

		// File drop zone
		document.querySelectorAll('.pf-file-zone').forEach(function (zone) {
			var input    = zone.querySelector('.pf-file-input');
			var dropArea = zone.querySelector('.pf-file-droparea');
			var fileList = zone.querySelector('.pf-file-list');
			var MAX      = 10;
			var selectedFiles = [];

			function formatSize(bytes) {
				if (bytes < 1024)       return bytes + ' B';
				if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' KB';
				return (bytes / 1048576).toFixed(1) + ' MB';
			}

			function getIcon(name) {
				var ext = (name.split('.').pop() || '').toLowerCase();
				var icons = {
					pdf:  '📄', jpg: '🖼', jpeg: '🖼', png: '🖼',
					dwg:  '📐', dxf: '📐', ifc: '🏗',  skp: '🏗',
					doc:  '📝', docx: '📝',
					zip:  '🗜',  rar: '🗜',
				};
				return icons[ext] || '📎';
			}

			function renderList() {
				fileList.innerHTML = '';
				selectedFiles.forEach(function (file, idx) {
					var li = document.createElement('li');
					li.className = 'pf-file-item';
					li.innerHTML =
						'<span class="pf-file-item-icon">' + getIcon(file.name) + '</span>' +
						'<span class="pf-file-item-name">' + file.name + '</span>' +
						'<span class="pf-file-item-size">' + formatSize(file.size) + '</span>' +
						'<button type="button" class="pf-file-item-remove" data-idx="' + idx + '" aria-label="Ukloni">&times;</button>';
					fileList.appendChild(li);
				});
				// Ažuriraj FileList na inputu
				var dt = new DataTransfer();
				selectedFiles.forEach(function (f) { dt.items.add(f); });
				input.files = dt.files;

				zone.classList.toggle('has-files', selectedFiles.length > 0);
				dropArea.querySelector('.pf-file-main-text').style.display = selectedFiles.length >= MAX ? 'none' : '';
			}

			function addFiles(newFiles) {
				Array.from(newFiles).forEach(function (f) {
					if (selectedFiles.length >= MAX) return;
					// Provjeri duplikate
					var exists = selectedFiles.some(function (s) { return s.name === f.name && s.size === f.size; });
					if (!exists) selectedFiles.push(f);
				});
				renderList();
			}

			// Klik na zonu → otvori file picker
			dropArea.addEventListener('click', function () { input.click(); });
			dropArea.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
			});

			// Input change
			input.addEventListener('change', function () {
				addFiles(this.files);
				this.value = '';
			});

			// Drag & drop
			dropArea.addEventListener('dragover', function (e) {
				e.preventDefault();
				zone.classList.add('is-dragover');
			});
			dropArea.addEventListener('dragleave', function () {
				zone.classList.remove('is-dragover');
			});
			dropArea.addEventListener('drop', function (e) {
				e.preventDefault();
				zone.classList.remove('is-dragover');
				addFiles(e.dataTransfer.files);
			});

			// Ukloni datoteku
			fileList.addEventListener('click', function (e) {
				var btn = e.target.closest('.pf-file-item-remove');
				if (!btn) return;
				var idx = parseInt(btn.dataset.idx, 10);
				selectedFiles.splice(idx, 1);
				renderList();
			});
		});
	});
})();
