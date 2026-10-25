<?php
/**
 * Admin Remap Order Modal dialog.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div id="idtaR2RemapModal" class="idta-r2-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
	<div class="idta-r2-modal-dialog idta-r2-dialog-sm">
		<div class="idta-r2-modal-header">
			<h3 class="idta-r2-modal-title"><?php esc_html_e( 'Link Folder to WooCommerce Order', 'idta-r2-optimizer' ); ?></h3>
			<button type="button" class="idta-r2-modal-close" id="remapModalClose" aria-label="<?php esc_attr_e( 'Close', 'idta-r2-optimizer' ); ?>">
				&times;
			</button>
		</div>

		<div class="idta-r2-modal-body">
			<p class="text-muted">
				<?php esc_html_e( 'Associating this folder will update the _idp_assets meta key on the order, linking the customer verification documents.', 'idta-r2-optimizer' ); ?>
			</p>

			<div class="form-field mt-15">
				<label for="remapTargetFolder" class="idta-r2-label"><?php esc_html_e( 'R2 Folder Path:', 'idta-r2-optimizer' ); ?></label>
				<input type="text" id="remapTargetFolder" class="regular-text code" readonly>
			</div>

			<div class="form-field mt-15">
				<label for="remapOrderId" class="idta-r2-label"><?php esc_html_e( 'Target WooCommerce Order ID:', 'idta-r2-optimizer' ); ?> <span class="required">*</span></label>
				<input type="number" id="remapOrderId" class="regular-text" placeholder="e.g. 12345" min="1" required>
				<p class="description">
					<?php esc_html_e( 'Enter the numeric Order ID from WooCommerce → Orders.', 'idta-r2-optimizer' ); ?>
				</p>
			</div>

			<div class="idta-r2-remap-feedback" id="remapFeedback" hidden></div>
		</div>

		<div class="idta-r2-modal-footer">
			<button type="button" class="button button-secondary" id="remapModalCancel">
				<?php esc_html_e( 'Cancel', 'idta-r2-optimizer' ); ?>
			</button>
			<button type="button" class="button button-primary" id="remapModalSubmit">
				<span class="dashicons dashicons-admin-links"></span>
				<span id="remapSubmitLabel"><?php esc_html_e( 'Confirm Link', 'idta-r2-optimizer' ); ?></span>
			</button>
		</div>
	</div>
</div>

