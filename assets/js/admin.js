jQuery(function ($) {
	'use strict';

	// Bail ako builder nije na stranici (npr. Upiti/Postavke)
	if (!document.getElementById('pf-steps-container')) {
		return;
	}

	// ODMAH premjesti modale na <body> i sakrij ih (izbjegava prikaz na dnu stranice)
	$('#pf-preview-modal, #pf-template-modal').each(function () {
		$(this).appendTo('body').removeClass('is-open').css('display', 'none');
	});

	var pfSteps       = [];
	var fieldsByUid   = {};
	var selectedUid   = null;
	var uidCounter    = 1;
	var activeStep    = 0;

	var pfFieldTypes = window.pfFieldTypes || {};
	var CHOICE_TYPES = ['select', 'radio', 'checkbox'];

	/* ---------------------------------------------------------
	 *  Pomoćne funkcije
	 * --------------------------------------------------------- */
	function escapeHtml(str) {
		return $('<div>').text(str || '').html();
	}

	function escapeAttr(str) {
		return (str || '').replace(/"/g, '&quot;');
	}

	function slugify(str) {
		var s = (str || '')
			.toLowerCase()
			.replace(/[čć]/g, 'c')
			.replace(/š/g, 's')
			.replace(/ž/g, 'z')
			.replace(/đ/g, 'dj')
			.replace(/[^a-z0-9]+/g, '_')
			.replace(/^_+|_+$/g, '');
		return s || 'polje';
	}

	function getNextUid() {
		return 'f' + (uidCounter++);
	}

	function ensureUid(field) {
		if (!field._uid) {
			field._uid = getNextUid();
		}
		return field._uid;
	}

	function allFieldsFlat() {
		var out = [];
		pfSteps.forEach(function (step) {
			step.rows.forEach(function (row) {
				row.cells.forEach(function (cell) {
					cell.forEach(function (f) {
						out.push(f);
					});
				});
			});
		});
		return out;
	}

	function generateUniqueName(label, excludeField) {
		var base = slugify(label);
		var name = base;
		var n = 2;
		var all = allFieldsFlat();
		while (all.some(function (f) { return f !== excludeField && f.name === name; })) {
			name = base + '_' + n;
			n++;
		}
		return name;
	}

	function defaultFieldForType(type) {
		var label = pfFieldTypes[type] || type;
		var field = {
			type: type,
			label: label,
			name: '',
			required: false,
			placeholder: '',
			options: CHOICE_TYPES.indexOf(type) > -1 ? ['Opcija 1', 'Opcija 2'] : [],
			condition: null,
			_nameAuto: true
		};
		if (type === 'html') {
			field.placeholder = '<p>Tekst...</p>';
		}
		field.name = generateUniqueName(label, field);
		return field;
	}

	/* ---------------------------------------------------------
	 *  Render preview polja (koristi frontend.css klase)
	 * --------------------------------------------------------- */
	function renderFieldPreviewHTML(field) {
		var label = field.label || '(bez naziva)';
		var req   = field.required ? ' <span class="pf-required-mark">*</span>' : '';

		// U builderu polja s uvjetom NISU skrivena — uvijek vidljiva za uređivanje.
		// (display:none za uvjete primjenjuje se samo u Pregledu/frontendu)
		var condAttrs = '';
		if (field.condition) {
			var cond = field.condition.rules
				? field.condition
				: { match: 'all', rules: [{ field: field.condition.field, operator: field.condition.operator || 'equals', value: field.condition.value || '' }] };
			condAttrs = ' data-pf-cond=\'' + JSON.stringify(cond).replace(/'/g, '&#39;') + '\'';
		}

		// VAŽNO: u builderu NIKAD ne skrivaj polje — uvijek prazan style
		var initialStyle = '';

		if (field.type === 'html') {
			return '<div class="pf-field pf-field-html"' + condAttrs + initialStyle + '>'
				+ (field.placeholder || '<em>Info blok</em>') + '</div>';
		}

		if (field.type === 'hidden') {
			var utmInfo = field.utm_source ? ('UTM: ' + field.utm_source) : ('vrijednost: ' + (field.default_value || '(prazno)'));
			return '<div class="pf-field pf-field-hidden"' + condAttrs + initialStyle + '>'
				+ '<span class="dashicons dashicons-hidden" style="color:#9C9182;"></span> '
				+ '<strong>' + escapeHtml(label) + '</strong>'
				+ ' <em style="color:#9C9182;">— skriveno polje (' + escapeHtml(utmInfo) + ')</em></div>';
		}

		if (field.type === 'section_divider') {
			var html = '<div class="pf-field pf-field-section-divider"' + condAttrs + initialStyle + '>';
			if (label) html += '<div class="pf-divider-title">' + escapeHtml(label) + '</div>';
			html += '<div class="pf-divider-line"></div>';
			if (field.placeholder) html += '<div class="pf-divider-desc">' + escapeHtml(field.placeholder) + '</div>';
			html += '</div>';
			return html;
		}

		if (field.type === 'image_choice') {
			var html = '<div class="pf-field pf-field-image-choice"' + condAttrs + initialStyle + '>'
				+ '<fieldset><legend>' + escapeHtml(label) + (field.required ? ' <span class="pf-required-mark">*</span>' : '') + '</legend>'
				+ '<div class="pf-image-choice-grid">';
			(field.options || []).forEach(function (opt) {
				var parts    = opt.split('|');
				var optLabel = (parts[0] || '').trim();
				var optImg   = (parts[1] || '').trim();
				html += '<label class="pf-image-choice-item"><input type="checkbox" disabled>';
				html += '<span class="pf-image-choice-card">';
				if (optImg) {
					html += '<span class="pf-image-choice-img" style="background-image:url(\'' + escapeAttr(optImg) + '\')"></span>';
				} else {
					html += '<span class="pf-image-choice-icon"><span class="dashicons dashicons-format-image"></span></span>';
				}
				html += '<span class="pf-image-choice-label">' + escapeHtml(optLabel) + '</span></span></label>';
			});
			html += '</div></fieldset></div>';
			return html;
		}

		// Sva ostala polja
		var html = '<div class="pf-field pf-field-' + field.type + '"' + condAttrs + initialStyle + '>';

		if (field.type !== 'checkbox' && field.type !== 'radio') {
			html += '<label>' + escapeHtml(label) + req + '</label>';
		}

		switch (field.type) {
			case 'textarea':
				html += '<textarea disabled placeholder="' + escapeAttr(field.placeholder || '') + '"></textarea>';
				break;

			case 'select':
				html += '<select disabled><option value="">' + (field.placeholder || 'Odaberite...') + '</option>';
				(field.options || []).forEach(function (o) {
					html += '<option>' + escapeHtml(o) + '</option>';
				});
				html += '</select>';
				break;

			case 'radio':
				html += '<fieldset><legend>' + escapeHtml(label) + req + '</legend>';
				(field.options || []).forEach(function (o) {
					html += '<label class="pf-inline-option"><input type="radio" disabled> ' + escapeHtml(o) + '</label>';
				});
				html += '</fieldset>';
				break;

			case 'checkbox':
				html += '<fieldset><legend>' + escapeHtml(label) + req + '</legend>';
				(field.options || []).forEach(function (o) {
					html += '<label class="pf-inline-option"><input type="checkbox" disabled> ' + escapeHtml(o) + '</label>';
				});
				html += '</fieldset>';
				break;

			case 'file':
				html += '<input type="file" disabled>';
				if (field.placeholder) {
					html += '<p class="pf-field-hint">Dozvoljeno: ' + escapeHtml(field.placeholder) + ' (maks. 10 MB)</p>';
				}
				break;

			case 'date':
				html += '<div class="pf-date-wrap"><input type="text" disabled placeholder="DD/MM/YYYY" class="pf-date-input"><span class="pf-date-icon"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M1 7h14" stroke="currentColor" stroke-width="1.5"/><path d="M5 1v4M11 1v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span></div>';
				break;

			case 'rating':
				var maxR = field.max_rating || 10;
				var lowL = field.label_low  || '1 – minimum';
				var higL = field.label_high || maxR + ' – maksimum';
				html += '<div class="pf-rating-wrap" data-max="' + maxR + '">';
				html += '<div class="pf-rating-buttons">';
				for (var ri = 1; ri <= maxR; ri++) {
					html += '<button type="button" class="pf-rating-btn" disabled>' + ri + '</button>';
				}
				html += '</div>';
				html += '<div class="pf-rating-labels"><span class="pf-rating-label-low">' + escapeHtml(lowL) + '</span><span class="pf-rating-label-high">' + escapeHtml(higL) + '</span></div>';
				html += '</div>';
				break;

			case 'email':
				html += '<div class="pf-input-icon-wrap"><span class="pf-input-icon pf-input-icon-left"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M1 5.5l7 4 7-4" stroke="currentColor" stroke-width="1.5"/></svg></span><input type="email" disabled placeholder="' + escapeAttr(field.placeholder || 'ime@domena.com') + '" class="pf-input-with-icon"></div>';
				break;

			case 'tel':
				html += '<div class="pf-input-icon-wrap"><span class="pf-input-icon pf-input-icon-left"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 2h3l1.5 3.5-2 1.5c1 2 2.5 3.5 4.5 4.5l1.5-2L15 11v3c0 .5-.5 1-1 1C5 15 1 8 1 3c0-.5.5-1 1-1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span><input type="tel" disabled placeholder="' + escapeAttr(field.placeholder || '+385 9X XXX XXXX') + '" class="pf-input-with-icon"></div>';
				break;

			default:
				var inputType = field.type === 'number' ? 'number' : 'text';
				html += '<input type="' + inputType + '" disabled placeholder="' + escapeAttr(field.placeholder || '') + '">';
		}

		html += '</div>';
		return html;
	}

	// Preview modal koristi interaktivne inpute (za conditional logic evaluaciju)
	function buildPreviewFieldHTML(field) {
		var label = field.label || '(bez naziva)';
		var req   = field.required ? ' <span class="pf-required-mark">*</span>' : '';

		var condAttrs = '';
		if (field.condition) {
			var cond = field.condition.rules
				? field.condition
				: { match: 'all', rules: [{ field: field.condition.field, operator: field.condition.operator || 'equals', value: field.condition.value || '' }] };
			condAttrs = ' data-pf-cond=\'' + JSON.stringify(cond).replace(/'/g, '&#39;') + '\'';
		}
		var initialStyle = condAttrs ? ' style="display:none;"' : '';

		if (field.type === 'html') {
			return '<div class="pf-field pf-field-html"' + condAttrs + initialStyle + '>' + (field.placeholder || '') + '</div>';
		}
		if (field.type === 'hidden') return '';
		if (field.type === 'section_divider') {
			var h = '<div class="pf-field pf-field-section-divider"' + condAttrs + initialStyle + '>';
			if (label) h += '<div class="pf-divider-title">' + escapeHtml(label) + '</div>';
			h += '<div class="pf-divider-line"></div>';
			if (field.placeholder) h += '<div class="pf-divider-desc">' + escapeHtml(field.placeholder) + '</div>';
			return h + '</div>';
		}
		if (field.type === 'image_choice') {
			var h = '<div class="pf-field pf-field-image-choice"' + condAttrs + initialStyle + '><fieldset><legend>' + escapeHtml(label) + req + '</legend><div class="pf-image-choice-grid">';
			(field.options || []).forEach(function (opt) {
				var parts = opt.split('|');
				var ol = (parts[0] || '').trim(), oi = (parts[1] || '').trim();
				h += '<label class="pf-image-choice-item"><input type="checkbox" name="' + escapeAttr(field.name) + '[]" value="' + escapeAttr(ol) + '">';
				h += '<span class="pf-image-choice-card">';
				h += oi ? '<span class="pf-image-choice-img" style="background-image:url(\'' + escapeAttr(oi) + '\')"></span>'
				        : '<span class="pf-image-choice-icon"><span class="dashicons dashicons-format-image"></span></span>';
				h += '<span class="pf-image-choice-label">' + escapeHtml(ol) + '</span></span></label>';
			});
			return h + '</div></fieldset></div>';
		}

		var h = '<div class="pf-field pf-field-' + field.type + '"' + condAttrs + initialStyle + '>';
		if (field.type !== 'checkbox' && field.type !== 'radio') {
			h += '<label>' + escapeHtml(label) + req + '</label>';
		}
		switch (field.type) {
			case 'textarea':
				h += '<textarea name="' + escapeAttr(field.name) + '" placeholder="' + escapeAttr(field.placeholder || '') + '"></textarea>';
				break;
			case 'select':
				h += '<select name="' + escapeAttr(field.name) + '"><option value="">Odaberite...</option>';
				(field.options || []).forEach(function (o) { h += '<option value="' + escapeAttr(o) + '">' + escapeHtml(o) + '</option>'; });
				h += '</select>';
				break;
			case 'radio':
				h += '<fieldset><legend>' + escapeHtml(label) + req + '</legend>';
				(field.options || []).forEach(function (o) {
					h += '<label class="pf-inline-option"><input type="radio" name="' + escapeAttr(field.name) + '" value="' + escapeAttr(o) + '"> ' + escapeHtml(o) + '</label>';
				});
				h += '</fieldset>';
				break;
			case 'checkbox':
				h += '<fieldset><legend>' + escapeHtml(label) + req + '</legend>';
				(field.options || []).forEach(function (o) {
					h += '<label class="pf-inline-option"><input type="checkbox" name="' + escapeAttr(field.name) + '[]" value="' + escapeAttr(o) + '"> ' + escapeHtml(o) + '</label>';
				});
				h += '</fieldset>';
				break;
			case 'file':
				h += '<div class="pf-file-zone">'
					+ '<div class="pf-file-droparea">'
					+ '<div class="pf-file-icon"><svg width="36" height="36" viewBox="0 0 36 36" fill="none"><path d="M18 4v18M10 12l8-8 8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 26v2a2 2 0 002 2h20a2 2 0 002-2v-2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></div>'
					+ '<p class="pf-file-main-text">Povuci datoteke ovdje ili <span class="pf-file-browse">odaberi s računala</span></p>'
					+ '<p class="pf-file-sub-text">PDF, JPG, DWG, DXF, IFC, ZIP &middot; max 10 datoteka &middot; 20 MB po datoteci</p>'
					+ '</div></div>';
				break;
			case 'date':
				h += '<div class="pf-date-wrap"><input type="text" name="' + escapeAttr(field.name) + '" placeholder="DD/MM/YYYY" maxlength="10" autocomplete="off" class="pf-date-input"><span class="pf-date-icon"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M1 7h14" stroke="currentColor" stroke-width="1.5"/><path d="M5 1v4M11 1v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span></div>';
				break;
			case 'rating':
				var maxRp = field.max_rating || 10;
				h += '<div class="pf-rating-wrap" data-max="' + maxRp + '"><input type="hidden" name="' + escapeAttr(field.name) + '"><div class="pf-rating-buttons">';
				for (var rp = 1; rp <= maxRp; rp++) h += '<button type="button" class="pf-rating-btn" data-value="' + rp + '">' + rp + '</button>';
				h += '</div><div class="pf-rating-labels"><span class="pf-rating-label-low">' + escapeHtml(field.label_low || '1 – minimum') + '</span><span class="pf-rating-label-high">' + escapeHtml(field.label_high || maxRp + ' – maksimum') + '</span></div></div>';
				break;
			case 'email':
				h += '<div class="pf-input-icon-wrap"><span class="pf-input-icon pf-input-icon-left"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1" y="3" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M1 5.5l7 4 7-4" stroke="currentColor" stroke-width="1.5"/></svg></span><input type="email" name="' + escapeAttr(field.name) + '" placeholder="' + escapeAttr(field.placeholder || 'ime@domena.com') + '" class="pf-input-with-icon"></div>';
				break;
			case 'tel':
				h += '<div class="pf-input-icon-wrap"><span class="pf-input-icon pf-input-icon-left"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 2h3l1.5 3.5-2 1.5c1 2 2.5 3.5 4.5 4.5l1.5-2L15 11v3c0 .5-.5 1-1 1C5 15 1 8 1 3c0-.5.5-1 1-1z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span><input type="tel" name="' + escapeAttr(field.name) + '" placeholder="' + escapeAttr(field.placeholder || '+385 9X XXX XXXX') + '" class="pf-input-with-icon"></div>';
				break;
			default:
				var it = field.type === 'number' ? 'number' : 'text';
				h += '<input type="' + it + '" name="' + escapeAttr(field.name) + '" placeholder="' + escapeAttr(field.placeholder || '') + '">';
		}
		return h + '</div>';
	}
	function indexFields() {
		fieldsByUid = {};
		allFieldsFlat().forEach(function (f) {
			ensureUid(f);
			fieldsByUid[f._uid] = f;
		});
	}

	function buildFieldCard(field) {
		var $card = $('<div class="pf-builder-field" data-uid="' + field._uid + '"></div>');
		var $toolbar = $('<div class="pf-bf-toolbar"></div>');

		$toolbar.append('<span class="pf-bf-handle dashicons dashicons-move" title="Povuci za premještanje"></span>');
		$toolbar.append('<span class="pf-bf-type-icon dashicons ' + escapeAttr(window.pfFieldIcons && window.pfFieldIcons[field.type] || 'dashicons-editor-textcolor') + '"></span>');
		$toolbar.append('<span class="pf-bf-type-label">' + escapeHtml(pfFieldTypes[field.type] || field.type) + '</span>');

		if (field.required) {
			$toolbar.append('<span class="pf-bf-required-badge">obavezno</span>');
		}

		if (field.condition && (field.condition.field || (field.condition.rules && field.condition.rules.length))) {
			$toolbar.append('<span class="pf-bf-cond-badge" title="Polje ima uvjetnu logiku">uvjetno</span>');
		}

		var $clone = $('<button type="button" class="pf-bf-clone" title="Dupliciraj polje"><span class="dashicons dashicons-admin-page"></span></button>');
		$clone.on('click', function (e) {
			e.stopPropagation();
			cloneField(field);
		});
		$toolbar.append($clone);

		// Premjesti na stranicu (samo ako ima više stranica)
		if (pfSteps.length > 1) {
			var $move = $('<button type="button" class="pf-bf-move" title="Premjesti na drugu stranicu"><span class="dashicons dashicons-arrow-right-alt2"></span></button>');
			$move.on('click', function (e) {
				e.stopPropagation();
				// Pronađi trenutnu stranicu polja
				var curIdx = -1;
				pfSteps.forEach(function (step, si) {
					step.rows.forEach(function (row) {
						row.cells.forEach(function (cell) {
							if (cell.indexOf(field) > -1) curIdx = si;
						});
					});
				});

				// Mini menu sa stranicama
				$('.pf-bf-move-menu').remove();
				var $menu = $('<div class="pf-bf-move-menu"></div>');
				pfSteps.forEach(function (step, si) {
					if (si === curIdx) return;
					var $item = $('<button type="button" class="pf-bf-move-item">' + escapeHtml(getStepLabel(step, si)) + '</button>');
					$item.on('click', function (ev) {
						ev.stopPropagation();
						$menu.remove();
						moveFieldToStep(field, si);
					});
					$menu.append($item);
				});
				$(this).after($menu);

				// Zatvori menu na klik vani
				setTimeout(function () {
					$(document).one('click', function () { $menu.remove(); });
				}, 0);
			});
			$toolbar.append($move);
		}

		var $del = $('<button type="button" class="pf-bf-delete" title="Obriši polje"><span class="dashicons dashicons-trash"></span></button>');
		$del.on('click', function (e) {
			e.stopPropagation();
			if (!confirm('Obrisati ovo polje?')) {
				return;
			}
			deleteField(field);
		});
		$toolbar.append($del);

		$card.append($toolbar);
		$card.append('<div class="pf-bf-preview">' + renderFieldPreviewHTML(field) + '</div>');

		$card.on('click', function () {
			$('.pf-builder-field').removeClass('is-selected');
			$card.addClass('is-selected');
			openPanel(field);
		});

		return $card;
	}

	function setRowCols(row, n) {
		if (n === row.cols) {
			return;
		}
		if (n < row.cols) {
			var merged = row.cells.slice(n - 1).reduce(function (acc, c) { return acc.concat(c); }, []);
			row.cells = row.cells.slice(0, n - 1).concat([merged]);
		} else {
			while (row.cells.length < n) {
				row.cells.push([]);
			}
		}
		row.cols = n;
	}

	function cloneField(field) {
		var copy = JSON.parse(JSON.stringify(field));
		delete copy._uid;
		copy._nameAuto = false;
		copy.name = generateUniqueName(copy.name || copy.label, copy);
		ensureUid(copy);

		// Ubaci kopiju odmah iza originala
		outer:
		for (var s = 0; s < pfSteps.length; s++) {
			for (var r = 0; r < pfSteps[s].rows.length; r++) {
				for (var c = 0; c < pfSteps[s].rows[r].cells.length; c++) {
					var idx = pfSteps[s].rows[r].cells[c].indexOf(field);
					if (idx > -1) {
						pfSteps[s].rows[r].cells[c].splice(idx + 1, 0, copy);
						break outer;
					}
				}
			}
		}
		renderCanvas();
	}

	function cloneRow(step, row) {
		var copy = JSON.parse(JSON.stringify(row));
		// Reset UIDs na svim kopiranim poljima i generiraj nova imena
		copy.cells.forEach(function (cell) {
			cell.forEach(function (f) {
				delete f._uid;
				f._nameAuto = false;
				f.name = generateUniqueName(f.name || f.label, f);
				ensureUid(f);
			});
		});
		var idx = step.rows.indexOf(row);
		step.rows.splice(idx + 1, 0, copy);
		renderCanvas();
	}

	function deleteRow(step, row) {
		var hasFields = row.cells.some(function (c) { return c.length > 0; });
		if (hasFields) {
			alert('Premjesti ili obriši polja iz ovog reda prije brisanja.');
			return;
		}
		if (step.rows.length <= 1) {
			alert('Korak mora imati bar jedan red.');
			return;
		}
		step.rows.splice(step.rows.indexOf(row), 1);
		renderCanvas();
	}

	function deleteStep(step) {
		if (pfSteps.length <= 1) {
			alert('Forma mora imati bar jedan korak.');
			return;
		}
		var hasFields = step.rows.some(function (r) { return r.cells.some(function (c) { return c.length > 0; }); });
		if (hasFields) {
			alert('Premjesti ili obriši polja iz ovog koraka prije brisanja.');
			return;
		}
		var idx = pfSteps.indexOf(step);
		pfSteps.splice(idx, 1);
		if (activeStep >= pfSteps.length) {
			activeStep = pfSteps.length - 1;
		}
		renderCanvas();
	}

	function moveFieldToStep(field, targetStepIdx) {
		if (targetStepIdx < 0 || targetStepIdx >= pfSteps.length) return;

		// Ukloni polje s trenutne pozicije
		var removed = false;
		outer:
		for (var s = 0; s < pfSteps.length; s++) {
			var rows = pfSteps[s].rows;
			for (var r = 0; r < rows.length; r++) {
				var cells = rows[r].cells;
				for (var c = 0; c < cells.length; c++) {
					var idx = cells[c].indexOf(field);
					if (idx > -1) {
						if (s === targetStepIdx) return; // već je tu
						cells[c].splice(idx, 1);
						removed = true;
						break outer;
					}
				}
			}
		}
		if (!removed) return;

		// Dodaj na ciljnu stranicu — u prvi red, prvi stupac
		var targetStep = pfSteps[targetStepIdx];
		if (!targetStep.rows.length) {
			targetStep.rows.push({ cols: 1, cells: [[]] });
		}
		targetStep.rows[0].cells[0].push(field);

		// Prebaci na ciljnu stranicu da korisnik vidi rezultat
		activeStep = targetStepIdx;
		selectedUid = field._uid;
		renderCanvas();
		setTimeout(function () {
			var f = fieldsByUid[field._uid];
			if (f) openPanel(f);
		}, 0);
	}

	function deleteField(field) {
		outer:
		for (var s = 0; s < pfSteps.length; s++) {
			var rows = pfSteps[s].rows;
			for (var r = 0; r < rows.length; r++) {
				var cells = rows[r].cells;
				for (var c = 0; c < cells.length; c++) {
					var idx = cells[c].indexOf(field);
					if (idx > -1) {
						cells[c].splice(idx, 1);
						break outer;
					}
				}
			}
		}
		if (selectedUid === field._uid) {
			selectedUid = null;
			showPanelPlaceholder();
		}
		renderCanvas();
	}

	function buildRow(step, row) {
		var $wrapper = $('<div class="pf-row-wrapper"></div>');
		var $toolbar = $('<div class="pf-row-toolbar"></div>');

		$toolbar.append('<span class="pf-row-toolbar-label">Stupci:</span>');

		[1, 2, 3].forEach(function (n) {
			var $b = $('<button type="button" class="pf-col-btn' + (row.cols === n ? ' is-active' : '') + '" title="' + n + ' stupac"></button>');
			var $icon = $('<span class="pf-col-icon"></span>');
			for (var i = 0; i < n; i++) {
				$icon.append('<span></span>');
			}
			$b.append($icon);
			$b.on('click', function () {
				setRowCols(row, n);
				renderCanvas();
			});
			$toolbar.append($b);
		});

		var $cloneRow = $('<button type="button" class="pf-row-clone" title="Dupliciraj red"><span class="dashicons dashicons-admin-page"></span></button>');
		$cloneRow.on('click', function () {
			cloneRow(step, row);
		});
		$toolbar.append($cloneRow);

		var $del = $('<button type="button" class="pf-row-delete" title="Obriši red"><span class="dashicons dashicons-trash"></span></button>');
		$del.on('click', function () {
			deleteRow(step, row);
		});
		$toolbar.append($del);

		$wrapper.append($toolbar);

		var $grid = $('<div class="pf-row-grid" data-cols="' + row.cols + '"></div>');
		row.cells.forEach(function (cell) {
			var $col = $('<div class="pf-col"></div>');
			cell.forEach(function (f) {
				$col.append(buildFieldCard(f));
			});
			$grid.append($col);
		});
		$wrapper.append($grid);

		return $wrapper;
	}

	function buildStepContent(step) {
		var $wrap = $('<div class="pf-step-content"></div>');

		var $rows = $('<div class="pf-step-rows"></div>');
		step.rows.forEach(function (row) {
			$rows.append(buildRow(step, row));
		});
		$wrap.append($rows);

		var $addRow = $('<button type="button" class="button pf-add-row">+ Dodaj red</button>');
		$addRow.on('click', function () {
			step.rows.push({ cols: 1, cells: [[]] });
			renderCanvas();
		});
		$wrap.append($addRow);

		return $wrap;
	}

	function getStepLabel(step, i) {
		return (step.label && step.label.trim()) ? step.label.trim() : ('Stranica ' + (i + 1));
	}

	function renderTabs() {
		var $tabs = $('#pf-steps-tabs').empty();

		pfSteps.forEach(function (step, i) {
			if (!step.hasOwnProperty('enabled'))   step.enabled   = true;
			if (!step.hasOwnProperty('label'))     step.label     = '';
			if (!step.hasOwnProperty('condition')) step.condition = null;

			var isActive   = (i === activeStep);
			var isDisabled = (step.enabled === false);
			var hasCond    = (step.condition && step.condition.field);

			var cls = 'pf-step-tab';
			if (isActive)   cls += ' is-active';
			if (isDisabled) cls += ' is-disabled';
			if (hasCond)    cls += ' has-condition';

			var $tab = $('<div class="' + cls + '" data-step-index="' + i + '" draggable="true"></div>');

			// Label (editable na dblclick)
			var $label = $('<span class="pf-step-tab-label">' + escapeHtml(getStepLabel(step, i)) + '</span>');
			$label.on('dblclick', function (e) {
				e.stopPropagation();
				var $input = $('<input type="text" class="pf-step-tab-rename" value="' + escapeAttr(step.label || '') + '" placeholder="Stranica ' + (i + 1) + '">');
				$(this).replaceWith($input);
				$input.focus().select();
				function commitRename() {
					var val = $input.val().trim();
					step.label = val;
					renderTabs();
				}
				$input.on('blur', commitRename);
				$input.on('keydown', function (e) {
					if (e.key === 'Enter') { commitRename(); }
					if (e.key === 'Escape') { renderTabs(); }
				});
			});
			$tab.append($label);

			// Badge: uvjetno
			if (hasCond) {
				$tab.append('<span class="pf-step-badge pf-step-badge-cond" title="Stranica ima uvjetno prikazivanje">uvjetno</span>');
			}

			// Toggle enabled
			var $toggle = $('<button type="button" class="pf-step-tab-toggle dashicons ' + (isDisabled ? 'dashicons-hidden' : 'dashicons-visibility') + '" title="' + (isDisabled ? 'Stranica isključena — klikni za uključivanje' : 'Stranica uključena — klikni za isključivanje') + '"></button>');
			$toggle.on('click', function (e) {
				e.stopPropagation();
				step.enabled = (step.enabled === false) ? true : false;
				renderTabs();
			});
			$tab.append($toggle);

			// Uvjet za stranicu
			var $condBtn = $('<button type="button" class="pf-step-tab-cond dashicons dashicons-filter" title="Uvjetno prikazivanje stranice"></button>');
			$condBtn.on('click', function (e) {
				e.stopPropagation();
				openStepConditionPanel(step, i);
			});
			$tab.append($condBtn);

			// Obriši
			if (pfSteps.length > 1) {
				var $x = $('<button type="button" class="pf-step-tab-close dashicons dashicons-no-alt" title="Obriši stranicu"></button>');
				$x.on('click', function (e) {
					e.stopPropagation();
					deleteStep(step);
				});
				$tab.append($x);
			}

			// Klik za navigaciju
			$tab.on('click', function () {
				activeStep = i;
				renderCanvas();
			});

			// Drag & drop reorder
			$tab.on('dragstart', function (e) {
				e.originalEvent.dataTransfer.setData('text/plain', i);
				$(this).addClass('pf-tab-dragging');
			});
			$tab.on('dragend', function () {
				$(this).removeClass('pf-tab-dragging');
				$('.pf-step-tab').removeClass('pf-tab-dragover');
			});
			$tab.on('dragover', function (e) {
				e.preventDefault();
				$('.pf-step-tab').removeClass('pf-tab-dragover');
				$(this).addClass('pf-tab-dragover');
			});
			$tab.on('drop', function (e) {
				e.preventDefault();
				var fromIdx = parseInt(e.originalEvent.dataTransfer.getData('text/plain'), 10);
				var toIdx   = parseInt($(this).data('step-index'), 10);
				$(this).removeClass('pf-tab-dragover');
				if (fromIdx === toIdx || isNaN(fromIdx) || isNaN(toIdx)) return;
				// Premjesti step
				var moved = pfSteps.splice(fromIdx, 1)[0];
				pfSteps.splice(toIdx, 0, moved);
				activeStep = toIdx;
				renderCanvas();
			});

			$tabs.append($tab);
		});

		var $add = $('<button type="button" class="pf-step-tab-add" title="Dodaj stranicu"><span class="dashicons dashicons-plus-alt2"></span></button>');
		$add.on('click', function () {
			pfSteps.push({ rows: [{ cols: 1, cells: [[]] }], label: '', enabled: true, condition: null });
			activeStep = pfSteps.length - 1;
			renderCanvas();
		});
		$tabs.append($add);
	}

	// Panel za uvjet stranice — otvori se u desnom panelu
	function openStepConditionPanel(step, stepIdx) {
		selectedUid = null;
		$('.pf-builder-field').removeClass('is-selected');

		// Polja s prethodnih stranica (uvjet stranice ovisi o ranijim odgovorima)
		var allFields = allFieldsFlat().filter(function (f) {
			return f.type !== 'hidden' && f.type !== 'section_divider' && f.type !== 'html' && f.name;
		});

		var cond = step.condition || { field: '', operator: 'contains', value: '' };
		var $panel = $('#pf-field-panel').empty();

		// Header
		var $header = $('<div class="pf-panel-header"></div>');
		$header.append('<h3>Uvjet za ' + escapeHtml(getStepLabel(step, stepIdx)) + '</h3>');
		var $close = $('<button type="button" class="pf-panel-close" title="Zatvori">&times;</button>');
		$close.on('click', function () { showPanelPlaceholder(); });
		$header.append($close);
		$panel.append($header);

		var $body = $('<div class="pf-panel-tab-content"></div>');
		$panel.append($body);

		$body.append('<p class="description" style="margin:0 0 14px;font-size:12px;line-height:1.5;color:#6B5F58;">Ova stranica prikazat će se korisniku <strong>samo ako je uvjet ispunjen</strong>. Ako uvjet nije ispunjen, stranica se preskače. Ostavi polje prazno da se stranica uvijek prikazuje.</p>');

		// Polje (select)
		var $fieldSel = $('<select></select>');
		$fieldSel.append('<option value="">— bez uvjeta (uvijek prikaži) —</option>');
		allFields.forEach(function (f) {
			$fieldSel.append('<option value="' + escapeAttr(f.name) + '"' + (cond.field === f.name ? ' selected' : '') + '>'
				+ escapeHtml(f.label || f.name) + '</option>');
		});
		$body.append(panelRow('Ovisi o pitanju', $fieldSel));

		// Operator
		var $opSel = $('<select></select>');
		[['contains', 'sadrži odgovor'], ['equals', 'točno jednako'], ['not_equals', 'nije jednako']].forEach(function (o) {
			$opSel.append('<option value="' + o[0] + '"' + (cond.operator === o[0] ? ' selected' : '') + '>' + o[1] + '</option>');
		});
		var $opRow = panelRow('Uvjet', $opSel);
		$body.append($opRow);

		// Vrijednost — dropdown opcija odabranog polja, ili tekst
		var $valWrap = $('<div></div>');
		var $valRow = panelRow('Vrijednost (odgovor)', $valWrap);
		$body.append($valRow);

		function renderValueControl() {
			$valWrap.empty();
			var selectedField = allFields.filter(function (f) { return f.name === $fieldSel.val(); })[0];
			if (selectedField && selectedField.options && selectedField.options.length) {
				// Dropdown s opcijama
				var $valSel = $('<select class="pf-step-cond-value-ctrl"></select>');
				selectedField.options.forEach(function (opt) {
					$valSel.append('<option value="' + escapeAttr(opt) + '"' + (cond.value === opt ? ' selected' : '') + '>' + escapeHtml(opt) + '</option>');
				});
				$valWrap.append($valSel);
			} else {
				// Tekstualni unos
				var $valInput = $('<input type="text" class="pf-step-cond-value-ctrl" placeholder="npr. Da">').val(cond.value || '');
				$valWrap.append($valInput);
			}
		}
		renderValueControl();

		// Toggle vidljivosti uvjeta ovisno o odabiru polja
		function toggleCondVisibility() {
			if ($fieldSel.val()) {
				$opRow.show(); $valRow.show();
			} else {
				$opRow.hide(); $valRow.hide();
			}
		}
		toggleCondVisibility();

		$fieldSel.on('change', function () {
			cond.value = ''; // reset
			renderValueControl();
			toggleCondVisibility();
		});

		// Gumbi
		var $save = $('<button type="button" class="button button-primary" style="margin-top:12px;width:100%;">Spremi uvjet</button>');
		$save.on('click', function () {
			var fieldVal = $fieldSel.val();
			if (!fieldVal) {
				step.condition = null;
			} else {
				step.condition = {
					field:    fieldVal,
					operator: $opSel.val(),
					value:    $valWrap.find('.pf-step-cond-value-ctrl').val().trim(),
				};
			}
			renderTabs();
			showPanelPlaceholder();
		});
		$body.append($save);

		if (step.condition) {
			var $clear = $('<button type="button" class="button" style="margin-top:8px;width:100%;">Ukloni uvjet</button>');
			$clear.on('click', function () {
				step.condition = null;
				renderTabs();
				showPanelPlaceholder();
			});
			$body.append($clear);
		}
	}

	function renderCanvas() {
		indexFields();

		if (activeStep >= pfSteps.length) {
			activeStep = pfSteps.length - 1;
		}
		if (activeStep < 0) {
			activeStep = 0;
		}

		renderTabs();

		var $container = $('#pf-steps-container').empty();
		$container.append(buildStepContent(pfSteps[activeStep]));

		initSortables();

		if (selectedUid && fieldsByUid[selectedUid]) {
			$('#pf-steps-container .pf-builder-field[data-uid="' + selectedUid + '"]').addClass('is-selected');
		}
	}

	function updateCardPreview(field) {
		var $card = $('#pf-steps-container .pf-builder-field[data-uid="' + field._uid + '"]');

		$card.find('.pf-bf-type-label').text(pfFieldTypes[field.type] || field.type);
		$card.find('.pf-bf-required-badge').remove();

		if (field.required) {
			$card.find('.pf-bf-delete').before('<span class="pf-bf-required-badge">obavezno</span>');
		}

		$card.find('.pf-bf-preview').html(renderFieldPreviewHTML(field));
	}

	/* ---------------------------------------------------------
	 *  Drag & drop
	 * --------------------------------------------------------- */
	function initSortables() {
		$('#pf-steps-container .pf-col').each(function () {
			new Sortable(this, {
				group: 'pf-fields',
				handle: '.pf-bf-handle',
				animation: 150,
				onAdd: function () {
					setTimeout(rebuildFromDOM, 0);
				},
				onUpdate: function () {
					setTimeout(rebuildFromDOM, 0);
				},
				onRemove: function () {
					setTimeout(rebuildFromDOM, 0);
				},
				onEnd: function () {
					setTimeout(rebuildFromDOM, 0);
				}
			});
		});
	}

	function initPalette() {
		$('#pf-palette-list .pf-palette-group-items').each(function () {
			new Sortable(this, {
				group: { name: 'pf-fields', pull: 'clone', put: false },
				sort: false,
				animation: 150
			});
		});
	}

	function rebuildFromDOM() {
		var rows = [];

		$('#pf-steps-container .pf-row-wrapper').each(function () {
			var $grid = $(this).find('.pf-row-grid');
			var cols  = parseInt($grid.attr('data-cols'), 10) || 1;
			var cells = [];

			$grid.find('.pf-col').each(function () {
				var cell = [];

				$(this).children().each(function () {
					var $el = $(this);

					if ($el.hasClass('pf-palette-item')) {
						var type = $el.data('new-type');
						var f = defaultFieldForType(type);
						ensureUid(f);
						cell.push(f);
					} else {
						var uid = $el.data('uid');
						if (fieldsByUid[uid]) {
							cell.push(fieldsByUid[uid]);
						}
					}
				});

				cells.push(cell);
			});

			rows.push({ cols: cols, cells: cells });
		});

		pfSteps[activeStep].rows = rows;
		renderCanvas();

		if (!selectedUid || !fieldsByUid[selectedUid]) {
			showPanelPlaceholder();
		}
	}

	/* ---------------------------------------------------------
	 *  Panel (postavke polja)
	 * --------------------------------------------------------- */
	function panelRow(labelText, $control) {
		var $row = $('<div class="pf-panel-row"></div>');
		$row.append('<label>' + escapeHtml(labelText) + '</label>');
		$row.append($control);
		return $row;
	}

	function showPanelPlaceholder() {
		selectedUid = null;
		$('#pf-field-panel').html('<p class="pf-panel-placeholder">Klikni na polje za uređivanje postavki.</p>');
	}

	function buildConditionSection(field) {
		var $wrap = $('<div class="pf-condition-block"></div>');

		// Migriraj staru strukturu { field, operator, value } → nova { match: 'all', rules: [...] }
		if (field.condition && field.condition.field && !field.condition.rules) {
			field.condition = {
				match: 'all',
				rules: [{ field: field.condition.field, operator: field.condition.operator || 'equals', value: field.condition.value || '' }]
			};
		}
		if (!field.condition) field.condition = null;

		var hasCond = !!field.condition;

		$wrap.append('<h4 style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#9C9182;">Uvjet prikaza</h4>');
		var $enableLabel = $('<label class="pf-cond-toggle"><input type="checkbox"' + (hasCond ? ' checked' : '') + '> <span>Prikaži ovo polje samo ako je uvjet ispunjen</span></label>');
		$wrap.append($enableLabel);

		var $condMain = $('<div class="pf-cond-main"></div>');
		$condMain.toggle(hasCond);
		$wrap.append($condMain);

		var OPS_NO_VALUE = ['is_empty', 'is_not_empty'];

		var OPS = {
			'equals':       'jednako je',
			'not_equals':   'nije jednako',
			'contains':     'sadrži',
			'not_contains': 'ne sadrži',
			'starts_with':  'počinje s',
			'is_empty':     'je prazno',
			'is_not_empty': 'nije prazno',
			'greater_than': 'veće od',
			'less_than':    'manje od',
		};

		function getOtherFields() {
			return allFieldsFlat().filter(function (f) { return f !== field && f.name; });
		}

		function getFieldOptions(name) {
			var f = allFieldsFlat().filter(function (f) { return f.name === name; })[0];
			return (f && f.options && f.options.length) ? f.options : null;
		}

		function renderRuleRow(rule, idx, $rulesWrap) {
			var others = getOtherFields();
			var $row = $('<div class="pf-cond-rule-row" data-idx="' + idx + '"></div>');

			// Polje
			var $fsel = $('<select class="pf-cond-field-sel"></select>');
			others.forEach(function (f) {
				$fsel.append('<option value="' + escapeAttr(f.name) + '"' + (rule.field === f.name ? ' selected' : '') + '>' + escapeHtml(f.label || f.name) + '</option>');
			});

			// Operator
			var $opsel = $('<select class="pf-cond-op-sel"></select>');
			Object.keys(OPS).forEach(function (k) {
				$opsel.append('<option value="' + k + '"' + (rule.operator === k ? ' selected' : '') + '>' + OPS[k] + '</option>');
			});

			// Vrijednost
			var $valWrap = $('<span class="pf-cond-val-wrap"></span>');

			function renderValue() {
				$valWrap.empty();
				var op = $opsel.val();
				if (OPS_NO_VALUE.indexOf(op) > -1) {
					rule.value = '';
					return;
				}
				var opts = getFieldOptions($fsel.val());
				if (opts) {
					var $vsel = $('<select class="pf-cond-val-sel"></select>');
					opts.forEach(function (o) {
						$vsel.append('<option value="' + escapeAttr(o) + '"' + (rule.value === o ? ' selected' : '') + '>' + escapeHtml(o) + '</option>');
					});
					$vsel.on('change', function () { rule.value = $(this).val(); });
					if (!rule.value || !opts.some(function(o){return o===rule.value;})) {
						rule.value = opts[0] || '';
						$vsel.val(rule.value);
					}
					$valWrap.append($vsel);
				} else {
					var inputType = ['greater_than','less_than'].indexOf(op) > -1 ? 'number' : 'text';
					var $vinp = $('<input type="' + inputType + '" class="pf-cond-val-inp" placeholder="vrijednost">').val(rule.value || '');
					$vinp.on('input', function () { rule.value = $(this).val(); });
					$valWrap.append($vinp);
				}
			}

			$fsel.on('change', function () {
				rule.field = $(this).val();
				rule.value = '';
				renderValue();
			});
			$opsel.on('change', function () {
				rule.operator = $(this).val();
				renderValue();
			});

			renderValue();

			// Obriši red
			var $del = $('<button type="button" class="pf-cond-rule-del" title="Ukloni uvjet">&times;</button>');
			$del.on('click', function () {
				field.condition.rules.splice(idx, 1);
				if (field.condition.rules.length === 0) {
					field.condition = null;
					$enableLabel.find('input').prop('checked', false);
					$condMain.hide();
				} else {
					renderAll();
				}
			});

			$row.append($fsel).append($opsel).append($valWrap).append($del);
			return $row;
		}

		function renderAll() {
			$condMain.empty();
			if (!field.condition || !field.condition.rules) return;

			// AND / OR toggle
			var $matchRow = $('<div class="pf-cond-match-row"></div>');
			$matchRow.append('<span>Prikaži ako je ispunjeno</span>');
			var $matchSel = $('<select class="pf-cond-match-sel"></select>');
			$matchSel.append('<option value="all"' + (field.condition.match !== 'any' ? ' selected' : '') + '>SVI uvjeti (AND)</option>');
			$matchSel.append('<option value="any"' + (field.condition.match === 'any' ? ' selected' : '') + '>BILO KOJI uvjet (OR)</option>');
			$matchSel.on('change', function () { field.condition.match = $(this).val(); });
			$matchRow.append($matchSel);
			$condMain.append($matchRow);

			// Rule redovi
			var $rulesWrap = $('<div class="pf-cond-rules-wrap"></div>');
			field.condition.rules.forEach(function (rule, idx) {
				$rulesWrap.append(renderRuleRow(rule, idx, $rulesWrap));
				// Separator AND/OR između redova
				if (idx < field.condition.rules.length - 1) {
					var sep = field.condition.match === 'any' ? 'ILI' : 'I';
					$rulesWrap.append('<div class="pf-cond-sep">' + sep + '</div>');
				}
			});
			$condMain.append($rulesWrap);

			// + Dodaj uvjet
			if (field.condition.rules.length < 5) {
				var $addBtn = $('<button type="button" class="button pf-cond-add-btn">+ Dodaj uvjet</button>');
				$addBtn.on('click', function () {
					var others = getOtherFields();
					if (!others.length) return;
					field.condition.rules.push({ field: others[0].name, operator: 'equals', value: '' });
					renderAll();
				});
				$condMain.append($addBtn);
			}
		}

		if (hasCond) renderAll();

		$enableLabel.find('input').on('change', function () {
			if ($(this).is(':checked')) {
				var others = getOtherFields();
				if (!others.length) {
					alert('Nema drugih polja za postavljanje uvjeta.');
					$(this).prop('checked', false);
					return;
				}
				field.condition = { match: 'all', rules: [{ field: others[0].name, operator: 'equals', value: '' }] };
				renderAll();
				$condMain.show();
			} else {
				field.condition = null;
				$condMain.hide();
			}
		});

		return $wrap;
	}

	function openPanel(field) {
		selectedUid = field._uid;
		var $panel = $('#pf-field-panel').empty();

		var $header = $('<div class="pf-panel-header"></div>');
		$header.append('<h3>Postavke polja</h3>');
		var $close = $('<button type="button" class="pf-panel-close" title="Zatvori">&times;</button>');
		$close.on('click', function () {
			$('.pf-builder-field').removeClass('is-selected');
			showPanelPlaceholder();
		});
		$header.append($close);
		$panel.append($header);

		// Tabovi
		var $tabBar = $('<div class="pf-panel-tabs"></div>');
		var $tabGeneral = $('<button type="button" class="pf-panel-tab is-active">Općenito</button>');
		var $tabLogic = $('<button type="button" class="pf-panel-tab">Logika</button>');
		$tabBar.append($tabGeneral).append($tabLogic);
		$panel.append($tabBar);

		var $general = $('<div class="pf-panel-tab-content"></div>');
		var $logic = $('<div class="pf-panel-tab-content" style="display:none;"></div>');
		$panel.append($general).append($logic);

		$tabGeneral.on('click', function () {
			$tabGeneral.addClass('is-active');
			$tabLogic.removeClass('is-active');
			$general.show();
			$logic.hide();
		});
		$tabLogic.on('click', function () {
			$tabLogic.addClass('is-active');
			$tabGeneral.removeClass('is-active');
			$logic.show();
			$general.hide();
		});

		var $panelTarget = $general;

		// Tip polja
		var $typeSelect = $('<select></select>');
		Object.keys(pfFieldTypes).forEach(function (key) {
			var sel = field.type === key ? ' selected' : '';
			$typeSelect.append('<option value="' + key + '"' + sel + '>' + escapeHtml(pfFieldTypes[key]) + '</option>');
		});
		$typeSelect.on('change', function () {
			field.type = $(this).val();
			if (CHOICE_TYPES.indexOf(field.type) > -1 && (!field.options || field.options.length === 0)) {
				field.options = ['Opcija 1', 'Opcija 2'];
			}
			updateCardPreview(field);
			openPanel(field);
		});
		$panelTarget.append(panelRow('Tip polja', $typeSelect));

		// Hidden/UTM specifična polja
		if (field.type === 'hidden') {
			// Label (interni naziv) + Name za hidden polje
			var $hLabel = $('<input type="text">').val(field.label);
			$panelTarget.append(panelRow('Naziv (interni, npr. "Izvor")', $hLabel));

			var $hName = $('<input type="text">').val(field.name);
			$panelTarget.append(panelRow('Naziv polja (name)', $hName));

			$hLabel.on('input', function () {
				field.label = $(this).val();
				if (field._nameAuto !== false) {
					field.name = generateUniqueName(field.label, field);
					$hName.val(field.name);
				}
			});
			$hName.on('input', function () {
				field._nameAuto = false;
				field.name = $(this).val();
			});

			var $utm = $('<select></select>');
			var utmOpts = { '': '— ne koristi UTM —', utm_source: 'utm_source', utm_medium: 'utm_medium', utm_campaign: 'utm_campaign', utm_term: 'utm_term', utm_content: 'utm_content' };
			Object.keys(utmOpts).forEach(function (k) {
				var sel = (field.utm_source || '') === k ? ' selected' : '';
				$utm.append('<option value="' + k + '"' + sel + '>' + utmOpts[k] + '</option>');
			});
			$utm.on('change', function () { field.utm_source = $(this).val(); });

			var $def = $('<input type="text" placeholder="Npr. organic (ako nije UTM)">').val(field.default_value || '');
			$def.on('input', function () { field.default_value = $(this).val(); });

			$panelTarget.append(panelRow('UTM parametar', $utm));
			$panelTarget.append(panelRow('Default vrijednost', $def));

			// Logic tab nije potreban za hidden
			$logic.append('<p class="description">Skrivena polja uvijek se šalju.</p>');
			return;
		}

		// Section divider - samo naslov i opis
		if (field.type === 'section_divider') {
			var $sdTitle = $('<input type="text" placeholder="npr. Kontakt podaci">').val(field.label);
			$sdTitle.on('input', function () {
				field.label = $(this).val();
				updateCardPreview(field);
			});
			var $sdDesc = $('<input type="text" placeholder="Neobavezan opis ispod linije">').val(field.placeholder || '');
			$sdDesc.on('input', function () {
				field.placeholder = $(this).val();
				updateCardPreview(field);
			});
			$panelTarget.append(panelRow('Naslov sekcije', $sdTitle));
			$panelTarget.append(panelRow('Opis (opcionalno)', $sdDesc));
			$logic.append('<p class="description">Razdjelnici ne sudjeluju u uvjetnoj logici.</p>');
			return;
		}

		// Label
		var $labelInput = $('<input type="text">').val(field.label);
		$panelTarget.append(panelRow(field.type === 'html' ? 'Naziv (interno)' : 'Label', $labelInput));

		// Name
		var $nameInput = $('<input type="text">').val(field.name);
		if (field.type !== 'html') {
			$panelTarget.append(panelRow('Naziv polja (name)', $nameInput));
		}

		$labelInput.on('input', function () {
			field.label = $(this).val();
			if (field._nameAuto !== false) {
				field.name = generateUniqueName(field.label, field);
				$nameInput.val(field.name);
			}
			updateCardPreview(field);
		});

		$nameInput.on('input', function () {
			field._nameAuto = false;
			field.name = $(this).val();
		});

		// Required
		if (field.type !== 'html') {
			var $req = $('<input type="checkbox">').prop('checked', !!field.required);
			$req.on('change', function () {
				field.required = $(this).is(':checked');
				updateCardPreview(field);
			});
			$panelTarget.append(panelRow('Obavezno polje', $req));
		}

		// Placeholder / HTML / file extensions
		if (field.type === 'html') {
			var $html = $('<textarea rows="5"></textarea>').val(field.placeholder);
			$html.on('input', function () {
				field.placeholder = $(this).val();
				updateCardPreview(field);
			});
			$panelTarget.append(panelRow('HTML sadržaj', $html));
		} else if (CHOICE_TYPES.indexOf(field.type) === -1) {
			var phLabel = field.type === 'file' ? 'Dozvoljene ekstenzije (npr. .jpg,.png,.pdf)' : 'Placeholder';
			var $ph = $('<input type="text">').val(field.placeholder);
			$ph.on('input', function () {
				field.placeholder = $(this).val();
				updateCardPreview(field);
			});
			$panelTarget.append(panelRow(phLabel, $ph));
		}

		// Opcije
		if (CHOICE_TYPES.indexOf(field.type) > -1 || field.type === 'image_choice') {
			var isImgChoice = field.type === 'image_choice';
			var $opts = $('<textarea rows="5" placeholder="' + (isImgChoice ? 'Label slike|https://url-slike.jpg\nDruga opcija|https://...' : 'Jedna opcija po redu') + '"></textarea>').val((field.options || []).join('\n'));
			$opts.on('input', function () {
				field.options = $(this).val().split('\n').map(function (s) { return s.trim(); }).filter(function (s) { return s.length > 0; });
				updateCardPreview(field);
			});
			var label_opts = isImgChoice ? 'Opcije (Label|URL slike)' : 'Opcije (jedna po redu)';
			$panelTarget.append(panelRow(label_opts, $opts));
			if (isImgChoice) {
				var $hint = $('<p class="description" style="font-size:11px;margin-top:4px;">Format: <code>Naziv opcije|https://url-slike.jpg</code><br>URL slike je opcionalan — bez njega prikazuje se ikona.</p>');
				$panelTarget.append($hint);

				// Multiple / single toggle
				var $multi = $('<input type="checkbox">').prop('checked', !!field.multiple);
				$multi.on('change', function () {
					field.multiple = $(this).is(':checked');
					updateCardPreview(field);
				});
				$panelTarget.append(panelRow('Višestruki odabir', $multi));
			}
		}

		// Default vrijednost (za text/email/tel/number/textarea/select/date)
		if (['text', 'email', 'tel', 'number', 'date', 'textarea', 'select'].indexOf(field.type) > -1) {
			var $defVal = $('<input type="text">').val(field.default_value || '');
			$defVal.on('input', function () {
				field.default_value = $(this).val();
				updateCardPreview(field);
			});
			$panelTarget.append(panelRow('Default vrijednost', $defVal));
		}

		// Rating opcije
		if (field.type === 'rating') {
			if (!field.max_rating) field.max_rating = 10;
			if (!field.label_low)  field.label_low  = '1 – Nije mi važno';
			if (!field.label_high) field.label_high = field.max_rating + ' – Presudno mi je';

			var $maxR = $('<input type="number" min="2" max="20" step="1">').val(field.max_rating);
			$maxR.on('input', function () {
				field.max_rating = Math.max(2, Math.min(20, parseInt($(this).val()) || 10));
				if (!field.label_high || field.label_high.match(/^\d+/)) {
					field.label_high = field.max_rating + ' – Presudno mi je';
					$labelHigh.val(field.label_high);
				}
				updateCardPreview(field);
			});
			$panelTarget.append(panelRow('Maksimalna ocjena (2–20)', $maxR));

			var $labelLow = $('<input type="text" placeholder="npr. 1 – Nije mi važno">').val(field.label_low);
			$labelLow.on('input', function () { field.label_low = $(this).val(); });
			$panelTarget.append(panelRow('Oznaka lijevo (minimum)', $labelLow));

			var $labelHigh = $('<input type="text" placeholder="npr. 10 – Presudno mi je">').val(field.label_high);
			$labelHigh.on('input', function () { field.label_high = $(this).val(); });
			$panelTarget.append(panelRow('Oznaka desno (maksimum)', $labelHigh));
		}

		// Premjesti na stranicu (samo ako ima više stranica)
		if (pfSteps.length > 1 && field.type !== 'hidden') {
			var $moveSelect = $('<select></select>');
			var currentStepIdx = -1;
			pfSteps.forEach(function (step, si) {
				// Pronađi na kojoj je stranici ovo polje
				step.rows.forEach(function (row) {
					row.cells.forEach(function (cell) {
						if (cell.indexOf(field) > -1) currentStepIdx = si;
					});
				});
			});
			pfSteps.forEach(function (step, si) {
				var sel = si === currentStepIdx ? ' selected' : '';
				$moveSelect.append('<option value="' + si + '"' + sel + '>' + escapeHtml(getStepLabel(step, si)) + '</option>');
			});
			$moveSelect.on('change', function () {
				var targetIdx = parseInt($(this).val(), 10);
				moveFieldToStep(field, targetIdx);
			});
			$panelTarget.append(panelRow('Premjesti na stranicu', $moveSelect));
		}

		// Logika (Smart Logic) - uvjet prikaza
		if (field.type !== 'html') {
			$logic.append(buildConditionSection(field));
		} else {
			$logic.append('<p class="description">Info blok nema logiku prikaza.</p>');
		}
	}

	/* ---------------------------------------------------------
	 *  Init
	 * --------------------------------------------------------- */
	pfSteps = (window.pfInitialStructure && window.pfInitialStructure.steps) || [];

	if (!pfSteps.length) {
		pfSteps = [{ rows: [{ cols: 1, cells: [[]] }] }];
	}

	allFieldsFlat().forEach(function (f) {
		f.condition = f.condition || null;
		f.options = f.options || [];
		f._nameAuto = false;
	});

	renderCanvas();
	initPalette();

	/* ---------------------------------------------------------
	 *  Validacija prije spremanja
	 * --------------------------------------------------------- */
	function validateBeforeSave() {
		var errors = [];
		var names  = {};
		var NO_NAME_TYPES = ['section_divider', 'html'];

		allFieldsFlat().forEach(function (f) {
			if (NO_NAME_TYPES.indexOf(f.type) !== -1) return; // ne trebaju name
			if (!f.name) {
				errors.push('Polje "' + (f.label || '(bez naziva)') + '" nema postavljen naziv (name).');
			} else if (names[f.name]) {
				errors.push('Duplikat naziva polja: "' + f.name + '" se pojavljuje više puta.');
			} else {
				names[f.name] = true;
			}
		});

		var hasAnyField = allFieldsFlat().length > 0;
		if (!hasAnyField) {
			errors.push('Forma nema niti jedno polje. Dodaj barem jedno polje prije spremanja.');
		}

		if (errors.length) {
			alert('Greške prije spremanja:\n\n' + errors.join('\n'));
			return false;
		}
		return true;
	}

	$('#pf-form-edit-form').on('submit', function (e) {
		// Sync postavki prije slanja
		syncSettings();

		if (!validateBeforeSave()) {
			e.preventDefault();
			return;
		}
		var clean = JSON.parse(JSON.stringify(pfSteps, function (key, val) {
			if (key === '_uid' || key === '_nameAuto') return undefined;
			return val;
		}));
		$('#pf-fields-json').val(JSON.stringify({ steps: clean }));
	});

	/* ---------------------------------------------------------
	 *  Panel: hidden/UTM polje - dodatna polja u openPanel
	 * --------------------------------------------------------- */
	// UTM i default value za hidden tip - dodaju se dinamički u openPanel
	// (logika je u openPanel funkciji)

	/* ---------------------------------------------------------
	 *  Predlošci polja (Templates)
	 * --------------------------------------------------------- */
	var pfTemplates = {};

	function loadTemplates(cb) {
		$.post(window.pfAjaxUrl, {
			action: 'pf_get_templates',
			nonce: window.pfTemplateNonce
		}, function (res) {
			if (res.success) {
				pfTemplates = res.data || {};
				if (cb) cb();
			}
		});
	}

	function renderTemplateModal() {
		var $modal = $('#pf-template-modal');
		if ( $modal.parent()[0] !== document.body ) {
			$modal.appendTo('body');
		}
		var $modal = $('#pf-template-modal');
		var $list  = $modal.find('.pf-template-list').empty();

		var keys = Object.keys(pfTemplates);
		if (!keys.length) {
			$list.append('<p class="pf-panel-placeholder">Nema spremljenih predložaka.</p>');
		} else {
			keys.forEach(function (id) {
				var tpl = pfTemplates[id];
				var $row = $('<div class="pf-template-row"></div>');
				$row.append('<span class="pf-template-name">' + escapeHtml(tpl.name) + ' <small>(' + tpl.fields.length + ' polja)</small></span>');

				var $use = $('<button type="button" class="button button-small">Ubaci</button>');
				$use.on('click', function () {
					// Ubaci sva polja predloška u aktivni korak, u novi red
					var newCells = [ tpl.fields.map(function (f) {
						var copy = JSON.parse(JSON.stringify(f));
						delete copy._uid;
						copy._nameAuto = false;
						copy.name = generateUniqueName(copy.name || copy.label, copy);
						ensureUid(copy);
						return copy;
					}) ];
					pfSteps[activeStep].rows.push({ cols: 1, cells: newCells });
					renderCanvas();
					$('#pf-template-modal').removeClass('is-open');
				});

				var $del = $('<button type="button" class="button button-small pf-tpl-del" style="margin-left:6px;color:#b32d2e;">Obriši</button>');
				$del.on('click', function () {
					if (!confirm('Obrisati predložak "' + tpl.name + '"?')) return;
					$.post(window.pfAjaxUrl, { action: 'pf_delete_template', nonce: window.pfTemplateNonce, template_id: id }, function () {
						delete pfTemplates[id];
						renderTemplateModal();
					});
				});

				$row.append($use).append($del);
				$list.append($row);
			});
		}
	}

	// Spremi trenutna polja aktivne stranice kao predložak
	$('#pf-save-template-btn').on('click', function () {
		var flat = [];
		pfSteps[activeStep].rows.forEach(function (row) {
			row.cells.forEach(function (cell) {
				cell.forEach(function (f) { flat.push(f); });
			});
		});

		if (!flat.length) {
			alert('Nema polja na trenutnoj stranici za spremanje kao predložak.');
			return;
		}

		var name = prompt('Naziv predloška:', 'Predložak ' + (Object.keys(pfTemplates).length + 1));
		if (!name) return;

		var cleanFields = JSON.parse(JSON.stringify(flat, function (k, v) {
			if (k === '_uid' || k === '_nameAuto') return undefined;
			return v;
		}));

		$.post(window.pfAjaxUrl, {
			action: 'pf_save_template',
			nonce: window.pfTemplateNonce,
			name: name,
			fields: JSON.stringify(cleanFields)
		}, function (res) {
			if (res.success) {
				pfTemplates[res.data.id] = res.data;
				alert('Predložak "' + name + '" uspješno spremljen!');
			}
		});
	});

	$('#pf-load-template-btn').on('click', function () {
		loadTemplates(function () {
			renderTemplateModal();
			$('#pf-template-modal').css('display', '').addClass('is-open');
		});
	});

	$(document).on('click', '#pf-template-modal-close, #pf-template-modal', function (e) {
		if (e.target === this || $(e.target).is('#pf-template-modal-close')) {
			$('#pf-template-modal').removeClass('is-open').css('display', 'none');
		}
	});

	/* ---------------------------------------------------------
	 *  Pregled forme (Desktop/Mobitel)
	 * --------------------------------------------------------- */
	function buildPreviewThemeCSS() {
		var t = pfTheme || {};
		var primary = t.primary_color || '#B5654A';
		var bg      = t.bg_color      || '#FFFFFF';
		var text    = t.text_color    || '#2B2420';
		var labelC  = t.label_color   || text;
		var border  = t.border_color  || '#DDD4C8';
		var inputBg = t.input_bg      || '#FBF8F4';
		var radius  = (t.border_radius || '8') + 'px';
		var font    = t.font_family   || 'inherit';
		var btnStyle= t.button_style  || 'filled';
		var btnText = t.button_text   || '#FFFFFF';

		var btnBg     = btnStyle === 'filled' ? primary : 'transparent';
		var btnBorder = btnStyle === 'ghost'  ? 'transparent' : primary;
		var btnTextC  = btnStyle === 'filled' ? btnText : primary;

		// Label stil
		var labelStyle = t.label_style || 'normal';
		var labelCss;
		if (labelStyle === 'uppercase') {
			labelCss = 'font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:0.08em;';
		} else if (labelStyle === 'light') {
			labelCss = 'font-weight:400;font-size:13px;text-transform:none;letter-spacing:0;';
		} else {
			labelCss = 'font-weight:600;font-size:14px;text-transform:none;letter-spacing:0;';
		}
		var inputFont = t.font_size === 'small' ? '13px' : t.font_size === 'large' ? '17px' : '15px';
		var inputPad  = t.input_height === 'compact' ? '9px 12px' : t.input_height === 'spacious' ? '16px 16px' : '12px 14px';

		// Google font import
		var fontImport = '';
		var fm = font.match(/'([^']+)'/);
		if (fm && fm[1]) {
			fontImport = '@import url("https://fonts.googleapis.com/css2?family=' + encodeURIComponent(fm[1]) + ':wght@400;500;600;700&display=swap");';
		}

		var S = '#pf-preview-frame';
		return '<style>'
			+ fontImport
			+ S + ' .pf-form { font-family:' + font + ' !important; color:' + text + ' !important; }'
			+ S + ' .pf-form .pf-field label, ' + S + ' .pf-form .pf-field legend { color:' + labelC + ' !important; ' + labelCss + ' !important; }'
			+ S + ' .pf-form .pf-field input, ' + S + ' .pf-form .pf-field select, ' + S + ' .pf-form .pf-field textarea {'
				+ 'border:1.5px solid ' + border + ' !important; border-radius:' + radius + ' !important; background:' + inputBg + ' !important;'
				+ 'color:' + text + ' !important; padding:' + inputPad + ' !important; font-size:' + inputFont + ' !important; font-family:' + font + ' !important; }'
			+ S + ' .pf-form .pf-btn-primary { background:' + btnBg + ' !important; color:' + btnTextC + ' !important; border:2px solid ' + btnBorder + ' !important; border-radius:' + radius + ' !important; }'
			+ S + ' .pf-form .pf-btn-secondary { border:2px solid ' + border + ' !important; color:' + text + ' !important; border-radius:' + radius + ' !important; }'
			+ S + ' .pf-form .pf-step-dot.is-active, ' + S + ' .pf-form .pf-step-dot.is-complete { background:' + primary + ' !important; color:#fff !important; }'
			+ S + ' .pf-form .pf-step-line.is-complete { background:' + primary + ' !important; }'
			+ S + ' .pf-form .pf-required-mark { color:' + primary + ' !important; }'
			+ S + ' .pf-form .pf-inline-option input { accent-color:' + primary + ' !important; }'
			+ '</style>';
	}

	function buildPreviewHTML() {
		var totalSteps = pfSteps.length;
		var submitLabel = $('#pf-submit-label').val() || 'Pošalji';

		var html = buildPreviewThemeCSS();
		html += '<form class="pf-form pf-preview-form" novalidate onsubmit="return false;">';

		// Step indicator (samo ako ima više stranica)
		if (totalSteps > 1) {
			html += '<div class="pf-steps-indicator">';
			pfSteps.forEach(function (step, i) {
				var dotTitle = getStepLabel(step, i);
				html += '<div class="pf-step-dot ' + (i === 0 ? 'is-active' : '') + '" data-step="' + (i + 1) + '" title="' + escapeAttr(dotTitle) + '">' + (i + 1) + '</div>';
				if (i < totalSteps - 1) html += '<div class="pf-step-line"></div>';
			});
			html += '</div>';
		}

		pfSteps.forEach(function (step, i) {
			var isActive = i === 0;
			html += '<div class="pf-step-panel ' + (isActive ? 'is-active' : '') + '" data-step="' + (i + 1) + '">';

			step.rows.forEach(function (row) {
				html += '<div class="pf-row pf-cols-' + row.cols + '">';
				row.cells.forEach(function (cell) {
					html += '<div class="pf-col">';
					cell.forEach(function (f) {
						if (f.type !== 'hidden') {
							html += buildPreviewFieldHTML(f);
						}
					});
					html += '</div>';
				});
				html += '</div>';
			});

			// Navigacija
			html += '<div class="pf-step-actions">';
			if (i > 0) {
				html += '<button type="button" class="pf-btn pf-btn-secondary pf-preview-prev" data-step="' + (i + 1) + '">← Natrag</button>';
			}
			if (i < totalSteps - 1) {
				html += '<button type="button" class="pf-btn pf-btn-primary pf-preview-next" data-step="' + (i + 1) + '">Sljedeći korak →</button>';
				html += '<button type="button" class="pf-btn pf-btn-primary pf-preview-submit" data-step="' + (i + 1) + '" disabled style="display:none;">' + escapeHtml(submitLabel) + '</button>';
			} else {
				html += '<button type="button" class="pf-btn pf-btn-primary pf-preview-submit" disabled>' + escapeHtml(submitLabel) + '</button>';
			}
			html += '</div>';

			html += '</div>'; // pf-step-panel
		});

		html += '</form>';
		return html;
	}

	// Preview navigacija (delegirana)
	$(document).on('click', '.pf-preview-next', function () {
		var frame = $('#pf-preview-frame')[0];
		var panels = Array.prototype.slice.call(frame.querySelectorAll('.pf-step-panel'));
		var curIdx = panels.findIndex(function (p) { return p.classList.contains('is-active'); });
		if (curIdx === -1) return;
		var nextIdx = findPreviewVisibleStep(frame, panels, curIdx, +1);
		if (nextIdx === -1) return;
		gotoPreviewStep(frame, panels, nextIdx);
	});

	$(document).on('click', '.pf-preview-prev', function () {
		var frame = $('#pf-preview-frame')[0];
		var panels = Array.prototype.slice.call(frame.querySelectorAll('.pf-step-panel'));
		var curIdx = panels.findIndex(function (p) { return p.classList.contains('is-active'); });
		if (curIdx === -1) return;
		var prevIdx = findPreviewVisibleStep(frame, panels, curIdx, -1);
		if (prevIdx === -1) return;
		gotoPreviewStep(frame, panels, prevIdx);
	});

	function gotoPreviewStep(frame, panels, targetIdx) {
		panels.forEach(function (p, i) { p.classList.toggle('is-active', i === targetIdx); });
		var targetStep = parseInt(panels[targetIdx].getAttribute('data-step'), 10);
		frame.querySelectorAll('.pf-step-dot').forEach(function (dot) {
			var s = parseInt(dot.getAttribute('data-step'), 10);
			dot.classList.remove('is-active', 'is-complete');
			if (s < targetStep) dot.classList.add('is-complete');
			else if (s === targetStep) dot.classList.add('is-active');
		});
	}

	$('#pf-preview-btn').on('click', function () {
		var $modal = $('#pf-preview-modal');
		if ( $modal.parent()[0] !== document.body ) {
			$modal.appendTo('body');
		}
		$('#pf-preview-frame').html(buildPreviewHTML());
		$modal.css('display', '').addClass('is-open');
		var frame = $('#pf-preview-frame')[0];
		evaluatePreviewConditions(frame);
		syncPreviewChecked(frame);
		updatePreviewStepButtons(frame);
	});

	// Re-evaluiraj pri svakoj promjeni unutar previewa + is-checked klasa
	$(document).on('change', '#pf-preview-frame', function () {
		var frame = $('#pf-preview-frame')[0];
		evaluatePreviewConditions(frame);
		syncPreviewChecked(frame);
		updatePreviewStepButtons(frame);
		checkPreviewAutoAdvance(frame);
	});
	$(document).on('input', '#pf-preview-frame', function () {
		var frame = $('#pf-preview-frame')[0];
		evaluatePreviewConditions(frame);
		updatePreviewStepButtons(frame);
	});

	function checkPreviewAutoAdvance(frame) {
		if (!frame) return;
		var panels = Array.prototype.slice.call(frame.querySelectorAll('.pf-step-panel'));
		var curIdx = panels.findIndex(function (p) { return p.classList.contains('is-active'); });
		if (curIdx === -1) return;

		var nextIdx = findPreviewVisibleStep(frame, panels, curIdx, +1);
		if (nextIdx === -1) return;

		// Samo ako sljedeća stranica ima uvjet
		if (!panels[nextIdx].getAttribute('data-step-cond-field')) return;

		// Provjeri jesu li sva vidljiva polja trenutne stranice ispunjena
		var inputs = Array.prototype.slice.call(
			panels[curIdx].querySelectorAll('input:not([type="hidden"]), select, textarea')
		).filter(function (inp) {
			var f = inp.closest('.pf-field');
			return f && f.style.display !== 'none';
		});
		if (!inputs.length) return;

		var allFilled = inputs.every(function (inp) {
			if (inp.type === 'checkbox' || inp.type === 'radio') {
				var group = panels[curIdx].querySelectorAll('[name="' + inp.name + '"]');
				return Array.prototype.some.call(group, function (g) { return g.checked; });
			}
			return inp.value && inp.value.trim() !== '';
		});

		if (allFilled) {
			setTimeout(function () {
				gotoPreviewStep(frame, panels, nextIdx);
			}, 350);
		}
	}

	function syncPreviewChecked(frame) {
		if (!frame) return;
		frame.querySelectorAll('.pf-inline-option').forEach(function (label) {
			var inp = label.querySelector('input[type="checkbox"], input[type="radio"]');
			if (inp) label.classList.toggle('is-checked', inp.checked);
		});
	}

	function getPreviewFieldValue(frame, name) {
		// Traži po name i name[] (checkbox)
		var byName = Array.prototype.slice.call(
			frame.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]')
		);
		if (!byName.length) return '';

		var type = byName[0].type;
		if (type === 'checkbox') {
			return byName.filter(function (el) { return el.checked; }).map(function (el) { return el.value; });
		}
		if (type === 'radio') {
			var checked = byName.filter(function (el) { return el.checked; });
			return checked.length ? checked[0].value : '';
		}
		if (type === 'select-one' || type === 'select-multiple') {
			return byName[0].value;
		}
		return byName[0].value;
	}

	function previewSingleCondMet(target, op, value) {
		var isArr = Array.isArray(target);
		switch (op) {
			case 'is_empty':     return isArr ? target.length === 0 : (target || '') === '';
			case 'is_not_empty': return isArr ? target.length > 0   : (target || '') !== '';
			case 'not_equals':   return isArr ? target.indexOf(value) === -1 : target !== value;
			case 'contains':     return isArr ? target.some(function(t){return t.toLowerCase().indexOf(value.toLowerCase())!==-1;}) : (target||'').toLowerCase().indexOf(value.toLowerCase())!==-1;
			case 'not_contains': return isArr ? !target.some(function(t){return t.toLowerCase().indexOf(value.toLowerCase())!==-1;}) : (target||'').toLowerCase().indexOf(value.toLowerCase())===-1;
			case 'starts_with':  return isArr ? target.some(function(t){return t.toLowerCase().indexOf(value.toLowerCase())===0;}) : (target||'').toLowerCase().indexOf(value.toLowerCase())===0;
			case 'greater_than': return parseFloat(isArr ? target[0] : target) > parseFloat(value);
			case 'less_than':    return parseFloat(isArr ? target[0] : target) < parseFloat(value);
			default:             return isArr ? target.indexOf(value) !== -1 : target === value;
		}
	}

	function previewEvalCond(frame, cond) {
		if (!cond) return true;
		if (!cond.rules) {
			if (!cond.field) return true;
			return previewSingleCondMet(getPreviewFieldValue(frame, cond.field), cond.operator || 'equals', cond.value || '');
		}
		if (!cond.rules.length) return true;
		var results = cond.rules.map(function (r) {
			return previewSingleCondMet(getPreviewFieldValue(frame, r.field), r.operator || 'equals', r.value || '');
		});
		return cond.match === 'any' ? results.some(Boolean) : results.every(Boolean);
	}

	function evaluatePreviewConditions(frame) {
		if (!frame) return;
		// Polja s uvjetom (novi data-pf-cond JSON)
		frame.querySelectorAll('[data-pf-cond]').forEach(function (el) {
			var cond = null;
			try { cond = JSON.parse(el.getAttribute('data-pf-cond')); } catch(e) {}
			el.style.display = previewEvalCond(frame, cond) ? '' : 'none';
		});
	}

	// Je li stranica vidljiva u previewu?
	function isPreviewStepVisible(frame, panel) {
		var f = panel.getAttribute('data-step-cond-field');
		if (!f) return true;
		return previewSingleCondMet(
			getPreviewFieldValue(frame, f),
			panel.getAttribute('data-step-cond-op') || 'equals',
			panel.getAttribute('data-step-cond-value') || ''
		);
	}

	function findPreviewVisibleStep(frame, panels, fromIdx, dir) {
		var i = fromIdx + dir;
		while (i >= 0 && i < panels.length) {
			if (isPreviewStepVisible(frame, panels[i])) return i;
			i += dir;
		}
		return -1;
	}

	function updatePreviewStepButtons(frame) {
		if (!frame) return;
		var panels = Array.prototype.slice.call(frame.querySelectorAll('.pf-step-panel'));
		panels.forEach(function (panel, idx) {
			var nextBtn = panel.querySelector('.pf-preview-next');
			var submitBtn = panel.querySelector('.pf-preview-submit');
			if (!nextBtn || !submitBtn) return;
			var hasNext = findPreviewVisibleStep(frame, panels, idx, +1) !== -1;
			nextBtn.style.display   = hasNext ? '' : 'none';
			submitBtn.style.display = hasNext ? 'none' : '';
		});
		// Step indicator — sakrij nevidljive
		var dots = frame.querySelectorAll('.pf-step-dot');
		panels.forEach(function (panel, idx) {
			var vis = isPreviewStepVisible(frame, panel);
			if (dots[idx]) {
				dots[idx].style.display = vis ? '' : 'none';
				var line = dots[idx].nextElementSibling;
				if (line && line.classList.contains('pf-step-line')) line.style.display = vis ? '' : 'none';
			}
		});
	}

	$('#pf-preview-close').on('click', function () {
		$('#pf-preview-modal').removeClass('is-open');
	});

	$(document).on('click', '#pf-preview-modal', function (e) {
		if (e.target === this) {
			$(this).removeClass('is-open');
		}
	});

	$('.pf-preview-device-btn').on('click', function () {
		$('.pf-preview-device-btn').removeClass('is-active');
		$(this).addClass('is-active');
		$('#pf-preview-frame').toggleClass('is-mobile', $(this).data('device') === 'mobile');
	});

	/* ---------------------------------------------------------
	 *  Edit page tab switching (Polja / Izgled / Postavke / AI)
	 * --------------------------------------------------------- */
	$('.pf-edit-tab').on('click', function () {
		if ($(this).hasClass('pf-tab-disabled')) return;
		var tab = $(this).data('tab');
		$('.pf-edit-tab').removeClass('is-active');
		$(this).addClass('is-active');
		$('.pf-edit-tab-content').hide();
		$('.pf-edit-tab-content[data-tab="' + tab + '"]').show();
		if (tab === 'theme') renderThemePreview();
	});

	/* ---------------------------------------------------------
	 *  Inline title editing
	 * --------------------------------------------------------- */
	var $titleDisplay = $('#pf-title-display');
	var $titleInput   = $('#pf-title-input');
	var $titleHint    = $('.pf-title-hint');
	var $titleHidden  = $('#pf-hidden-title');

	$titleDisplay.on('click', function () {
		$titleDisplay.hide();
		$titleHint.show();
		$titleInput.show().val($titleHidden.val()).focus().select();
	});

	function commitTitle() {
		var val = $titleInput.val().trim();
		$titleInput.hide();
		$titleHint.hide();
		$titleDisplay.show();
		if (val) {
			$titleHidden.val(val);
			$titleDisplay.html(escapeHtml(val));
		}
	}

	$titleInput.on('keydown', function (e) {
		if (e.key === 'Enter')  { e.preventDefault(); commitTitle(); }
		if (e.key === 'Escape') { $titleInput.val($titleHidden.val()); commitTitle(); }
	}).on('blur', commitTitle);

	/* ---------------------------------------------------------
	 *  Settings tab — sync live fields → hidden inputs
	 * --------------------------------------------------------- */
	function syncSettings() {
		$('#pf-hidden-success').val($('#pf-success').val());
		$('#pf-hidden-submit-label').val($('#pf-submit-label').val());
		$('#pf-hidden-ar-enabled').val($('#pf-ar-enabled-check').is(':checked') ? '1' : '');
		$('#pf-hidden-ar-subject').val($('#pf-ar-subject').val());
		$('#pf-hidden-ar-message').val($('#pf-ar-message').val());
	}

	$(document).on('change input', '#pf-success, #pf-submit-label, #pf-ar-enabled-check, #pf-ar-subject, #pf-ar-message', syncSettings);

	// Sync once on load
	syncSettings();

	/* ---------------------------------------------------------
	 *  Copy shortcode
	 * --------------------------------------------------------- */
	$(document).on('click', '.pf-copy-btn', function () {
		var text = $(this).data('copy');
		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(() => {
				var $btn = $(this);
				$btn.html('<span class="dashicons dashicons-yes"></span> Kopirano!');
				setTimeout(function () {
					$btn.html('<span class="dashicons dashicons-clipboard"></span> Kopiraj');
				}, 2000);
			});
		}
	});

	// Header meta shortcode click
	$(document).on('click', '.pf-meta-shortcode', function () {
		var text = $(this).text().trim();
		if (navigator.clipboard) {
			navigator.clipboard.writeText(text);
			$(this).addClass('pf-copied');
			setTimeout(() => $(this).removeClass('pf-copied'), 1500);
		}
	});

	/* ---------------------------------------------------------
	 *  Theme editor
	 * --------------------------------------------------------- */
	var pfCfg    = window.pfAdminCfg || {};
	var pfPresets = pfCfg.presets || {};
	var pfTheme  = {};

	// Inicijalizacija teme iz hidden input-a
	try {
		var raw = $('#pf-theme-json').val();
		pfTheme = raw ? JSON.parse(raw) : {};
	} catch(e) { pfTheme = {}; }

	function syncThemeToInput() {
		$('#pf-theme-json').val(JSON.stringify(pfTheme));
	}

	function renderThemePreview() {
		var t = pfTheme;
		var radius   = (t.border_radius || '8') + 'px';
		var font     = t.font_family || 'inherit';
		var btnBg    = t.button_style === 'filled' ? (t.primary_color || '#B5654A') : 'transparent';
		var btnBorder= t.button_style === 'ghost'  ? 'transparent' : (t.primary_color || '#B5654A');
		var btnText  = t.button_style === 'filled' ? (t.button_text || '#fff') : (t.primary_color || '#B5654A');

		// Label stil
		var labelStyle = t.label_style || 'normal';
		var labelFontSize, labelFontWeight, labelTransform, labelSpacing;
		if (labelStyle === 'uppercase') {
			labelFontSize = '10px'; labelFontWeight = '700';
			labelTransform = 'uppercase'; labelSpacing = '0.08em';
		} else if (labelStyle === 'light') {
			labelFontSize = '13px'; labelFontWeight = '400';
			labelTransform = 'none'; labelSpacing = '0';
		} else {
			labelFontSize = '14px'; labelFontWeight = '600';
			labelTransform = 'none'; labelSpacing = '0';
		}

		// Input veličina
		var inputFontSize = t.font_size === 'small' ? '13px' : t.font_size === 'large' ? '17px' : '15px';
		var inputPadding  = t.input_height === 'compact' ? '9px 12px' : t.input_height === 'spacious' ? '16px 16px' : '11px 14px';

		// Google Font import ako treba
		var fontImport = '';
		var fontMatch = font.match(/'([^']+)'/);
		if (fontMatch && fontMatch[1] !== 'inherit') {
			fontImport = '<style>@import url("https://fonts.googleapis.com/css2?family=' + encodeURIComponent(fontMatch[1]) + ':wght@400;500;600;700&display=swap");</style>';
		}

		var bg      = t.bg_color    || '#fff';
		var textClr = t.text_color  || '#2B2420';
		var labelClr= t.label_color || textClr;
		var border  = t.border_color|| '#DDD4C8';
		var inputBg = t.input_bg    || '#FBF8F4';
		var primary = t.primary_color || '#B5654A';

		function lbl(text) {
			return '<label style="display:block;font-family:' + escapeAttr(font) + ';font-size:' + labelFontSize + ';font-weight:' + labelFontWeight + ';text-transform:' + labelTransform + ';letter-spacing:' + labelSpacing + ';color:' + escapeAttr(labelClr) + ';margin-bottom:6px;">' + escapeHtml(text) + ' <span style="color:' + escapeAttr(primary) + '">*</span></label>';
		}

		function inp(placeholder) {
			return '<input disabled placeholder="' + escapeAttr(placeholder) + '" style="display:block;width:100%;padding:' + inputPadding + ';font-family:' + escapeAttr(font) + ';font-size:' + inputFontSize + ';border:1.5px solid ' + escapeAttr(border) + ';border-radius:' + radius + ';background:' + escapeAttr(inputBg) + ';color:' + escapeAttr(textClr) + ';box-sizing:border-box;-webkit-appearance:none;">';
		}

		function sel(option) {
			return '<select disabled style="display:block;width:100%;padding:' + inputPadding + ';font-family:' + escapeAttr(font) + ';font-size:' + inputFontSize + ';border:1.5px solid ' + escapeAttr(border) + ';border-radius:' + radius + ';background:' + escapeAttr(inputBg) + ';color:' + escapeAttr(textClr) + ';box-sizing:border-box;-webkit-appearance:none;"><option>' + escapeHtml(option) + '</option></select>';
		}

		function radio(text) {
			return '<label style="display:flex;align-items:center;gap:8px;font-family:' + escapeAttr(font) + ';font-size:' + inputFontSize + ';color:' + escapeAttr(textClr) + ';margin-bottom:6px;"><input type="radio" disabled style="accent-color:' + escapeAttr(primary) + ';"> ' + escapeHtml(text) + '</label>';
		}

		var html = fontImport;
		// Wrapper
		html += '<div style="font-family:' + escapeAttr(font) + ';background:' + escapeAttr(bg) + ';color:' + escapeAttr(textClr) + ';padding:24px;border-radius:' + radius + ';">';

		// Step indicator
		html += '<div style="display:flex;align-items:center;margin-bottom:20px;">';
		html += '<div style="width:28px;height:28px;border-radius:50%;background:' + escapeAttr(primary) + ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;font-family:' + escapeAttr(font) + ';flex-shrink:0;">1</div>';
		html += '<div style="flex:1;height:1px;background:' + escapeAttr(border) + ';margin:0 8px;"></div>';
		html += '<div style="width:28px;height:28px;border-radius:50%;background:' + escapeAttr(border) + ';color:#999;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;font-family:' + escapeAttr(font) + ';flex-shrink:0;">2</div>';
		html += '</div>';

		// Polje 1 — text
		html += '<div style="margin-bottom:14px;">' + lbl('Ime i prezime') + inp('Ivica Ivić') + '</div>';

		// Polje 2 — select
		html += '<div style="margin-bottom:14px;">' + lbl('Vrsta projekta') + sel('Kuhinja po mjeri') + '</div>';

		// Polje 3 — radio
		html += '<div style="margin-bottom:14px;">';
		html += lbl('Imate li dizajn?');
		html += radio('Da, imam dizajn');
		html += radio('Trebam dizajn');
		html += '</div>';

		// Gumb
		html += '<button disabled style="width:100%;background:' + escapeAttr(btnBg) + ';color:' + escapeAttr(btnText) + ';border:2px solid ' + escapeAttr(btnBorder) + ';border-radius:' + radius + ';padding:' + inputPadding + ';font-family:' + escapeAttr(font) + ';font-size:' + inputFontSize + ';font-weight:700;letter-spacing:0.03em;cursor:pointer;box-sizing:border-box;">Sljedeći korak →</button>';

		html += '</div>';
		$('#pf-theme-preview').html(html);
	}

	// Color inputs (picker + text)
	$(document).on('input change', '.pf-color-input', function () {
		var prop = $(this).data('prop');
		var val  = $(this).val();
		pfTheme[prop] = val;
		$('.pf-color-text[data-prop="' + prop + '"]').val(val);
		syncThemeToInput();
		renderThemePreview();
	});

	$(document).on('change', '.pf-color-text', function () {
		var prop = $(this).data('prop');
		var val  = $(this).val();
		if (/^#[0-9a-fA-F]{6}$/.test(val)) {
			pfTheme[prop] = val;
			$('.pf-color-input[data-prop="' + prop + '"]').val(val);
			syncThemeToInput();
			renderThemePreview();
		}
	});

	// Range input (border-radius)
	$(document).on('input', '.pf-range-input', function () {
		var prop = $(this).data('prop');
		var val  = $(this).val();
		pfTheme[prop] = val;
		$('#pf-radius-label').text(val + 'px');
		syncThemeToInput();
		renderThemePreview();
	});

	// Select (font)
	$(document).on('change', '.pf-select-input', function () {
		var prop = $(this).data('prop');
		pfTheme[prop] = $(this).val();
		syncThemeToInput();
		renderThemePreview();
	});

	// Button style toggle
	$(document).on('click', '.pf-btn-style-opt', function () {
		$('.pf-btn-style-opt').removeClass('is-active');
		$(this).addClass('is-active');
		pfTheme.button_style = $(this).data('val');
		syncThemeToInput();
		renderThemePreview();
	});

	// Label style toggle
	$(document).on('click', '.pf-label-style-opt', function () {
		$('.pf-label-style-opt').removeClass('is-active');
		$(this).addClass('is-active');
		pfTheme.label_style = $(this).data('val');
		syncThemeToInput();
		renderThemePreview();
	});

	// Font size toggle
	$(document).on('click', '.pf-size-opt', function () {
		$('.pf-size-opt').removeClass('is-active');
		$(this).addClass('is-active');
		pfTheme.font_size = $(this).data('val');
		syncThemeToInput();
		renderThemePreview();
	});

	// Input height toggle
	$(document).on('click', '.pf-height-opt', function () {
		$('.pf-height-opt').removeClass('is-active');
		$(this).addClass('is-active');
		pfTheme.input_height = $(this).data('val');
		syncThemeToInput();
		renderThemePreview();
	});

	// Preset cards
	$(document).on('click', '.pf-preset-card', function () {
		var key = $(this).data('preset');
		var preset = pfPresets[key];
		if (!preset) return;

		pfTheme = $.extend({}, preset);
		delete pfTheme.label;
		syncThemeToInput();

		// Ažuriraj sve kontrole
		Object.keys(pfTheme).forEach(function (prop) {
			var val = pfTheme[prop];
			$('.pf-color-input[data-prop="' + prop + '"]').val(val);
			$('.pf-color-text[data-prop="' + prop + '"]').val(val);
			$('.pf-range-input[data-prop="' + prop + '"]').val(val);
			$('.pf-select-input[data-prop="' + prop + '"]').val(val);
		});
		$('#pf-radius-label').text((pfTheme.border_radius || '8') + 'px');
		$('.pf-btn-style-opt').removeClass('is-active');
		$('.pf-btn-style-opt[data-val="' + (pfTheme.button_style || 'filled') + '"]').addClass('is-active');
		$('.pf-label-style-opt').removeClass('is-active');
		$('.pf-label-style-opt[data-val="' + (pfTheme.label_style || 'normal') + '"]').addClass('is-active');
		$('.pf-size-opt').removeClass('is-active');
		$('.pf-size-opt[data-val="' + (pfTheme.font_size || 'medium') + '"]').addClass('is-active');
		$('.pf-height-opt').removeClass('is-active');
		$('.pf-height-opt[data-val="' + (pfTheme.input_height || 'normal') + '"]').addClass('is-active');

		$('.pf-preset-card').removeClass('is-active');
		$(this).addClass('is-active');
		renderThemePreview();
	});

	/* ---------------------------------------------------------
	 *  AI Asistent chat
	 * --------------------------------------------------------- */
	var aiHistory = [];

	function getFormContext() {
		var clean = JSON.parse(JSON.stringify(pfSteps, function (k, v) {
			if (k === '_uid' || k === '_nameAuto') return undefined;
			return v;
		}));

		// Flat popis polja za lakšu referencu
		var flatFields = [];
		pfSteps.forEach(function (step, si) {
			step.rows.forEach(function (row) {
				row.cells.forEach(function (cell) {
					cell.forEach(function (f) {
						if (f.name || f.type === 'section_divider') {
							flatFields.push({
								step:  si + 1,
								type:  f.type,
								label: f.label,
								name:  f.name || '',
								required: !!f.required
							});
						}
					});
				});
			});
		});

		return JSON.stringify({
			form_title:  $('#pf-hidden-title').val() || $('#pf-title-input').val() || '(bez naziva)',
			total_steps: pfSteps.length,
			total_fields: flatFields.length,
			flat_fields:  flatFields,
			steps:        clean
		});
	}

	function appendAiMessage(role, text) {
		var $wrap = $('#pf-ai-messages');
		var cls   = role === 'user' ? 'pf-ai-msg-user' : 'pf-ai-msg-agent';
		var $msg  = $('<div class="pf-ai-msg ' + cls + '"><div class="pf-ai-bubble"></div></div>');
		$msg.find('.pf-ai-bubble').text(text);
		$wrap.append($msg);
		$wrap.scrollTop($wrap[0].scrollHeight);
	}

	function appendAiTyping() {
		var $wrap = $('#pf-ai-messages');
		var $el   = $('<div class="pf-ai-msg pf-ai-msg-agent pf-ai-typing" id="pf-ai-typing"><div class="pf-ai-bubble"><span></span><span></span><span></span></div></div>');
		$wrap.append($el);
		$wrap.scrollTop($wrap[0].scrollHeight);
	}

	function applyAiActions(actions) {
		if (!actions || !actions.length) return;

		var changed = false;

		actions.forEach(function (action) {
			switch (action.type) {

				case 'replace_all':
					if (action.steps && Array.isArray(action.steps)) {
						pfSteps = action.steps;
						allFieldsFlat().forEach(function (f) { ensureUid(f); f._nameAuto = false; f.options = f.options || []; f.condition = f.condition || null; });
						changed = true;
					}
					break;

				case 'add_field':
					var stepIdx = (action.step || 1) - 1;
					if (!pfSteps[stepIdx]) {
						pfSteps.push({ rows: [{ cols: 1, cells: [[]] }] });
						stepIdx = pfSteps.length - 1;
					}
					var newField = defaultFieldForType(action.field.type || 'text');
					$.extend(newField, action.field);
					newField._nameAuto = false;
					ensureUid(newField);
					// Dodaj u zadnji red zadnjeg stupca te stranice
					var lastRow = pfSteps[stepIdx].rows[pfSteps[stepIdx].rows.length - 1];
					lastRow.cells[0].push(newField);
					changed = true;
					break;

				case 'update_field':
					allFieldsFlat().forEach(function (f) {
						if (f.name === action.name && action.changes) {
							$.extend(f, action.changes);
							changed = true;
						}
					});
					break;

				case 'delete_field':
					pfSteps.forEach(function (step) {
						step.rows.forEach(function (row) {
							row.cells.forEach(function (cell, ci) {
								row.cells[ci] = cell.filter(function (f) { return f.name !== action.name; });
							});
						});
					});
					changed = true;
					break;

				case 'add_step':
					pfSteps.push({ rows: [{ cols: 1, cells: [[]] }] });
					changed = true;
					break;

				case 'reorder_fields':
					var reorderStep = (action.step || 1) - 1;
					var reorderOrder = action.order || [];
					if (pfSteps[reorderStep] && reorderOrder.length) {
						// Skupi sva polja s te stranice po name-u
						var fieldMap = {};
						pfSteps[reorderStep].rows.forEach(function (row) {
							row.cells.forEach(function (cell) {
								cell.forEach(function (f) {
									if (f.name) fieldMap[f.name] = f;
								});
							});
						});
						// Rebuildiraj prvi red u novom redoslijedu
						var reordered = reorderOrder.map(function (n) { return fieldMap[n]; }).filter(Boolean);
						if (reordered.length) {
							pfSteps[reorderStep].rows = [{ cols: 1, cells: [reordered] }];
							changed = true;
						}
					}
					break;
			}
		});

		if (changed) {
			activeStep = 0;
			renderCanvas();

			// Prikaz popisa promjena
			var $sugg = $('#pf-ai-suggestions').empty();
			actions.forEach(function (a) {
				var desc = '';
				switch (a.type) {
					case 'add_field':    desc = '➕ Dodano polje: ' + (a.field && a.field.label || ''); break;
					case 'update_field': desc = '✏️ Ažurirano: ' + (a.name || ''); break;
					case 'delete_field': desc = '🗑️ Obrisano: ' + (a.name || ''); break;
					case 'add_step':     desc = '📄 Dodana nova stranica'; break;
					case 'replace_all':  desc = '🔄 Forma potpuno zamijenjena'; break;
				}
				if (desc) $sugg.append('<div class="pf-ai-change-item">' + escapeHtml(desc) + '</div>');
			});
		}
	}

	function sendAiMessage() {
		var msg = $('#pf-ai-input').val().trim();
		if (!msg) return;

		$('#pf-ai-input').val('').css('height', 'auto');
		$('#pf-ai-send').prop('disabled', true);

		appendAiMessage('user', msg);
		appendAiTyping();

		$.post({
			url: (pfCfg.ajaxUrl || ajaxurl) + '?action=pf_ai_chat',
			data: {
				nonce:        pfCfg.aiNonce || '',
				message:      msg,
				history:      JSON.stringify(aiHistory),
				form_context: getFormContext(),
			},
			success: function (res) {
				$('#pf-ai-typing').remove();
				$('#pf-ai-send').prop('disabled', false);

				if (res.success) {
					var agentMsg = res.data.message;
					appendAiMessage('agent', agentMsg);

					aiHistory.push({ role: 'user',      content: msg });
					aiHistory.push({ role: 'assistant', content: agentMsg });
					if (aiHistory.length > 40) aiHistory = aiHistory.slice(-40);

					applyAiActions(res.data.actions);
				} else {
					appendAiMessage('agent', '❌ ' + (res.data || 'Greška. Pokušaj ponovo.'));
				}
			},
			error: function () {
				$('#pf-ai-typing').remove();
				$('#pf-ai-send').prop('disabled', false);
				appendAiMessage('agent', '❌ Greška u komunikaciji. Provjeri internet vezu.');
			}
		});
	}

	$('#pf-ai-send').on('click', sendAiMessage);
	$('#pf-ai-input').on('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			sendAiMessage();
		}
	});
	$('#pf-ai-clear').on('click', function () {
		aiHistory = [];
		$('#pf-ai-messages').html('<div class="pf-ai-msg pf-ai-msg-agent"><div class="pf-ai-bubble">Razgovor je obrisan. Što trebamo napraviti? 👋</div></div>');
		$('#pf-ai-suggestions').html('<p class="pf-ai-empty-hint">Ovdje će se prikazati prijedlozi izmjena.</p>');
	});
	// Auto-resize textarea
	$('#pf-ai-input').on('input', function () {
		this.style.height = 'auto';
		this.style.height = Math.min(this.scrollHeight, 140) + 'px';
	});
});
