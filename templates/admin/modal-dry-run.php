<?php
/**
 * Admin Dry-Run Preview Modal Dialog.
 *
 * Displays simulation results for bulk orphaned folder deletion,
 * calculating reclaimable storage and files count before final execution.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;
?>
<div id="idtaR2DryRunModal" class="idta-r2-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
	<div class="idta-r2-modal-dialog idta-r2-dialog-md">
		<div class="idta-r2-modal-header">
			<div class="idta-r2-modal-title-wrap">
				<h3 class="idta-r2-modal-title"><?php esc_html_e( 'Dry Run: Storage Cleanup Preview', 'idta-r2-optimizer' ); ?></h3>
				<span class="idta-r2-modal-badge idta-r2-badge-dryrun"><?php esc_html_e( 'Simulation Mode', 'idta-r2-optimizer' ); ?></span>
			</div>
			<button type="button" class="idta-r2-modal-close" id="dryRunModalClose" aria-label="<?php esc_attr_e( 'Close', 'idta-r2-optimizer' ); ?>">
				&times;
			</button>
		</div>

		<div class="idta-r2-modal-body">
			<div class="idta-r2-alert alert-info">
				<span class="dashicons dashicons-info"></span>
				<span>
					<?php esc_html_e( 'This is a simulation. No files or R2 objects have been removed. Review the reclaimable metrics below before proceeding.', 'idta-r2-optimizer' ); ?>
				</span>
			</div>

			<!-- Dry Run Metric Highlights -->
			<div class="idta-r2-dryrun-stats mt-15">
				<div class="idta-r2-dryrun-card">
					<span class="dryrun-val text-red" id="dryRunFolderCount">0</span>
					<span class="dryrun-lbl"><?php esc_html_e( 'Orphaned Folders', 'idta-r2-optimizer' ); ?></span>
				</div>
				<div class="idta-r2-dryrun-card">
					<span class="dryrun-val" id="dryRunFilesCount">0</span>
					<span class="dryrun-lbl"><?php esc_html_e( 'R2 Objects / Files', 'idta-r2-optimizer' ); ?></span>
				</div>
				<div class="idta-r2-dryrun-card">
					<span class="dryrun-val text-green" id="dryRunBytesReclaimed">0 MB</span>
					<span class="dryrun-lbl"><?php esc_html_e( 'Reclaimable Storage', 'idta-r2-optimizer' ); ?></span>
				</div>
			</div>

			<!-- Queued Folders Preview List -->
			<div class="idta-r2-dryrun-list-wrap mt-15">
				<label class="idta-r2-label">
					<?php esc_html_e( 'Candidate Folder Prefixes:', 'idta-r2-optimizer' ); ?>
				</label>
				<div class="idta-r2-dryrun-list-scroll">
					<ul id="dryRunFolderList" class="idta-r2-code-list"></ul>
				</div>
			</div>

			<!-- Safety Confirmation Box -->
			<div class="idta-r2-confirmation-box mt-15">
				<label class="idta-r2-checkbox-label">
					<input type="checkbox" id="dryRunConfirmCheckbox">
					<span>
						<?php esc_html_e( 'I understand that confirming deletion will permanently erase all objects in these folders from Cloudflare R2.', 'idta-r2-optimizer' ); ?>
					</span>
				</label>
			</div>

			<div class="idta-r2-dryrun-feedback mt-15" id="dryRunFeedback" hidden></div>
		</div>

		<div class="idta-r2-modal-footer">
			<button type="button" class="button button-secondary" id="dryRunModalCancel">
				<?php esc_html_e( 'Cancel', 'idta-r2-optimizer' ); ?>
			</button>
			<button type="button" class="button button-primary button-danger" id="dryRunModalExecute" disabled>
				<span class="dashicons dashicons-trash"></span>
				<span id="dryRunExecuteLabel"><?php esc_html_e( 'Permanently Delete Orphaned Folders', 'idta-r2-optimizer' ); ?></span>
			</button>
		</div>
	</div>
</div>

