/**
 * Media Library Fixer tool page: runs each module's scan and fix through admin-ajax, one batch per request.
 *
 * @package Media_Library_Fixer
 * @since 0.1.0
 */

(function () {
	'use strict';

	const config = window.mlfixerToolPage;
	const strings = config.strings;

	/**
	 * Fill a translated sprintf-style template: %s, %d and their numbered forms.
	 *
	 * @param {string} template Template.
	 * @param {...*}   values   Values in order.
	 * @return {string} Filled string.
	 */
	function format(template, ...values) {
		let nextIndex = 0;

		return template.replace(/%(?:(\d+)\$)?[sd]/g, (match, position) => {
			const value = position ? values[Number(position) - 1] : values[nextIndex++];
			return String(value);
		});
	}

	/**
	 * Send one operation for one module and return the response data.
	 *
	 * @param {string} moduleId  Module ID.
	 * @param {string} operation Operation name.
	 * @param {Object} extra     Additional POST fields.
	 * @return {Promise<Object>} Response data; rejects with the server's message on failure.
	 */
	async function request(moduleId, operation, extra = {}) {
		const body = new FormData();

		body.append('action', config.action);
		body.append('_ajax_nonce', config.nonce);
		body.append('module', moduleId);
		body.append('operation', operation);

		for (const [name, value] of Object.entries(extra)) {
			body.append(name, value);
		}

		const response = await fetch(config.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
		const text = await response.text();
		let payload = null;

		try {
			payload = JSON.parse(text);
		} catch (parseError) {
			// A PHP fatal or a proxy timeout returns HTML; report the status rather than the parse error.
			throw new Error(`${response.status} ${response.statusText}`.trim() || parseError.message);
		}

		if (!payload || !payload.success) {
			throw new Error((payload && payload.data && payload.data.message) || `${response.status} ${response.statusText}`);
		}

		return payload.data;
	}

	/**
	 * Create an element with optional class and text.
	 *
	 * @param {string} tagName   Tag name.
	 * @param {string} className Class attribute.
	 * @param {string} text      Text content.
	 * @return {HTMLElement} Element.
	 */
	function createElement(tagName, className = '', text = '') {
		const element = document.createElement(tagName);

		if (className) {
			element.className = className;
		}

		if (text) {
			element.textContent = text;
		}

		return element;
	}

	/**
	 * One module's panel on the page.
	 */
	class ModulePanel {
		/**
		 * @param {HTMLElement} section      The module's section element.
		 * @param {Object}      moduleConfig Module settings from PHP.
		 */
		constructor(section, moduleConfig) {
			this.moduleConfig = moduleConfig;
			this.scanButton = section.querySelector('[data-role="scan"]');
			this.stopButton = section.querySelector('[data-role="stop"]');
			this.clearButton = section.querySelector('[data-role="clear"]');
			this.statusElement = section.querySelector('[data-role="status"]');
			this.progressElement = section.querySelector('[data-role="progress"]');
			this.progressBar = this.progressElement.querySelector('progress');
			this.progressText = section.querySelector('[data-role="progress-text"]');
			this.resultsElement = section.querySelector('[data-role="results"]');
			this.logElement = section.querySelector('[data-role="log"]');
			this.selectedKeys = new Set();
			this.refreshSelection = null;
			this.stopRequested = false;
			this.busy = false;

			this.scanButton.addEventListener('click', () => this.runScan());
			this.stopButton.addEventListener('click', () => this.requestStop());
			this.clearButton.addEventListener('click', () => this.clearResults());
		}

		/**
		 * Load and show the stored state.
		 */
		async init() {
			try {
				this.render(await request(this.moduleConfig.id, 'status'));
			} catch (error) {
				this.showError(error);
			}

			this.scanButton.disabled = false;
		}

		/**
		 * Run a fresh scan to completion, or until stopped.
		 */
		async runScan() {
			this.clearLog();
			await this.runOperation(async () => {
				const data = await request(this.moduleConfig.id, 'scan_start');
				return this.repeatSteps('scan_step', 'scanning', data);
			});
		}

		/**
		 * Fix the selected findings, after confirmation where the module asks for it.
		 */
		async runFix() {
			const keys = Array.from(this.selectedKeys);

			if (keys.length > 0 && (!this.moduleConfig.confirmation || window.confirm(this.moduleConfig.confirmation))) {
				this.clearLog();
				await this.runOperation(async () => {
					const data = await request(this.moduleConfig.id, 'fix_start', { keys: JSON.stringify(keys) });
					return this.repeatSteps('fix_step', 'fixing', data);
				});
			}
		}

		/**
		 * Carry on with a scan or fix that was stopped or interrupted.
		 *
		 * @param {string} phase The phase left unfinished.
		 */
		async resume(phase) {
			const operation = 'scanning' === phase ? 'scan_step' : 'fix_step';

			await this.runOperation(async () => this.repeatSteps(operation, phase, { phase, done: 0, total: 0 }));
		}

		/**
		 * Run a scan or fix with the controls locked, then show the result.
		 *
		 * @param {Function} operation Async function returning the final response data.
		 */
		async runOperation(operation) {
			this.setBusy(true);

			try {
				const data = await operation();

				// A stopped run ends mid-phase, when the response carries no findings.
				this.render(data.findings ? data : await request(this.moduleConfig.id, 'status'));
			} catch (error) {
				this.showError(error);
			}

			this.setBusy(false);
		}

		/**
		 * Request steps until the phase changes or the user stops.
		 *
		 * @param {string} operation Step operation.
		 * @param {string} phase     Phase the steps advance.
		 * @param {Object} data      Response that started the phase.
		 * @return {Promise<Object>} Last response.
		 */
		async repeatSteps(operation, phase, data) {
			let latest = data;

			while (latest.phase === phase && !this.stopRequested) {
				this.showProgress(phase, latest);
				latest = await request(this.moduleConfig.id, operation);
				this.appendMessages(latest.messages || []);
			}

			if ('fixing' === phase && latest.phase !== phase) {
				this.appendMessages([{ type: 'summary', text: format(strings.fixSummary, latest.fixedCount, latest.failedCount) }]);
			}

			return latest;
		}

		/**
		 * Ask the running loop to stop after the current request.
		 */
		requestStop() {
			if (this.busy) {
				this.stopRequested = true;
				this.stopButton.disabled = true;
				this.statusElement.textContent = strings.stopping;
			}
		}

		/**
		 * Forget the stored results.
		 */
		async clearResults() {
			this.clearLog();
			await this.runOperation(async () => request(this.moduleConfig.id, 'reset'));
		}

		/**
		 * Lock or unlock the controls while a run is in progress.
		 *
		 * @param {boolean} busy Whether a run is in progress.
		 */
		setBusy(busy) {
			this.busy = busy;
			this.stopRequested = false;
			this.scanButton.disabled = busy;
			this.stopButton.hidden = !busy;
			this.stopButton.disabled = false;
			this.clearButton.disabled = busy;
			this.progressElement.hidden = !busy;

			for (const control of this.resultsElement.querySelectorAll('button, input')) {
				control.disabled = busy;
			}

			// Unlocking re-enables the fix button, which must stay disabled with nothing selected.
			if (!busy && this.refreshSelection) {
				this.refreshSelection();
			}
		}

		/**
		 * Update the progress bar.
		 *
		 * @param {string} phase Current phase.
		 * @param {Object} data  Response with done and total.
		 */
		showProgress(phase, data) {
			this.statusElement.textContent = 'scanning' === phase ? strings.scanning : strings.fixing;

			if (data.total > 0) {
				this.progressBar.max = data.total;
				this.progressBar.value = data.done;
				this.progressText.textContent = format(strings.progress, data.done, data.total);
			} else {
				// No total yet: an indeterminate bar.
				this.progressBar.removeAttribute('value');
				this.progressText.textContent = '';
			}
		}

		/**
		 * Show the state: summary line, findings table, and a resume prompt for an unfinished run.
		 *
		 * @param {Object} data Response data.
		 */
		render(data) {
			const findings = data.findings || [];
			const unfinished = 'scanning' === data.phase || 'fixing' === data.phase;

			this.resultsElement.replaceChildren();
			this.selectedKeys.clear();
			this.refreshSelection = null;
			this.scanButton.textContent = data.scannedAt ? strings.rescan : strings.scan;
			this.clearButton.hidden = !data.scannedAt && !unfinished;

			if (unfinished) {
				this.statusElement.textContent = strings.resumeFix;
				const continueButton = createElement('button', 'button', strings.continueRunning);
				continueButton.type = 'button';
				continueButton.addEventListener('click', () => this.resume(data.phase));
				this.resultsElement.append(continueButton);
			} else if (data.scannedAt) {
				this.statusElement.textContent = format(strings.scannedSummary, data.scannedAt, data.findingCount);

				if (data.scanErrors > 0) {
					this.resultsElement.append(createElement('p', 'mlfixer-warning', strings.scanErrors));
				}

				if (findings.length > 0) {
					this.resultsElement.append(this.buildTable(findings));
				}
			} else {
				this.statusElement.textContent = strings.notScanned;
			}
		}

		/**
		 * Build the selectable findings table with its action bar.
		 *
		 * @param {Object[]} findings Findings.
		 * @return {DocumentFragment} Action bar and table.
		 */
		buildTable(findings) {
			const fragment = document.createDocumentFragment();
			const actionBar = createElement('div', 'mlfixer-actions');
			const selectAllLabel = createElement('label');
			const selectAll = createElement('input');
			const selectedCount = createElement('span', 'mlfixer-selected-count');
			const fixButton = createElement('button', 'button button-primary', this.moduleConfig.fixLabel);
			const scroller = createElement('div', 'mlfixer-table-scroll');
			const table = createElement('table', 'widefat striped mlfixer-table');
			const headerRow = createElement('tr');
			const body = createElement('tbody');
			const rowCheckboxes = [];

			const refreshSelection = () => {
				selectedCount.textContent = format(strings.selectedCount, this.selectedKeys.size);
				fixButton.disabled = 0 === this.selectedKeys.size;
				selectAll.checked = this.selectedKeys.size === findings.length;
				selectAll.indeterminate = this.selectedKeys.size > 0 && this.selectedKeys.size < findings.length;
			};

			selectAll.type = 'checkbox';
			selectAll.addEventListener('change', () => {
				for (const checkbox of rowCheckboxes) {
					checkbox.checked = selectAll.checked;
					if (selectAll.checked) {
						this.selectedKeys.add(checkbox.value);
					} else {
						this.selectedKeys.delete(checkbox.value);
					}
				}
				refreshSelection();
			});
			selectAllLabel.append(selectAll, ` ${strings.selectAll}`);

			fixButton.type = 'button';
			fixButton.addEventListener('click', () => this.runFix());
			actionBar.append(selectAllLabel, selectedCount, fixButton);

			headerRow.append(
				createElement('td', 'check-column'),
				createElement('th', '', this.moduleConfig.columns.label),
				createElement('th', '', this.moduleConfig.columns.detail)
			);
			headerRow.querySelectorAll('th').forEach((cell) => cell.setAttribute('scope', 'col'));

			for (const finding of findings) {
				const row = createElement('tr');
				const checkCell = createElement('th', 'check-column');
				const checkbox = createElement('input');
				const labelCell = createElement('td');

				checkCell.scope = 'row';
				checkbox.type = 'checkbox';
				checkbox.value = finding.key;
				checkbox.setAttribute('aria-label', finding.label);
				checkbox.addEventListener('change', () => {
					if (checkbox.checked) {
						this.selectedKeys.add(finding.key);
					} else {
						this.selectedKeys.delete(finding.key);
					}
					refreshSelection();
				});
				rowCheckboxes.push(checkbox);
				checkCell.append(checkbox);

				if (finding.url) {
					const link = createElement('a', '', finding.label);
					link.href = finding.url;
					link.target = '_blank';
					link.rel = 'noopener';
					labelCell.append(link);
				} else {
					labelCell.textContent = finding.label;
				}

				row.append(checkCell, labelCell, createElement('td', '', finding.detail));
				body.append(row);
			}

			table.append(createElement('thead'), body);
			table.tHead.append(headerRow);
			scroller.append(table);
			fragment.append(actionBar, scroller);
			refreshSelection();
			this.refreshSelection = refreshSelection;

			return fragment;
		}

		/**
		 * Append fix messages to the log.
		 *
		 * @param {Object[]} messages Messages with type and text.
		 */
		appendMessages(messages) {
			for (const message of messages) {
				this.logElement.append(createElement('li', `mlfixer-log-${message.type}`, message.text));
			}

			if (messages.length > 0) {
				this.logElement.hidden = false;
				this.logElement.scrollTop = this.logElement.scrollHeight;
			}
		}

		/**
		 * Empty the log.
		 */
		clearLog() {
			this.logElement.replaceChildren();
			this.logElement.hidden = true;
		}

		/**
		 * Show a failed request in the status line.
		 *
		 * @param {Error} error The error.
		 */
		showError(error) {
			this.statusElement.textContent = `${strings.requestFailed} ${error.message}`;
			this.statusElement.classList.add('mlfixer-error');
			window.console.error('Media Library Fixer:', error);
		}
	}

	for (const moduleConfig of config.modules) {
		const section = document.querySelector(`.mlfixer-module[data-module="${moduleConfig.id}"]`);

		if (section) {
			new ModulePanel(section, moduleConfig).init();
		}
	}
})();
