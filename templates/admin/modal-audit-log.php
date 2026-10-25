<?php
/**
 * Admin Audit Trail Modal Dialog.
 *
 * Displays historical records of all deletion, remapping, and configuration actions,
 * with real-time filtering and direct CSV export functionality.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;
?>
<div id="idtaR2AuditLogModal" class="idta-r2-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
	<div class="idta-r2-modal-dialog idta-r2-dialog-lg">
		<div class="idta-r2-modal-header">
			<div class="idta-r2-modal-title-wrap">
				<h3 class="idta-r2-modal-title"><?php esc_html_e( 'Operations Audit Trail & Safety Logs', 'idta-r2-optimizer' ); ?></h3>
				<span class="idta-r2-modal-badge"><?php esc_html_e( 'wp_idta_r2_logs', 'idta-r2-optimizer' ); ?></span>
			</div>
			<div class="idta-r2-modal-header-actions">
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=idta_r2_export_logs_csv' ), Plugin::NONCE_ACTION_PREFIX . 'export_logs', 'nonce' ) ); ?>"
					class="button button-secondary" id="auditLogExportBtn" download>
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export CSV', 'idta-r2-optimizer' ); ?>
				</a>
				<button type="button" class="idta-r2-modal-close" id="auditLogModalClose" aria-label="<?php esc_attr_e( 'Close', 'idta-r2-optimizer' ); ?>">
					&times;
				</button>
			</div>
		</div>

		<div class="idta-r2-modal-body">
			<!-- Audit Trail Toolbar -->
			<div class="idta-r2-audit-toolbar">
				<div class="idta-r2-audit-filters">
					<label for="auditFilterAction" class="screen-reader-text"><?php esc_html_e( 'Filter by Action', 'idta-r2-optimizer' ); ?></label>
					<select id="auditFilterAction" class="idta-r2-select">
						<option value=""><?php esc_html_e( 'All Actions', 'idta-r2-optimizer' ); ?></option>
						<option value="DELETE_ORPHAN"><?php esc_html_e( 'Delete Orphan', 'idta-r2-optimizer' ); ?></option>
						<option value="FORCE_DELETE"><?php esc_html_e( 'Force Delete', 'idta-r2-optimizer' ); ?></option>
						<option value="BATCH_CLEANUP"><?php esc_html_e( 'Batch Cleanup', 'idta-r2-optimizer' ); ?></option>
						<option value="REMAP_ORDER"><?php esc_html_e( 'Remap Order', 'idta-r2-optimizer' ); ?></option>
					</select>
					<button type="button" class="button button-secondary" id="auditRefreshBtn">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh', 'idta-r2-optimizer' ); ?>
					</button>
				</div>
				<div class="idta-r2-audit-total" id="auditTotalLabel">
					<?php esc_html_e( 'Loading records...', 'idta-r2-optimizer' ); ?>
				</div>
			</div>

			<!-- Logs Table -->
			<div class="idta-r2-table-responsive mt-15 idta-r2-audit-table-wrap">
				<table class="wp-list-table widefat fixed striped idta-r2-audit-table">
					<thead>
						<tr>
							<th scope="col" style="width: 140px;"><?php esc_html_e( 'Timestamp (UTC)', 'idta-r2-optimizer' ); ?></th>
							<th scope="col" style="width: 110px;"><?php esc_html_e( 'User', 'idta-r2-optimizer' ); ?></th>
							<th scope="col" style="width: 130px;"><?php esc_html_e( 'Action', 'idta-r2-optimizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Target Path / Details', 'idta-r2-optimizer' ); ?></th>
							<th scope="col" style="width: 90px;"><?php esc_html_e( 'Order ID', 'idta-r2-optimizer' ); ?></th>
							<th scope="col" style="width: 80px;"><?php esc_html_e( 'Status', 'idta-r2-optimizer' ); ?></th>
						</tr>
					</thead>
					<tbody id="auditLogsTbody">
						<tr>
							<td colspan="6" class="text-center text-muted">
								<span class="spinner is-active inline-spinner"></span>
								<?php esc_html_e( 'Loading audit log history...', 'idta-r2-optimizer' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<div class="idta-r2-modal-footer">
			<button type="button" class="button button-secondary" id="auditLogModalCloseFooter">
				<?php esc_html_e( 'Close', 'idta-r2-optimizer' ); ?>
			</button>
		</div>
	</div>
</div>

