<?php
/**
 * Admin settings template for Cloudflare R2 credentials and bucket mapping.
 *
 * @package IDTA\R2Optimizer
 *
 * @var array<string, mixed> $settings Stored plugin settings.
 * @var array<string, array{source: string, url: string, bucket: string, is_manual: bool}> $source_mappings Detected sources and current bucket assignments.
 * @var array<\IDTA\R2Optimizer\Bucket_Record> $available_buckets List of discovered buckets.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$has_secret = ! empty( $settings['secret_access_key'] );
?>
<div class="idta-r2-settings-wrap">
	<form method="post" action="" id="idtaR2SettingsForm">
		<?php wp_nonce_field( 'idta_r2_save_settings', 'idta_r2_settings_nonce' ); ?>
		<input type="hidden" name="action" value="idta_r2_save_settings">

		<!-- Cloudflare R2 API Credentials Card -->
		<div class="idta-r2-card">
			<div class="idta-r2-card-header">
				<h3 class="idta-r2-card-title"><?php esc_html_e( 'Cloudflare R2 S3-Compatible Credentials', 'idta-r2-optimizer' ); ?></h3>
				<p class="idta-r2-card-description">
					<?php esc_html_e( 'Connect securely to Cloudflare R2 object storage using an S3-compatible API token with Object Read & Write permissions.', 'idta-r2-optimizer' ); ?>
				</p>
			</div>

			<div class="idta-r2-card-body">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="idta_r2_account_id"><?php esc_html_e( 'Account ID', 'idta-r2-optimizer' ); ?> <span class="required">*</span></label>
							</th>
							<td>
								<input type="text" id="idta_r2_account_id" name="idta_r2[account_id]"
									value="<?php echo esc_attr( (string) ( $settings['account_id'] ?? '' ) ); ?>"
									class="regular-text code" placeholder="32-character hex ID (e.g. 7c9a8b1...)" required>
								<p class="description">
									<?php esc_html_e( 'Your Cloudflare Account ID from the Cloudflare Dashboard URL or Overview tab.', 'idta-r2-optimizer' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="idta_r2_access_key_id"><?php esc_html_e( 'Access Key ID', 'idta-r2-optimizer' ); ?> <span class="required">*</span></label>
							</th>
							<td>
								<input type="text" id="idta_r2_access_key_id" name="idta_r2[access_key_id]"
									value="<?php echo esc_attr( (string) ( $settings['access_key_id'] ?? '' ) ); ?>"
									class="regular-text code" placeholder="32-character Access Key ID" required>
								<p class="description">
									<?php esc_html_e( 'Cloudflare R2 Token Access Key ID.', 'idta-r2-optimizer' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="idta_r2_secret_access_key"><?php esc_html_e( 'Secret Access Key', 'idta-r2-optimizer' ); ?> <span class="required">*</span></label>
							</th>
							<td>
								<input type="password" id="idta_r2_secret_access_key" name="idta_r2[secret_access_key]"
									value="" class="regular-text code"
									placeholder="<?php echo $has_secret ? esc_attr__( '••••••••••••••••••••••••••••••••', 'idta-r2-optimizer' ) : esc_attr__( '64-character Secret Key', 'idta-r2-optimizer' ); ?>"
									autocomplete="new-password">
								<p class="description">
									<?php
									if ( $has_secret ) {
										esc_html_e( 'A Secret Key is already configured. Leave blank to keep existing key, or type a new key to update.', 'idta-r2-optimizer' );
									} else {
										esc_html_e( 'Cloudflare R2 64-character Secret Access Key.', 'idta-r2-optimizer' );
									}
									?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="idta_r2_default_bucket"><?php esc_html_e( 'Default Active Bucket', 'idta-r2-optimizer' ); ?> <span class="required">*</span></label>
							</th>
							<td>
								<input type="text" id="idta_r2_default_bucket" name="idta_r2[default_bucket]"
									value="<?php echo esc_attr( (string) ( $settings['default_bucket'] ?? 'idta-html' ) ); ?>"
									class="regular-text code" placeholder="e.g. idta-html" required>
								<p class="description">
									<?php esc_html_e( 'Primary bucket storing order verification assets. Standard IDTA bucket is idta-html.', 'idta-r2-optimizer' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="idta_r2_custom_endpoint"><?php esc_html_e( 'Custom S3 Endpoint (Optional)', 'idta-r2-optimizer' ); ?></label>
							</th>
							<td>
								<input type="url" id="idta_r2_custom_endpoint" name="idta_r2[custom_endpoint]"
									value="<?php echo esc_attr( (string) ( $settings['custom_endpoint'] ?? '' ) ); ?>"
									class="large-text code" placeholder="https://{account_id}.r2.cloudflarestorage.com">
								<p class="description">
									<?php esc_html_e( 'Leave empty to automatically use https://{account_id}.r2.cloudflarestorage.com.', 'idta-r2-optimizer' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Live Connection Test Action -->
				<div class="idta-r2-test-box">
					<button type="button" class="button button-secondary" id="idtaR2BtnTestConnection">
						<span class="dashicons dashicons-rest-api"></span>
						<?php esc_html_e( 'Test R2 Connection', 'idta-r2-optimizer' ); ?>
					</button>
					<span class="spinner" id="idtaR2TestSpinner"></span>
					<div class="idta-r2-test-result" id="idtaR2TestResult" hidden></div>
				</div>
			</div>
		</div>

		<!-- Front-End Source to Bucket Mapping Card -->
		<div class="idta-r2-card mt-20">
			<div class="idta-r2-card-header">
				<h3 class="idta-r2-card-title"><?php esc_html_e( 'Front-End Source to R2 Bucket Mappings', 'idta-r2-optimizer' ); ?></h3>
				<p class="idta-r2-card-description">
					<?php esc_html_e( 'Map each front-end store identifier (_idp_order_from) to its respective Cloudflare R2 bucket. Orders taken without an explicit mapping use the Default Active Bucket above.', 'idta-r2-optimizer' ); ?>
				</p>
			</div>

			<div class="idta-r2-card-body">
				<table class="widefat striped idta-r2-mapping-table">
					<thead>
						<tr>
							<th class="column-source"><?php esc_html_e( 'Source Identifier', 'idta-r2-optimizer' ); ?></th>
							<th class="column-url"><?php esc_html_e( 'Upload Base URL / Origin', 'idta-r2-optimizer' ); ?></th>
							<th class="column-bucket"><?php esc_html_e( 'Target R2 Bucket', 'idta-r2-optimizer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $source_mappings ) ) : ?>
							<tr>
								<td colspan="3" class="no-items"><?php esc_html_e( 'No front-end sources detected.', 'idta-r2-optimizer' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $source_mappings as $source_key => $mapping ) : ?>
								<tr>
									<td class="column-source">
										<strong><code><?php echo esc_html( $source_key ); ?></code></strong>
									</td>
									<td class="column-url">
										<code class="text-muted"><?php echo esc_html( $mapping['url'] ); ?></code>
									</td>
									<td class="column-bucket">
										<input type="text" name="idta_r2[source_buckets][<?php echo esc_attr( $source_key ); ?>]"
											value="<?php echo esc_attr( $mapping['bucket'] ); ?>"
											class="regular-text code" placeholder="<?php echo esc_attr( (string) ( $settings['default_bucket'] ?? 'idta-html' ) ); ?>">
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<!-- Automated Audits & Retention Policies Card -->
		<div class="idta-r2-card mt-20">
			<div class="idta-r2-card-header">
				<h3 class="idta-r2-card-title"><?php esc_html_e( 'Automated Background Audits & Retention Policies', 'idta-r2-optimizer' ); ?></h3>
				<p class="idta-r2-card-description">
					<?php esc_html_e( 'Configure automated Action Scheduler background scans, high-storage email notifications, and auto-purge retention rules for abandoned checkout uploads.', 'idta-r2-optimizer' ); ?>
				</p>
			</div>

			<div class="idta-r2-card-body">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="idta_r2_schedule_frequency"><?php esc_html_e( 'Background Audit Schedule', 'idta-r2-optimizer' ); ?></label>
							</th>
							<td>
								<?php $freq = (string) ( $settings['schedule_frequency'] ?? 'daily' ); ?>
								<select id="idta_r2_schedule_frequency" name="idta_r2[schedule_frequency]" class="regular-text">
									<option value="none" <?php selected( $freq, 'none' ); ?>><?php esc_html_e( 'Disabled (Manual Scans Only)', 'idta-r2-optimizer' ); ?></option>
									<option value="daily" <?php selected( $freq, 'daily' ); ?>><?php esc_html_e( 'Daily (Recommended)', 'idta-r2-optimizer' ); ?></option>
									<option value="weekly" <?php selected( $freq, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'idta-r2-optimizer' ); ?></option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Periodically re-indexes R2 buckets in the background and warms cache transients for fast dashboard loads.', 'idta-r2-optimizer' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="idta_r2_alert_threshold_gb"><?php esc_html_e( 'Storage Alert Threshold (GB)', 'idta-r2-optimizer' ); ?></label>
							</th>
							<td>
								<input type="number" step="0.5" min="0" id="idta_r2_alert_threshold_gb" name="idta_r2[alert_threshold_gb]"
									value="<?php echo esc_attr( (string) ( $settings['alert_threshold_gb'] ?? '5.0' ) ); ?>"
									class="small-text">
								<span class="description"><?php esc_html_e( 'GB', 'idta-r2-optimizer' ); ?></span>
								<p class="description">
									<?php esc_html_e( 'Sends an email alert to the store administrator if total orphaned storage volume exceeds this size. Set to 0 to disable alerts.', 'idta-r2-optimizer' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="idta_r2_auto_purge_days"><?php esc_html_e( 'Automated Orphan Retention', 'idta-r2-optimizer' ); ?></label>
							</th>
							<td>
								<?php $purge_days = (int) ( $settings['auto_purge_days'] ?? 0 ); ?>
								<select id="idta_r2_auto_purge_days" name="idta_r2[auto_purge_days]" class="regular-text">
									<option value="0" <?php selected( $purge_days, 0 ); ?>><?php esc_html_e( 'Disabled (Retain indefinitely until manual cleanup)', 'idta-r2-optimizer' ); ?></option>
									<option value="30" <?php selected( $purge_days, 30 ); ?>><?php esc_html_e( 'Automatically purge orphaned folders older than 30 days', 'idta-r2-optimizer' ); ?></option>
									<option value="60" <?php selected( $purge_days, 60 ); ?>><?php esc_html_e( 'Automatically purge orphaned folders older than 60 days', 'idta-r2-optimizer' ); ?></option>
									<option value="90" <?php selected( $purge_days, 90 ); ?>><?php esc_html_e( 'Automatically purge orphaned folders older than 90 days', 'idta-r2-optimizer' ); ?></option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Strict Safety Policy: Automatically removes unlinked abandoned uploads older than the specified age. Connected order documents are strictly preserved.', 'idta-r2-optimizer' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<div class="idta-r2-form-actions mt-20">
			<button type="submit" name="submit" class="button button-primary button-hero">
				<?php esc_html_e( 'Save Settings', 'idta-r2-optimizer' ); ?>
			</button>
		</div>
	</form>
</div>

