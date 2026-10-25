<?php
/**
 * Admin folder inspection modal template.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div id="idtaR2InspectModal" class="idta-r2-modal-backdrop" aria-hidden="true" role="dialog" aria-modal="true">
	<div class="idta-r2-modal-dialog">
		<div class="idta-r2-modal-header">
			<div class="idta-r2-modal-title-wrap">
				<h3 class="idta-r2-modal-title"><?php esc_html_e( 'Folder Inspection', 'idta-r2-optimizer' ); ?></h3>
				<span class="idta-r2-modal-subtitle" id="inspectFolderSubtitle"></span>
			</div>
			<button type="button" class="idta-r2-modal-close" id="inspectModalClose" aria-label="<?php esc_attr_e( 'Close', 'idta-r2-optimizer' ); ?>">
				&times;
			</button>
		</div>

		<div class="idta-r2-modal-body">
			<div class="idta-r2-inspect-loader" id="inspectLoader">
				<span class="spinner is-active"></span>
				<p><?php esc_html_e( 'Loading asset previews and metadata from Cloudflare R2...', 'idta-r2-optimizer' ); ?></p>
			</div>

			<div class="idta-r2-inspect-content" id="inspectContent" hidden>
				<!-- Linked Order Info (if connected) -->
				<div class="idta-r2-inspect-order-card" id="inspectOrderCard" hidden>
					<div class="idta-r2-order-card-icon">
						<span class="dashicons dashicons-cart"></span>
					</div>
					<div class="idta-r2-order-card-details">
						<h4>
							<span id="inspectOrderTitle"></span>
							<span class="idta-r2-status-pill" id="inspectOrderStatus"></span>
						</h4>
						<p id="inspectOrderMeta"></p>
					</div>
					<div class="idta-r2-order-card-action">
						<a href="#" id="inspectOrderLink" class="button button-secondary" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View Order', 'idta-r2-optimizer' ); ?> &rarr;
						</a>
					</div>
				</div>

				<!-- Document Asset Image Previews -->
				<h4 class="idta-r2-section-title"><?php esc_html_e( 'Verification Document Previews', 'idta-r2-optimizer' ); ?></h4>
				<div class="idta-r2-preview-grid" id="inspectPreviewGrid">
					<!-- Populated dynamically via JS -->
				</div>

				<!-- Detailed Object Metadata Table -->
				<h4 class="idta-r2-section-title mt-20"><?php esc_html_e( 'R2 Object File Details', 'idta-r2-optimizer' ); ?></h4>
				<table class="widefat striped idta-r2-files-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Filename', 'idta-r2-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Size', 'idta-r2-optimizer' ); ?></th>
							<th><?php esc_html_e( 'ETag', 'idta-r2-optimizer' ); ?></th>
							<th><?php esc_html_e( 'Last Modified', 'idta-r2-optimizer' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Action', 'idta-r2-optimizer' ); ?></th>
						</tr>
					</thead>
					<tbody id="inspectFilesTbody">
						<!-- Populated dynamically via JS -->
					</tbody>
				</table>
			</div>
		</div>

		<div class="idta-r2-modal-footer">
			<button type="button" class="button button-secondary" id="inspectModalCloseBtn">
				<?php esc_html_e( 'Close', 'idta-r2-optimizer' ); ?>
			</button>
		</div>
	</div>
</div>

