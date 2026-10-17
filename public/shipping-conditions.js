/**
 * Shipping conditions UI.
 *
 * Syncs rule condition/operator/value widgets on the shipping settings page:
 *  - restores saved condition & operator selections (select[data-saved-value])
 *  - swaps the value widget between number/text input and entity autocomplete
 *    based on the selected condition option data-value-type / data-autocomplete-url
 *  - restricts available operators for match-type conditions
 *  - handles rules added dynamically through the field-group templates
 */
(function () {
	'use strict';

	const AUTOCOMPLETE_CLASS = 'autocomplete';

	function selectedOption(select) {
		return select && select.selectedOptions && select.selectedOptions[0] ? select.selectedOptions[0] : null;
	}

	function initAutocomplete(input) {
		if (!input || input.dataset.acInit || typeof window._AutocompleteInput !== 'function') {
			return;
		}
		input.dataset.acInit = '1';
		new window._AutocompleteInput(input, input.dataset);
	}

	// Rebuild a clean plain value input, dropping any autocomplete wrappers.
	function resetValueWidget(rule) {
		let input = rule.querySelector('[data-v-rule-value]');

		if (!input) {
			return null;
		}

		const wrap = input.closest('.rule-value-wrap') || input.parentNode;

		// after _AutocompleteInput init the original name lives on the hidden sibling
		const hidden = wrap.querySelector('input[type="hidden"][name$="[value]"]');
		const name = hidden ? hidden.getAttribute('name') : input.getAttribute('name');

		wrap.innerHTML = '';

		input = document.createElement('input');
		input.setAttribute('type', 'text');
		input.setAttribute('class', 'form-control');
		input.setAttribute('name', name);
		input.setAttribute('value', '');
		input.setAttribute('placeholder', 'Value');
		input.required = true;
		input.setAttribute('data-v-rule-value', '');

		wrap.appendChild(input);

		return input;
	}

	function applyConfig(rule) {
		const condSelect = rule.querySelector('[data-v-rule-condition]');
		const opSelect   = rule.querySelector('[data-v-rule-operator]');
		const input      = rule.querySelector('[data-v-rule-value]');

		if (!condSelect) {
			return;
		}

		const opt  = selectedOption(condSelect);
		const cfg  = opt ? opt.dataset : {};
		const type = cfg.valueType || 'number';
		const wrap = input ? (input.closest('.rule-value-wrap') || input.parentNode) : null;

		if (!input) {
			return;
		}

		const always = (type === 'none');

		// hide value widget for "always" conditions
		if (wrap) {
			wrap.classList.toggle('d-none', always);
		}
		input.required = !always;

		// entity values use an autocomplete input fed by the configured url
		if (type === 'entity' && cfg.autocompleteUrl) {
			input.classList.add(AUTOCOMPLETE_CLASS);
			input.setAttribute('data-url', cfg.autocompleteUrl);
			initAutocomplete(input);
		} else {
			input.classList.remove(AUTOCOMPLETE_CLASS);
			delete input.dataset.url;
		}

		// restrict operators for match/coupon conditions (equality only)
		if (opSelect) {
			opSelect.disabled = always;

			const equalityOnly = ['entity', 'coupon'].includes(type);
			let valid          = true;

			opSelect.querySelectorAll('option').forEach((option) => {
				const restricted = equalityOnly && !['==', '!='].includes(option.value);

				option.hidden    = restricted;
				option.disabled  = restricted;

				if (restricted && option.selected) {
					valid = false;
				}
			});

			if (always || !valid) {
				opSelect.value = '==';
			}
		}
	}

	function syncSavedValues(root) {
		root.querySelectorAll('select[data-saved-value]').forEach((select) => {
			if (select.dataset.savedValue !== undefined && select.dataset.savedValue !== '') {
				select.value = select.dataset.savedValue;
			}
		});
	}

	function scan(root, resetValues) {
		syncSavedValues(root);

		root.querySelectorAll('.rule[data-v-rule]').forEach((rule) => {
			if (resetValues) {
				resetValueWidget(rule);
			}
			applyConfig(rule);
		});
	}

	document.addEventListener('change', function (e) {
		const condSelect = e.target.closest('[data-v-rule-condition]');

		if (condSelect) {
			const rule = condSelect.closest('.rule[data-v-rule]');

			if (rule) {
				resetValueWidget(rule);
				applyConfig(rule);
			}
		}
	});

	// rules are added dynamically by field-group.js ("and" / "+ Add rule group")
	document.addEventListener('click', function (e) {
		if (e.target.closest('.conditionals .btn-add, .conditionals .btn-rule-group-add')) {
			setTimeout(function () {
				scan(document, false);
			}, 0);
		}
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			scan(document, false);
		});
	} else {
		scan(document, false);
	}
})();

	//gross/net fee radio only applies with a tax class selected (the server
	//ignores the flag when tax class is None, this just makes it obvious)
	function syncTaxIncludedRow(select) {
		var pane = select.closest('.tab-pane');
		var row = pane ? pane.querySelector('[data-v-tax-included-row]') : null;

		if (! row) {
			return;
		}

		var disabled = ! select.value || select.value === '0';

		row.querySelectorAll('input[data-v-tax-included]').forEach(function (input) {
			input.disabled = disabled;
		});
		row.style.opacity = disabled ? '0.5' : '';
	}

	document.addEventListener('change', function (e) {
		if (e.target && e.target.matches && e.target.matches('select[data-v-tax_type_id]')) {
			syncTaxIncludedRow(e.target);
		}
	});

	function initTaxIncludedRows() {
		document.querySelectorAll('select[data-v-tax_type_id]').forEach(syncTaxIncludedRow);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initTaxIncludedRows);
	} else {
		initTaxIncludedRows();
	}
