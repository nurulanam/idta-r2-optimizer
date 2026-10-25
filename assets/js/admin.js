/**
 * IDTA R2 Optimizer Administrative Interactive Script
 */

/* global idtaR2Data, jQuery */
(function ($) {
	'use strict';

	var state = {
		allFolders: [],
		filteredFolders: [],
		activeFilter: 'all',
		searchQuery: '',
		currentPage: 1,
		perPage: 25,
		isScanning: false,
		dryRunCandidates: [],
		dryRunBucket: '',
	};

	$(document).ready(function () {
		initConnectionTest();
		initScanner();
		initTableFilters();
		initInspectorModal();
		initRowActions();
		initRemapModal();
		initBulkActions();
		initDryRunModal();
		initAuditLogModal();
		loadInitialState();
	});

	/**
	 * 1. Connection Test on Settings Screen
	 */
	function initConnectionTest() {
		var $btn = $('#idtaR2BtnTestConnection');
		if (!$btn.length) return;

		var $spinner = $('#idtaR2TestSpinner');
		var $result = $('#idtaR2TestResult');

		$btn.on('click', function () {
			$btn.prop('disabled', true);
			$spinner.addClass('is-active');
			$result.attr('hidden', true).removeClass('is-success is-error');

			var data = {
				action: 'idta_r2_test_connection',
				nonce: idtaR2Data.nonces.testConnection,
				account_id: $('#idta_r2_account_id').val(),
				access_key_id: $('#idta_r2_access_key_id').val(),
				secret_access_key: $('#idta_r2_secret_access_key').val(),
				custom_endpoint: $('#idta_r2_custom_endpoint').val(),
			};

			$.post(idtaR2Data.ajaxUrl, data)
				.done(function (res) {
					if (res.success && res.data) {
						$result.addClass('is-success').text(res.data.message || idtaR2Data.i18n.connectionSuccess).removeAttr('hidden');
					} else {
						var msg = (res.data && res.data.message) ? res.data.message : idtaR2Data.i18n.connectionFailed;
						$result.addClass('is-error').text(msg).removeAttr('hidden');
					}
				})
				.fail(function (xhr) {
					var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
						xhr.responseJSON.data.message : idtaR2Data.i18n.connectionFailed;
					$result.addClass('is-error').text(msg).removeAttr('hidden');
				})
				.always(function () {
					$btn.prop('disabled', false);
					$spinner.removeClass('is-active');
				});
		});
	}

	/**
	 * 2. Background Scanner Controller
	 */
	function initScanner() {
		var $btnScan = $('#idtaR2BtnStartScan');
		var $btnReset = $('#idtaR2BtnResetScan');
		var $yearSelect = $('#idtaR2FilterYear');
		var $monthSelect = $('#idtaR2FilterMonth');

		if (!$btnScan.length) return;

		// Year change enables month dropdown.
		$yearSelect.on('change', function () {
			var year = $(this).val();
			if (year) {
				$monthSelect.prop('disabled', false);
			} else {
				$monthSelect.val('').prop('disabled', true);
			}
		});

		$btnScan.on('click', function () {
			if (state.isScanning) return;
			startScan();
		});

		$btnReset.on('click', function () {
			resetScan();
		});
	}

	function startScan() {
		state.isScanning = true;
		var $btnScan = $('#idtaR2BtnStartScan');
		var $btnReset = $('#idtaR2BtnResetScan');
		var $progressWrap = $('#scanProgressWrap');
		var $progressBar = $('#scanProgressBar');
		var $progressStatus = $('#scanProgressStatus');

		// Clear previous scan state and reset table before starting fresh scan
		state.allFolders = [];
		state.filteredFolders = [];
		state.currentPage = 1;
		renderTable();

		$btnScan.prop('disabled', true).find('#btnScanLabel').text(idtaR2Data.i18n.scanning);
		$btnReset.prop('disabled', true).attr('hidden', true).hide();
		$progressWrap.removeAttr('hidden').show();
		$progressBar.css('width', '5%');
		$progressStatus.html('<span class="spinner is-active"></span> ' + idtaR2Data.i18n.scanning);

		var bucket = $('#idtaR2SelectBucket').val();
		var year = $('#idtaR2FilterYear').val();
		var month = $('#idtaR2FilterMonth').val();

		var prefix = '';
		if (year) {
			prefix = year + '/';
			if (month) {
				prefix += month + '/';
			}
		}

		executeScanStep(bucket, prefix, true);
	}

	function executeScanStep(bucket, prefix, isReset) {
		var data = {
			action: 'idta_r2_scan_step',
			nonce: idtaR2Data.nonces.scan,
			bucket: bucket,
			prefix: prefix,
			reset: isReset ? 1 : 0,
		};

		$.post(idtaR2Data.ajaxUrl, data)
			.done(function (res) {
				if (res.success && res.data) {
					var scanData = res.data;
					updateMetricsUI(scanData.metrics);

					var folderCount = Object.keys(scanData.folders || {}).length;
					$('#scanProgressCount').text(folderCount + ' ' + idtaR2Data.i18n.foldersFound);

					// Progressively stream scanned folders into table
					state.allFolders = Object.values(scanData.folders || {});
					applyFilters();

					if (scanData.is_complete) {
						finishScan(scanData);
					} else {
						$('#scanProgressBar').css('width', '50%');
						executeScanStep(bucket, prefix, false);
					}
				} else {
					handleScanError((res.data && res.data.message) ? res.data.message : 'Scan error occurred');
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
					xhr.responseJSON.data.message : 'Server communication failure during scan';
				handleScanError(msg);
			});
	}

	function finishScan(scanData) {
		state.isScanning = false;
		var $btnScan = $('#idtaR2BtnStartScan');
		var $btnReset = $('#idtaR2BtnResetScan');
		var $progressBar = $('#scanProgressBar');
		var $progressStatus = $('#scanProgressStatus');

		$progressBar.css('width', '100%');
		$progressStatus.html('<span class="dashicons dashicons-yes text-green"></span> ' + idtaR2Data.i18n.scanComplete);
		$btnScan.prop('disabled', false).find('#btnScanLabel').text('Scan Bucket');
		$btnReset.prop('disabled', false).removeAttr('hidden').show();

		setTimeout(function () {
			$('#scanProgressWrap').attr('hidden', true).hide();
		}, 3000);

		state.allFolders = Object.values(scanData.folders || {});
		applyFilters();
	}

	function handleScanError(msg) {
		state.isScanning = false;
		$('#scanProgressStatus').html('<span class="dashicons dashicons-warning text-red"></span> ' + msg);
		$('#idtaR2BtnStartScan').prop('disabled', false).find('#btnScanLabel').text('Scan Bucket');
		$('#idtaR2BtnResetScan').prop('disabled', false);
	}

	function resetScan() {
		var $btnReset = $('#idtaR2BtnResetScan');
		$btnReset.prop('disabled', true);

		$.post(idtaR2Data.ajaxUrl, {
			action: 'idta_r2_reset_scan',
			nonce: idtaR2Data.nonces.scan,
		}).always(function () {
			$btnReset.prop('disabled', false).attr('hidden', true);
			$('#scanProgressWrap').attr('hidden', true);
			state.allFolders = [];
			state.filteredFolders = [];
			applyFilters();
			updateMetricsUI({
				total_folders: 0,
				formatted_total_size: '0 B',
				connected_count: 0,
				formatted_connected_size: '0 B',
				orphaned_count: 0,
				formatted_orphaned_size: '0 B',
				broken_count: 0,
				formatted_broken_size: '0 B',
				estimated_monthly_savings: 0,
			});
		});
	}

	/**
	 * 3. Update KPI UI Cards
	 */
	function updateMetricsUI(m) {
		if (!m) return;
		$('#kpiTotalFolders').text(m.total_folders || 0);
		$('#kpiTotalSize').text(m.formatted_total_size || '0 B');
		$('#kpiConnectedCount').text(m.connected_count || 0);
		$('#kpiConnectedSize').text(m.formatted_connected_size || '0 B');
		$('#kpiOrphanedCount').text(m.orphaned_count || 0);
		$('#kpiOrphanedSize').text(m.formatted_orphaned_size || '0 B');
		$('#kpiBrokenCount').text(m.broken_count || 0);
		$('#kpiBrokenSize').text(m.formatted_broken_size || '0 B');

		var savings = parseFloat(m.estimated_monthly_savings || 0).toFixed(2);
		$('#kpiSavings').text('$' + savings);

		// Update tab badges
		$('#tabBadgeAll').text(m.total_folders || 0);
		$('#tabBadgeConnected').text(m.connected_count || 0);
		$('#tabBadgeOrphaned').text(m.orphaned_count || 0);
		$('#tabBadgeBroken').text(m.broken_count || 0);
	}

	function formatBytes(bytes) {
		if (!bytes || bytes === 0) return '0 B';
		var k = 1024;
		var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
		var i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
	}

	function recalculateLocalMetrics() {
		var totalFolders = state.allFolders.length;
		var totalBytes = 0;
		var connectedCount = 0;
		var connectedBytes = 0;
		var orphanedCount = 0;
		var orphanedBytes = 0;
		var brokenCount = 0;
		var brokenBytes = 0;

		$.each(state.allFolders, function (i, f) {
			var size = f.total_size || 0;
			totalBytes += size;
			if (f.status === 'connected') {
				connectedCount++;
				connectedBytes += size;
			} else if (f.status === 'orphaned') {
				orphanedCount++;
				orphanedBytes += size;
			} else if (f.status === 'broken') {
				brokenCount++;
				brokenBytes += size;
			}
		});

		var orphanedGb = orphanedBytes / (1024 * 1024 * 1024);
		var savings = (orphanedGb * 0.015).toFixed(2);

		updateMetricsUI({
			total_folders: totalFolders,
			formatted_total_size: formatBytes(totalBytes),
			connected_count: connectedCount,
			formatted_connected_size: formatBytes(connectedBytes),
			orphaned_count: orphanedCount,
			formatted_orphaned_size: formatBytes(orphanedBytes),
			broken_count: brokenCount,
			formatted_broken_size: formatBytes(brokenBytes),
			estimated_monthly_savings: savings,
		});
	}

	/**
	 * 4. Table Filters, Search & Pagination
	 */
	function initTableFilters() {
		$('#statusFilterTabs').on('click', '.idta-r2-tab', function () {
			var $tab = $(this);
			$('#statusFilterTabs .idta-r2-tab').removeClass('is-active');
			$tab.addClass('is-active');
			state.activeFilter = $tab.data('filter') || 'all';
			state.currentPage = 1;
			applyFilters();
		});

		var searchTimer;
		$('#idtaR2SearchInput').on('input', function () {
			clearTimeout(searchTimer);
			var query = $(this).val().toLowerCase().trim();
			searchTimer = setTimeout(function () {
				state.searchQuery = query;
				state.currentPage = 1;
				applyFilters();
			}, 250);
		});

		$('#cbSelectAllFolders').on('change', function () {
			var checked = $(this).is(':checked');
			$('#idtaR2TableBody .folder-checkbox').prop('checked', checked);
		});

		$('#paginationControls').on('click', '.pagination-btn', function () {
			var page = $(this).data('page');
			if (page && page !== state.currentPage) {
				state.currentPage = page;
				renderTable();
			}
		});
	}

	function applyFilters() {
		var filtered = state.allFolders.filter(function (folder) {
			if (state.activeFilter !== 'all' && folder.status !== state.activeFilter) {
				return false;
			}

			if (state.searchQuery) {
				var q = state.searchQuery;
				var pathMatch = (folder.path || '').toLowerCase().indexOf(q) !== -1;
				var orderIdMatch = folder.order && String(folder.order.order_id).indexOf(q) !== -1;
				var customerMatch = folder.order && (folder.order.customer_name || '').toLowerCase().indexOf(q) !== -1;

				if (!pathMatch && !orderIdMatch && !customerMatch) {
					return false;
				}
			}

			return true;
		});

		state.filteredFolders = filtered;
		renderTable();
	}

	function renderTable() {
		var $tbody = $('#idtaR2TableBody');
		$tbody.empty();

		if (!state.filteredFolders.length) {
			$tbody.html(
				'<tr class="idta-r2-empty-row"><td colspan="8" class="text-center">' +
				'<div class="idta-r2-empty-state"><span class="dashicons dashicons-search"></span>' +
				'<p>' + idtaR2Data.i18n.noFolders + '</p></div></td></tr>'
			);
			$('#tablePagination').attr('hidden', true);
			return;
		}

		var totalItems = state.filteredFolders.length;
		var totalPages = Math.ceil(totalItems / state.perPage);
		if (state.currentPage > totalPages) state.currentPage = totalPages;

		var startIndex = (state.currentPage - 1) * state.perPage;
		var endIndex = Math.min(startIndex + state.perPage, totalItems);
		var pageItems = state.filteredFolders.slice(startIndex, endIndex);

		$.each(pageItems, function (idx, folder) {
			var statusClass = 'status-' + folder.status;
			var statusBadge = '<span class="idta-r2-status-pill ' + statusClass + '">' +
				'<span class="status-indicator indicator-' + folder.status + '"></span>' +
				escapeHtml(folder.status_label) + '</span>';

			var orderHtml = '<span class="text-muted">— None —</span>';
			if (folder.order) {
				orderHtml = '<a href="' + escapeHtml(folder.order.admin_url) + '" target="_blank" rel="noopener">' +
					'<strong>#' + escapeHtml(String(folder.order.order_id)) + '</strong> — ' +
					escapeHtml(folder.order.customer_name) + '</a>';
			}

			var rowHtml = '<tr>' +
				'<td class="column-cb"><input type="checkbox" class="folder-checkbox" value="' + escapeHtml(folder.prefix) + '"></td>' +
				'<td class="column-status">' + statusBadge + '</td>' +
				'<td class="column-path"><code class="idta-r2-folder-path">' + escapeHtml(folder.prefix) + '</code></td>' +
				'<td class="column-order">' + orderHtml + '</td>' +
				'<td class="column-files text-center"><span class="badge">' + (folder.file_count || 0) + '</span></td>' +
				'<td class="column-size">' + escapeHtml(folder.formatted_size || '0 B') + '</td>' +
				'<td class="column-date">' + escapeHtml(folder.formatted_last_modified || '—') + '</td>' +
				'<td class="column-actions">' +
					'<div class="idta-r2-row-actions">' +
						'<button type="button" class="button button-small btn-inspect" data-prefix="' + escapeHtml(folder.prefix) + '" data-bucket="' + escapeHtml(folder.bucket) + '" title="Inspect Files">' +
							'<span class="dashicons dashicons-visibility"></span>' +
						'</button>' +
						'<button type="button" class="button button-small btn-remap" data-prefix="' + escapeHtml(folder.prefix) + '" title="Link Order">' +
							'<span class="dashicons dashicons-admin-links"></span>' +
						'</button>' +
						'<button type="button" class="button button-small btn-delete" data-prefix="' + escapeHtml(folder.prefix) + '" data-bucket="' + escapeHtml(folder.bucket) + '" data-status="' + escapeHtml(folder.status) + '" title="Delete Folder">' +
							'<span class="dashicons dashicons-trash"></span>' +
						'</button>' +
					'</div>' +
				'</td>' +
			'</tr>';

			$tbody.append(rowHtml);
		});

		renderPagination(totalItems, totalPages, startIndex + 1, endIndex);
	}

	function renderPagination(totalItems, totalPages, start, end) {
		var $pagination = $('#tablePagination');
		if (totalPages <= 1) {
			$pagination.attr('hidden', true);
			return;
		}

		$pagination.removeAttr('hidden');
		$('#paginationInfo').text('Showing ' + start + '–' + end + ' of ' + totalItems + ' folders');

		var $controls = $('#paginationControls');
		$controls.empty();

		if (state.currentPage > 1) {
			$controls.append('<button type="button" class="button button-small pagination-btn" data-page="' + (state.currentPage - 1) + '">&laquo; Prev</button> ');
		}

		for (var p = 1; p <= totalPages; p++) {
			if (p === 1 || p === totalPages || (p >= state.currentPage - 2 && p <= state.currentPage + 2)) {
				var activeClass = p === state.currentPage ? 'button-primary' : 'button-secondary';
				$controls.append('<button type="button" class="button button-small pagination-btn ' + activeClass + '" data-page="' + p + '">' + p + '</button> ');
			} else if (p === state.currentPage - 3 || p === state.currentPage + 3) {
				$controls.append('<span class="pagination-ellipsis">&hellip;</span> ');
			}
		}

		if (state.currentPage < totalPages) {
			$controls.append('<button type="button" class="button button-small pagination-btn" data-page="' + (state.currentPage + 1) + '">Next &raquo;</button>');
		}
	}

	/**
	 * 5. Folder Inspection Drawer/Modal Controller
	 */
	function initInspectorModal() {
		var $modal = $('#idtaR2InspectModal');
		if (!$modal.length) return;

		$('#idtaR2TableBody').on('click', '.btn-inspect', function () {
			var prefix = $(this).attr('data-prefix') || $(this).data('prefix');
			var bucket = $(this).attr('data-bucket') || $(this).data('bucket') || $('#idtaR2SelectBucket').val();
			openInspector(bucket, prefix);
		});

		$('#inspectModalClose, #inspectModalCloseBtn').on('click', function () {
			closeInspector();
		});

		$modal.on('click', function (e) {
			if ($(e.target).is($modal)) {
				closeInspector();
			}
		});

		$(document).on('keydown', function (e) {
			if (e.key === 'Escape') {
				if ($modal.hasClass('is-open')) closeInspector();
				if ($('#idtaR2RemapModal').hasClass('is-open')) closeRemapModal();
				if ($('#idtaR2DryRunModal').hasClass('is-open')) closeDryRunModal();
				if ($('#idtaR2AuditLogModal').hasClass('is-open')) closeAuditLogModal();
			}
		});
	}

	function openInspector(bucket, prefix) {
		var $modal = $('#idtaR2InspectModal');
		var $loader = $('#inspectLoader');
		var $content = $('#inspectContent');
		var $orderCard = $('#inspectOrderCard');

		// Explicitly reset all modal elements to ensure no state leaks across items
		$('#inspectFolderSubtitle').text(bucket + ' / ' + prefix);
		$('#inspectOrderTitle').empty();
		$('#inspectOrderStatus').empty().removeAttr('class');
		$('#inspectOrderMeta').empty();
		$('#inspectOrderLink').attr('href', '#');
		$orderCard.attr('hidden', true).css('display', 'none').hide();
		$('#inspectPreviewGrid').empty();
		$('#inspectFilesTbody').empty();

		$loader.show();
		$content.attr('hidden', true).css('display', 'none').hide();
		$modal.addClass('is-open');

		$.post(idtaR2Data.ajaxUrl, {
			action: 'idta_r2_inspect_folder',
			nonce: idtaR2Data.nonces.inspect,
			bucket: bucket,
			prefix: prefix,
		})
		.done(function (res) {
			if (res.success && res.data) {
				populateInspector(res.data);
				$loader.hide();
				$content.removeAttr('hidden').css('display', 'block').show();
			} else {
				alert(idtaR2Data.i18n.inspectError);
				closeInspector();
			}
		})
		.fail(function () {
			alert(idtaR2Data.i18n.inspectError);
			closeInspector();
		});
	}

	function populateInspector(data) {
		var folder = data.folder || {};
		var previews = data.previews || {};

		var $orderCard = $('#inspectOrderCard');
		if (folder.order && folder.order.order_id) {
			$('#inspectOrderTitle').text('Order #' + folder.order.order_id + ' — ' + (folder.order.customer_name || 'Guest'));
			$('#inspectOrderStatus')
				.attr('class', 'idta-r2-status-pill status-' + (folder.status || 'connected'))
				.text(folder.order.status_label || folder.order.status || 'Connected');
			$('#inspectOrderMeta').html(
				'<strong>Email:</strong> ' + escapeHtml(folder.order.billing_email || '—') +
				' &bull; <strong>Source:</strong> <code>' + escapeHtml(folder.order.order_from || 'idta') + '</code>' +
				' &bull; <strong>Created:</strong> ' + escapeHtml(folder.order.formatted_date || '—')
			);
			$('#inspectOrderLink').attr('href', folder.order.admin_url || '#');
			$orderCard.removeAttr('hidden').css('display', 'flex').show();
		} else {
			$('#inspectOrderTitle').empty();
			$('#inspectOrderStatus').empty().removeAttr('class');
			$('#inspectOrderMeta').empty();
			$('#inspectOrderLink').attr('href', '#');
			$orderCard.attr('hidden', true).css('display', 'none').hide();
		}

		var $grid = $('#inspectPreviewGrid');
		$grid.empty();

		var slotLabels = {
			passport_photo: 'Passport Photo',
			portrait: 'Passport Photo',
			license_front: 'Licence Front',
			license_back: 'Licence Back',
			signature: 'Signature',
		};

		var seenFiles = {};
		$.each(previews, function (slot, p) {
			var fn = (p.filename || slot).toLowerCase();
			if (seenFiles[fn]) {
				return;
			}
			seenFiles[fn] = true;

			var label = slotLabels[slot] || slot;
			var cardHtml = '<div class="idta-r2-preview-card">' +
				'<div class="idta-r2-preview-img-wrap">';

			if (p.is_present && p.preview_url) {
				cardHtml += '<a href="' + escapeHtml(p.preview_url) + '" target="_blank" rel="noopener">' +
					'<img src="' + escapeHtml(p.preview_url) + '" alt="' + escapeHtml(label) + '">' +
					'</a>';
			} else {
				cardHtml += '<div class="idta-r2-preview-missing">' +
					'<span class="dashicons dashicons-warning"></span>' +
					'<span>' + idtaR2Data.i18n.missingFile + '</span>' +
					'</div>';
			}

			cardHtml += '</div>' +
				'<div class="idta-r2-preview-meta">' +
					'<span class="idta-r2-preview-name">' + escapeHtml(label) + '</span>';

			if (p.is_present && p.preview_url) {
				cardHtml += '<a href="' + escapeHtml(p.preview_url) + '" class="button button-small" target="_blank" download>' +
					'<span class="dashicons dashicons-download"></span>' +
					'</a>';
			}

			cardHtml += '</div></div>';
			$grid.append(cardHtml);
		});

		var $tbody = $('#inspectFilesTbody');
		$tbody.empty();

		if (folder.files && folder.files.length) {
			$.each(folder.files, function (idx, file) {
				var filename = file.key.split('/').pop();
				var sizeMb = (file.size / 1024).toFixed(1) + ' KB';
				var tr = '<tr>' +
					'<td><strong><code>' + escapeHtml(filename) + '</code></strong></td>' +
					'<td>' + sizeMb + '</td>' +
					'<td><code>' + escapeHtml(file.etag || '—') + '</code></td>' +
					'<td>' + escapeHtml(file.last_modified || '—') + '</td>' +
					'<td class="column-actions">' +
						'<code>' + escapeHtml(file.key) + '</code>' +
					'</td>' +
				'</tr>';
				$tbody.append(tr);
			});
		} else {
			$tbody.html('<tr><td colspan="5" class="text-center text-muted">No files detected in folder.</td></tr>');
		}
	}

	function closeInspector() {
		$('#idtaR2InspectModal').removeClass('is-open');
		$('#inspectOrderTitle').empty();
		$('#inspectOrderStatus').empty().removeAttr('class');
		$('#inspectOrderMeta').empty();
		$('#inspectOrderLink').attr('href', '#');
		$('#inspectOrderCard').attr('hidden', true).css('display', 'none').hide();
		$('#inspectPreviewGrid').empty();
		$('#inspectFilesTbody').empty();
	}

	/**
	 * 6. Single Row Actions: Delete & Remap
	 */
	function initRowActions() {
		// Single folder deletion
		$('#idtaR2TableBody').on('click', '.btn-delete', function () {
			var prefix = $(this).attr('data-prefix') || $(this).data('prefix');
			var bucket = $(this).attr('data-bucket') || $(this).data('bucket') || $('#idtaR2SelectBucket').val();
			var status = $(this).attr('data-status') || $(this).data('status');
			var force = false;

			if (status === 'connected') {
				if (!window.confirm(idtaR2Data.i18n.confirmConnectedDelete)) {
					return;
				}
				force = true;
			} else {
				if (!window.confirm(idtaR2Data.i18n.confirmDelete)) {
					return;
				}
			}

			var $btn = $(this);
			$btn.prop('disabled', true);

			$.post(idtaR2Data.ajaxUrl, {
				action: 'idta_r2_delete_folder',
				nonce: idtaR2Data.nonces.delete,
				bucket: bucket,
				prefix: prefix,
				force: force ? 1 : 0,
			})
			.done(function (res) {
				if (res.success) {
					var cleanPrefix = (prefix || '').replace(/\/$/, '');
					state.allFolders = state.allFolders.filter(function (f) {
						var fClean = (f.prefix || f.path || '').replace(/\/$/, '');
						return fClean !== cleanPrefix;
					});
					recalculateLocalMetrics();
					applyFilters();
					window.alert(idtaR2Data.i18n.deleteSuccess);
				} else {
					window.alert((res.data && res.data.message) ? res.data.message : 'Deletion failed');
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
					xhr.responseJSON.data.message : 'Server communication failure during deletion';
				window.alert(msg);
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
		});

		// Open remap modal from row
		$('#idtaR2TableBody').on('click', '.btn-remap', function () {
			var prefix = $(this).attr('data-prefix') || $(this).data('prefix');
			openRemapModal(prefix);
		});
	}

	/**
	 * 7. Remap Order Modal Controller
	 */
	function openRemapModal(prefix) {
		var $modal = $('#idtaR2RemapModal');
		$('#remapTargetFolder').val(prefix);
		$('#remapOrderId').val('').prop('disabled', false);
		$('#remapFeedback').attr('hidden', true).empty();
		$('#remapModalSubmit').prop('disabled', false).find('#remapSubmitLabel').text('Confirm Link');
		$modal.addClass('is-open');
		$('#remapOrderId').focus();
	}

	function closeRemapModal() {
		$('#idtaR2RemapModal').removeClass('is-open');
	}

	function initRemapModal() {
		var $modal = $('#idtaR2RemapModal');
		if (!$modal.length) return;

		$('#remapModalClose, #remapModalCancel').on('click', function () {
			closeRemapModal();
		});

		$modal.on('click', function (e) {
			if ($(e.target).is($modal)) {
				closeRemapModal();
			}
		});

		$('#remapModalSubmit').on('click', function () {
			var prefix = $('#remapTargetFolder').val();
			var orderId = parseInt($('#remapOrderId').val(), 10);
			var $btn = $(this);
			var $feedback = $('#remapFeedback');

			if (!orderId || orderId <= 0) {
				$feedback.attr('class', 'idta-r2-alert alert-danger mt-15').text('Please enter a valid numeric Order ID.').removeAttr('hidden');
				return;
			}

			$btn.prop('disabled', true).find('#remapSubmitLabel').text(idtaR2Data.i18n.remapping);
			$feedback.attr('hidden', true);

			$.post(idtaR2Data.ajaxUrl, {
				action: 'idta_r2_remap_folder',
				nonce: idtaR2Data.nonces.remap,
				prefix: prefix,
				order_id: orderId,
			})
			.done(function (res) {
				if (res.success && res.data) {
					$feedback.attr('class', 'idta-r2-alert alert-success mt-15').text(idtaR2Data.i18n.remapSuccess).removeAttr('hidden');

					var cleanPrefix = (prefix || '').replace(/\/$/, '');
					$.each(state.allFolders, function (i, f) {
						var fClean = (f.prefix || f.path || '').replace(/\/$/, '');
						if (fClean === cleanPrefix) {
							f.status = 'connected';
							f.status_label = 'Connected';
							f.order = {
								order_id: res.data.order_id,
								customer_name: res.data.customer_name,
								admin_url: res.data.admin_url || ('post.php?post=' + res.data.order_id + '&action=edit'),
							};
						}
					});

					recalculateLocalMetrics();
					applyFilters();

					setTimeout(function () {
						closeRemapModal();
					}, 1200);
				} else {
					var msg = (res.data && res.data.message) ? res.data.message : 'Remapping failed';
					$feedback.attr('class', 'idta-r2-alert alert-danger mt-15').text(msg).removeAttr('hidden');
					$btn.prop('disabled', false).find('#remapSubmitLabel').text('Confirm Link');
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
					xhr.responseJSON.data.message : 'Server error occurred during remapping';
				$feedback.attr('class', 'idta-r2-alert alert-danger mt-15').text(msg).removeAttr('hidden');
				$btn.prop('disabled', false).find('#remapSubmitLabel').text('Confirm Link');
			});
		});
	}

	/**
	 * 8. Bulk Actions Controller
	 */
	function initBulkActions() {
		$('#idtaR2BtnApplyBulk').on('click', function () {
			var action = $('#idtaR2BulkAction').val();
			if (!action) return;

			var selectedPrefixes = [];
			$('#idtaR2TableBody .folder-checkbox:checked').each(function () {
				selectedPrefixes.push($(this).val());
			});

			if (!selectedPrefixes.length) {
				window.alert('Please select at least one folder checkbox in the table.');
				return;
			}

			var bucket = $('#idtaR2SelectBucket').val();

			if (action === 'dry_run') {
				executeDryRun(bucket, selectedPrefixes);
			} else if (action === 'delete') {
				if (window.confirm(idtaR2Data.i18n.confirmBulkDelete)) {
					executeBulkDelete(bucket, selectedPrefixes, false);
				}
			}
		});

		$('#idtaR2BtnCleanOrphaned').on('click', function () {
			var orphanedPrefixes = [];
			$.each(state.allFolders, function (i, f) {
				if (f.status === 'orphaned') {
					orphanedPrefixes.push(f.prefix);
				}
			});

			if (!orphanedPrefixes.length) {
				window.alert('No orphaned folders discovered in the current scan.');
				return;
			}

			var bucket = $('#idtaR2SelectBucket').val();
			executeDryRun(bucket, orphanedPrefixes);
		});
	}

	/**
	 * 9. Dry Run Preview Modal Controller
	 */
	function executeDryRun(bucket, prefixes) {
		var $btn = $('#idtaR2BtnApplyBulk');
		$btn.prop('disabled', true);

		$.post(idtaR2Data.ajaxUrl, {
			action: 'idta_r2_bulk_delete',
			nonce: idtaR2Data.nonces.delete,
			bucket: bucket,
			prefixes: prefixes,
			dry_run: 1,
		})
		.done(function (res) {
			if (res.success && res.data) {
				openDryRunModal(bucket, res.data);
			} else {
				window.alert((res.data && res.data.message) ? res.data.message : 'Dry run failed');
			}
		})
		.fail(function (xhr) {
			var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
				xhr.responseJSON.data.message : 'Server error occurred during dry run';
			window.alert(msg);
		})
		.always(function () {
			$btn.prop('disabled', false);
		});
	}

	function openDryRunModal(bucket, dryRunData) {
		state.dryRunBucket = bucket;
		state.dryRunCandidates = dryRunData.folders || [];

		$('#dryRunFolderCount').text(dryRunData.folders_count || 0);
		$('#dryRunFilesCount').text(dryRunData.files_count || 0);
		$('#dryRunBytesReclaimed').text(formatBytes(dryRunData.bytes_reclaimed || 0));

		var $list = $('#dryRunFolderList');
		$list.empty();
		$.each(dryRunData.folders || [], function (i, p) {
			$list.append('<li>' + escapeHtml(p) + '</li>');
		});

		$('#dryRunConfirmCheckbox').prop('checked', false);
		$('#dryRunModalExecute').prop('disabled', true).find('#dryRunExecuteLabel').text('Permanently Delete Orphaned Folders');
		$('#dryRunFeedback').attr('hidden', true).empty();

		$('#idtaR2DryRunModal').addClass('is-open');
	}

	function closeDryRunModal() {
		$('#idtaR2DryRunModal').removeClass('is-open');
	}

	function initDryRunModal() {
		var $modal = $('#idtaR2DryRunModal');
		if (!$modal.length) return;

		$('#dryRunModalClose, #dryRunModalCancel').on('click', function () {
			closeDryRunModal();
		});

		$modal.on('click', function (e) {
			if ($(e.target).is($modal)) {
				closeDryRunModal();
			}
		});

		$('#dryRunConfirmCheckbox').on('change', function () {
			var checked = $(this).is(':checked');
			$('#dryRunModalExecute').prop('disabled', !checked);
		});

		$('#dryRunModalExecute').on('click', function () {
			if (!state.dryRunCandidates.length) return;
			var bucket = state.dryRunBucket || $('#idtaR2SelectBucket').val();

			var $btn = $(this);
			$btn.prop('disabled', true).find('#dryRunExecuteLabel').text(idtaR2Data.i18n.deleting);

			executeBulkDelete(bucket, state.dryRunCandidates, true);
		});
	}

	function executeBulkDelete(bucket, prefixes, fromModal) {
		$.post(idtaR2Data.ajaxUrl, {
			action: 'idta_r2_bulk_delete',
			nonce: idtaR2Data.nonces.delete,
			bucket: bucket,
			prefixes: prefixes,
			dry_run: 0,
		})
		.done(function (res) {
			if (res.success && res.data) {
				var deletedPrefixes = res.data.folders || prefixes;
				state.allFolders = state.allFolders.filter(function (f) {
					return deletedPrefixes.indexOf(f.prefix) === -1;
				});

				recalculateLocalMetrics();
				applyFilters();

				if (fromModal) {
					$('#dryRunFeedback')
						.attr('class', 'idta-r2-alert alert-success mt-15')
						.text(deletedPrefixes.length + ' orphaned folders permanently deleted.')
						.removeAttr('hidden');
					setTimeout(function () {
						closeDryRunModal();
					}, 1500);
				} else {
					window.alert(deletedPrefixes.length + ' orphaned folders permanently deleted.');
				}
			} else {
				var msg = (res.data && res.data.message) ? res.data.message : 'Bulk deletion failed';
				if (fromModal) {
					$('#dryRunFeedback').attr('class', 'idta-r2-alert alert-danger mt-15').text(msg).removeAttr('hidden');
					$('#dryRunModalExecute').prop('disabled', false).find('#dryRunExecuteLabel').text('Permanently Delete Orphaned Folders');
				} else {
					window.alert(msg);
				}
			}
		})
		.fail(function (xhr) {
			var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ?
				xhr.responseJSON.data.message : 'Server error occurred during deletion';
			if (fromModal) {
				$('#dryRunFeedback').attr('class', 'idta-r2-alert alert-danger mt-15').text(msg).removeAttr('hidden');
				$('#dryRunModalExecute').prop('disabled', false).find('#dryRunExecuteLabel').text('Permanently Delete Orphaned Folders');
			} else {
				window.alert(msg);
			}
		});
	}

	/**
	 * 10. Audit Trail Modal Controller
	 */
	function initAuditLogModal() {
		var $modal = $('#idtaR2AuditLogModal');
		if (!$modal.length) return;

		$('#idtaR2BtnAuditLog').on('click', function () {
			openAuditLogModal();
		});

		$('#auditLogModalClose, #auditLogModalCloseFooter').on('click', function () {
			closeAuditLogModal();
		});

		$modal.on('click', function (e) {
			if ($(e.target).is($modal)) {
				closeAuditLogModal();
			}
		});

		$('#auditFilterAction').on('change', function () {
			loadAuditLogs($(this).val());
		});

		$('#auditRefreshBtn').on('click', function () {
			loadAuditLogs($('#auditFilterAction').val());
		});
	}

	function openAuditLogModal() {
		$('#idtaR2AuditLogModal').addClass('is-open');
		loadAuditLogs($('#auditFilterAction').val());
	}

	function closeAuditLogModal() {
		$('#idtaR2AuditLogModal').removeClass('is-open');
	}

	function loadAuditLogs(actionType) {
		var $tbody = $('#auditLogsTbody');
		var $total = $('#auditTotalLabel');

		$tbody.html('<tr><td colspan="6" class="text-center text-muted"><span class="spinner is-active inline-spinner"></span> Loading audit log history...</td></tr>');
		$total.text('Fetching records...');

		$.post(idtaR2Data.ajaxUrl, {
			action: 'idta_r2_get_logs',
			nonce: idtaR2Data.nonces.logs,
			action_type: actionType || '',
			limit: 100,
			offset: 0,
		})
		.done(function (res) {
			if (res.success && res.data) {
				var logs = res.data.logs || [];
				var total = res.data.total || 0;
				$total.text(total + ' operations recorded');

				$tbody.empty();
				if (!logs.length) {
					$tbody.html('<tr><td colspan="6" class="text-center text-muted">No audit trail records found.</td></tr>');
					return;
				}

				$.each(logs, function (i, log) {
					var actionClass = 'status-orphaned';
					if (log.action_type === 'REMAP_ORDER') actionClass = 'status-connected';
					if (log.action_type === 'BATCH_CLEANUP') actionClass = 'status-broken';

					var orderCol = '—';
					if (log.order_id) {
						orderCol = '<a href="post.php?post=' + log.order_id + '&action=edit" target="_blank">#' + log.order_id + '</a>';
					}

					var tr = '<tr>' +
						'<td><small>' + escapeHtml(log.timestamp) + '</small></td>' +
						'<td><strong>' + escapeHtml(log.user_display_name || 'System') + '</strong></td>' +
						'<td><span class="idta-r2-status-pill ' + actionClass + '">' + escapeHtml(log.action_type) + '</span></td>' +
						'<td><code>' + escapeHtml(log.folder_path) + '</code></td>' +
						'<td>' + orderCol + '</td>' +
						'<td><span class="badge">' + escapeHtml(log.status) + '</span></td>' +
					'</tr>';
					$tbody.append(tr);
				});
			} else {
				$tbody.html('<tr><td colspan="6" class="text-center text-red">Failed to load audit logs.</td></tr>');
			}
		})
		.fail(function () {
			$tbody.html('<tr><td colspan="6" class="text-center text-red">Server communication failure while loading audit logs.</td></tr>');
		});
	}

	/**
	 * 11. Load Initial State from Server Cache
	 */
	function loadInitialState() {
		var totalOnPage = parseInt($('#kpiTotalFolders').text(), 10);
		if (totalOnPage > 0) {
			$.post(idtaR2Data.ajaxUrl, {
				action: 'idta_r2_get_scan_progress',
				nonce: idtaR2Data.nonces.scan,
			}).done(function (res) {
				if (res.success && res.data && res.data.folders) {
					state.allFolders = Object.values(res.data.folders);
					applyFilters();
					$('#idtaR2BtnResetScan').removeAttr('hidden');
				}
			});
		}
	}

	function escapeHtml(string) {
		var str = String(string || '');
		return str.replace(/[&<>"']/g, function (m) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;',
			}[m];
		});
	}

})(jQuery);
