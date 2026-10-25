<?php
/**
 * Admin Explorer Dashboard template.
 *
 * @package IDTA\R2Optimizer
 *
 * @var string $active_bucket Currently active R2 bucket name.
 * @var array<string> $available_buckets List of known buckets.
 * @var array<string> $available_years List of available years for filter.
 * @var array<string, mixed> $scan_state Last saved scan progress state.
 * @var array<string, mixed> $metrics Calculated metrics array.
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;
?>
<div class="idta-r2-dashboard-wrap">

	<!-- Top Control Bar: Bucket & Date Range Selection -->
	<div class="idta-r2-control-bar">
		<div class="idta-r2-control-group">
			<label for="idtaR2SelectBucket" class="idta-r2-label"><?php esc_html_e( 'R2 Bucket:', 'idta-r2-optimizer' ); ?></label>
			<select id="idtaR2SelectBucket" class="idta-r2-select">
				<?php if ( empty( $available_buckets ) ) : ?>
					<option value="<?php echo esc_attr( $active_bucket ); ?>"><?php echo esc_html( $active_bucket ?: 'idta-html' ); ?></option>
				<?php else : ?>
					<?php foreach ( $available_buckets as $b_name ) : ?>
						<option value="<?php echo esc_attr( $b_name ); ?>" <?php selected( $b_name, $active_bucket ); ?>>
							<?php echo esc_html( $b_name ); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>

			<label for="idtaR2FilterYear" class="idta-r2-label ml-15"><?php esc_html_e( 'Year:', 'idta-r2-optimizer' ); ?></label>
			<select id="idtaR2FilterYear" class="idta-r2-select">
				<option value=""><?php esc_html_e( 'All Years', 'idta-r2-optimizer' ); ?></option>
				<?php foreach ( $available_years as $year ) : ?>
					<option value="<?php echo esc_attr( $year ); ?>"><?php echo esc_html( $year ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="idtaR2FilterMonth" class="idta-r2-label ml-10"><?php esc_html_e( 'Month:', 'idta-r2-optimizer' ); ?></label>
			<select id="idtaR2FilterMonth" class="idta-r2-select" disabled>
				<option value=""><?php esc_html_e( 'All Months', 'idta-r2-optimizer' ); ?></option>
				<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
					<?php $m_str = sprintf( '%02d', $m ); ?>
					<option value="<?php echo esc_attr( $m_str ); ?>"><?php echo esc_html( $m_str ); ?></option>
				<?php endfor; ?>
			</select>
		</div>

		<div class="idta-r2-control-actions">
			<button type="button" class="button button-primary" id="idtaR2BtnStartScan">
				<span class="dashicons dashicons-search"></span>
				<span id="btnScanLabel"><?php esc_html_e( 'Scan Bucket', 'idta-r2-optimizer' ); ?></span>
			</button>
			<button type="button" class="button button-secondary" id="idtaR2BtnResetScan" hidden>
				<?php esc_html_e( 'Reset Scan', 'idta-r2-optimizer' ); ?>
			</button>
			<button type="button" class="button button-secondary" id="idtaR2BtnAuditLog">
				<span class="dashicons dashicons-list-view"></span>
				<?php esc_html_e( 'Audit Trail', 'idta-r2-optimizer' ); ?>
			</button>
		</div>
	</div>

	<!-- Live Scan Progress Bar -->
	<div class="idta-r2-progress-wrap" id="scanProgressWrap" hidden>
		<div class="idta-r2-progress-header">
			<span class="idta-r2-progress-status" id="scanProgressStatus">
				<span class="spinner is-active"></span>
				<?php esc_html_e( 'Traversing Cloudflare R2 bucket...', 'idta-r2-optimizer' ); ?>
			</span>
			<span class="idta-r2-progress-count" id="scanProgressCount">0 folders discovered</span>
		</div>
		<div class="idta-r2-progress-track">
			<div class="idta-r2-progress-bar" id="scanProgressBar" style="width: 0%;"></div>
		</div>
	</div>

	<!-- Summary KPI Metric Cards -->
	<div class="idta-r2-metrics-grid" id="metricsGrid">
		<!-- Total Scanned -->
		<div class="idta-r2-metric-card">
			<div class="idta-r2-metric-icon icon-total">
				<span class="dashicons dashicons-category"></span>
			</div>
			<div class="idta-r2-metric-data">
				<span class="idta-r2-metric-value" id="kpiTotalFolders"><?php echo esc_html( (string) ( $metrics['total_folders'] ?? 0 ) ); ?></span>
				<span class="idta-r2-metric-label"><?php esc_html_e( 'Total Folders', 'idta-r2-optimizer' ); ?></span>
			</div>
			<div class="idta-r2-metric-sub" id="kpiTotalSize">
				<?php echo esc_html( (string) ( $metrics['formatted_total_size'] ?? '0 B' ) ); ?>
			</div>
		</div>

		<!-- Connected (Green) -->
		<div class="idta-r2-metric-card card-connected">
			<div class="idta-r2-metric-icon icon-connected">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<div class="idta-r2-metric-data">
				<span class="idta-r2-metric-value text-green" id="kpiConnectedCount"><?php echo esc_html( (string) ( $metrics['connected_count'] ?? 0 ) ); ?></span>
				<span class="idta-r2-metric-label"><?php esc_html_e( 'Connected Orders', 'idta-r2-optimizer' ); ?></span>
			</div>
			<div class="idta-r2-metric-sub" id="kpiConnectedSize">
				<?php echo esc_html( (string) ( $metrics['formatted_connected_size'] ?? '0 B' ) ); ?>
			</div>
		</div>

		<!-- Orphaned (Red) -->
		<div class="idta-r2-metric-card card-orphaned">
			<div class="idta-r2-metric-icon icon-orphaned">
				<span class="dashicons dashicons-warning"></span>
			</div>
			<div class="idta-r2-metric-data">
				<span class="idta-r2-metric-value text-red" id="kpiOrphanedCount"><?php echo esc_html( (string) ( $metrics['orphaned_count'] ?? 0 ) ); ?></span>
				<span class="idta-r2-metric-label"><?php esc_html_e( 'Orphaned Folders', 'idta-r2-optimizer' ); ?></span>
			</div>
			<div class="idta-r2-metric-sub" id="kpiOrphanedSize">
				<?php echo esc_html( (string) ( $metrics['formatted_orphaned_size'] ?? '0 B' ) ); ?>
			</div>
		</div>

		<!-- Broken / Incomplete (Amber) -->
		<div class="idta-r2-metric-card card-broken">
			<div class="idta-r2-metric-icon icon-broken">
				<span class="dashicons dashicons-flag"></span>
			</div>
			<div class="idta-r2-metric-data">
				<span class="idta-r2-metric-value text-amber" id="kpiBrokenCount"><?php echo esc_html( (string) ( $metrics['broken_count'] ?? 0 ) ); ?></span>
				<span class="idta-r2-metric-label"><?php esc_html_e( 'Incomplete Assets', 'idta-r2-optimizer' ); ?></span>
			</div>
			<div class="idta-r2-metric-sub" id="kpiBrokenSize">
				<?php echo esc_html( (string) ( $metrics['formatted_broken_size'] ?? '0 B' ) ); ?>
			</div>
		</div>

		<!-- Estimated Savings -->
		<div class="idta-r2-metric-card card-savings">
			<div class="idta-r2-metric-icon icon-savings">
				<span class="dashicons dashicons-money-alt"></span>
			</div>
			<div class="idta-r2-metric-data">
				<span class="idta-r2-metric-value text-blue" id="kpiSavings">
					$<?php echo esc_html( number_format( (float) ( $metrics['estimated_monthly_savings'] ?? 0 ), 2 ) ); ?>
				</span>
				<span class="idta-r2-metric-label"><?php esc_html_e( 'Reclaimable / Mo', 'idta-r2-optimizer' ); ?></span>
			</div>
			<div class="idta-r2-metric-sub">
				<?php esc_html_e( 'Based on R2 $0.015/GB', 'idta-r2-optimizer' ); ?>
			</div>
		</div>
	</div>

	<!-- Explorer Filter Toolbar & Table Container -->
	<div class="idta-r2-explorer-wrap mt-20">
		<div class="idta-r2-toolbar">
			<!-- Quick Status Filter Tabs -->
			<div class="idta-r2-status-tabs" id="statusFilterTabs">
				<button type="button" class="idta-r2-tab is-active" data-filter="all">
					<?php esc_html_e( 'All', 'idta-r2-optimizer' ); ?>
					<span class="tab-badge" id="tabBadgeAll"><?php echo esc_html( (string) ( $metrics['total_folders'] ?? 0 ) ); ?></span>
				</button>
				<button type="button" class="idta-r2-tab" data-filter="connected">
					<span class="status-indicator indicator-connected"></span>
					<?php esc_html_e( 'Connected', 'idta-r2-optimizer' ); ?>
					<span class="tab-badge" id="tabBadgeConnected"><?php echo esc_html( (string) ( $metrics['connected_count'] ?? 0 ) ); ?></span>
				</button>
				<button type="button" class="idta-r2-tab" data-filter="orphaned">
					<span class="status-indicator indicator-orphaned"></span>
					<?php esc_html_e( 'Orphaned', 'idta-r2-optimizer' ); ?>
					<span class="tab-badge" id="tabBadgeOrphaned"><?php echo esc_html( (string) ( $metrics['orphaned_count'] ?? 0 ) ); ?></span>
				</button>
				<button type="button" class="idta-r2-tab" data-filter="broken">
					<span class="status-indicator indicator-broken"></span>
					<?php esc_html_e( 'Incomplete', 'idta-r2-optimizer' ); ?>
					<span class="tab-badge" id="tabBadgeBroken"><?php echo esc_html( (string) ( $metrics['broken_count'] ?? 0 ) ); ?></span>
				</button>
			</div>

			<!-- Search & Table Quick Actions -->
			<div class="idta-r2-search-box">
				<span class="dashicons dashicons-search search-icon"></span>
				<input type="search" id="idtaR2SearchInput" class="idta-r2-search-input"
					placeholder="<?php esc_attr_e( 'Search folder, order #, or customer...', 'idta-r2-optimizer' ); ?>" autocomplete="off">
			</div>
		</div>

		<!-- Bulk Actions Toolbar -->
		<div class="idta-r2-bulk-bar">
			<div class="idta-r2-bulk-group">
				<select id="idtaR2BulkAction" class="idta-r2-select">
					<option value=""><?php esc_html_e( 'Bulk Actions', 'idta-r2-optimizer' ); ?></option>
					<option value="dry_run"><?php esc_html_e( 'Dry Run Deletion (Preview)', 'idta-r2-optimizer' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete Selected Orphaned', 'idta-r2-optimizer' ); ?></option>
				</select>
				<button type="button" class="button button-secondary" id="idtaR2BtnApplyBulk">
					<?php esc_html_e( 'Apply', 'idta-r2-optimizer' ); ?>
				</button>
			</div>

			<div class="idta-r2-bulk-quick">
				<button type="button" class="button button-secondary text-red" id="idtaR2BtnCleanOrphaned">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Clean All Orphaned Folders...', 'idta-r2-optimizer' ); ?>
				</button>
			</div>
		</div>

		<!-- Explorer Table -->
		<div class="idta-r2-table-responsive">
			<table class="wp-list-table widefat fixed striped idta-r2-table" id="idtaR2FolderTable">
				<thead>
					<tr>
						<th scope="col" class="column-cb check-column">
							<input type="checkbox" id="cbSelectAllFolders">
						</th>
						<th scope="col" class="column-status" data-sort="status">
							<?php esc_html_e( 'Status', 'idta-r2-optimizer' ); ?>
						</th>
						<th scope="col" class="column-path" data-sort="path">
							<?php esc_html_e( 'R2 Folder Prefix', 'idta-r2-optimizer' ); ?>
						</th>
						<th scope="col" class="column-order" data-sort="order">
							<?php esc_html_e( 'Linked WooCommerce Order', 'idta-r2-optimizer' ); ?>
						</th>
						<th scope="col" class="column-files" data-sort="files">
							<?php esc_html_e( 'Files', 'idta-r2-optimizer' ); ?>
						</th>
						<th scope="col" class="column-size" data-sort="size">
							<?php esc_html_e( 'Total Size', 'idta-r2-optimizer' ); ?>
						</th>
						<th scope="col" class="column-date" data-sort="date">
							<?php esc_html_e( 'Last Modified', 'idta-r2-optimizer' ); ?>
						</th>
						<th scope="col" class="column-actions">
							<?php esc_html_e( 'Actions', 'idta-r2-optimizer' ); ?>
						</th>
					</tr>
				</thead>
				<tbody id="idtaR2TableBody">
					<tr class="idta-r2-empty-row">
						<td colspan="8" class="text-center">
							<div class="idta-r2-empty-state">
								<span class="dashicons dashicons-cloud-upload"></span>
								<p>
									<?php esc_html_e( 'No folder scans loaded yet. Click "Scan Bucket" above to discover asset folders.', 'idta-r2-optimizer' ); ?>
								</p>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Table Pagination Footer -->
		<div class="idta-r2-pagination-bar" id="tablePagination" hidden>
			<div class="idta-r2-page-info" id="paginationInfo"></div>
			<div class="idta-r2-page-controls" id="paginationControls"></div>
		</div>
	</div>
</div>

<?php
// Include Drawer & Dialog Modals.
require_once __DIR__ . '/modal-inspect.php';
require_once __DIR__ . '/modal-remap.php';
require_once __DIR__ . '/modal-dry-run.php';
require_once __DIR__ . '/modal-audit-log.php';

