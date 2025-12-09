<?php
/**
 * Diyara Logs admin page.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\Admin;

use DiyaraCore\AI\Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Page_Logs
 */
class Admin_Page_Logs extends Admin_Page_Base {

	public function get_menu_slug() {
		return 'diyara-logs';
	}

	public function get_page_title() {
		return __( 'Diyara Logs', 'diyara-core' );
	}

	public function get_menu_title() {
		return __( 'Logs', 'diyara-core' );
	}

	public function render() {
		$this->render_header(
			__( 'AI & Auto-Blog Logs', 'diyara-core' ),
			__( 'Monitor recent AI runs, errors, and generated posts.', 'diyara-core' )
		);

		$logs = Logs::instance();

		// Filters.
		$current_campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$current_status      = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'any';
		$paged               = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page            = 20;

		$filters = array(
			'campaign_id' => $current_campaign_id,
			'status'      => $current_status,
		);

		$result   = $logs->get_logs( $filters, $per_page, $paged );
		$posts    = $result['posts'];
		$total    = $result['total'];
		$max_pages = $result['max_num_pages'];

		// Campaign dropdown options.
		$campaigns = get_posts(
			array(
				'post_type'      => 'diyara_campaign',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<form method="get" action="">
			<input type="hidden" name="page" value="<?php echo esc_attr( $this->get_menu_slug() ); ?>" />

			<div class="tablenav top" style="margin-bottom: 10px;">
				<div class="alignleft actions">
					<label for="diyara_logs_campaign" class="screen-reader-text"><?php esc_html_e( 'Filter by campaign', 'diyara-core' ); ?></label>
					<select name="campaign_id" id="diyara_logs_campaign">
						<option value="0"><?php esc_html_e( 'All campaigns', 'diyara-core' ); ?></option>
						<?php foreach ( $campaigns as $c ) : ?>
							<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $current_campaign_id, $c->ID ); ?>>
								<?php echo esc_html( get_the_title( $c ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<label for="diyara_logs_status" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'diyara-core' ); ?></label>
					<select name="status" id="diyara_logs_status">
						<option value="any" <?php selected( $current_status, 'any' ); ?>><?php esc_html_e( 'All statuses', 'diyara-core' ); ?></option>
						<option value="published" <?php selected( $current_status, 'published' ); ?>><?php esc_html_e( 'Published', 'diyara-core' ); ?></option>
						<option value="draft" <?php selected( $current_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'diyara-core' ); ?></option>
						<option value="failed" <?php selected( $current_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'diyara-core' ); ?></option>
						<option value="skipped" <?php selected( $current_status, 'skipped' ); ?>><?php esc_html_e( 'Skipped', 'diyara-core' ); ?></option>
					</select>

					<?php submit_button( __( 'Filter', 'diyara-core' ), 'secondary', '', false ); ?>
				</div>

				<div class="tablenav-pages">
					<?php
					if ( $max_pages > 1 ) {
						echo paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $max_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						);
					}
					?>
				</div>
			</div>

			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'diyara-core' ); ?></th>
						<th><?php esc_html_e( 'Campaign', 'diyara-core' ); ?></th>
						<th><?php esc_html_e( 'Source URL', 'diyara-core' ); ?></th>
						<th><?php esc_html_e( 'Target Post', 'diyara-core' ); ?></th>
						<th><?php esc_html_e( 'Status', 'diyara-core' ); ?></th>
						<th><?php esc_html_e( 'Tokens', 'diyara-core' ); ?></th>
						<th><?php esc_html_e( 'Messages', 'diyara-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $posts ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No logs found.', 'diyara-core' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $posts as $log_post ) : ?>
							<?php
							$log_id      = $log_post->ID;
							$created_at  = get_post_meta( $log_id, '_diyara_log_created_at', true );
							$created_at  = $created_at ? $created_at : $log_post->post_date;
							$campaign_id = (int) get_post_meta( $log_id, '_diyara_log_campaign_id', true );
							$campaign    = $campaign_id ? get_post( $campaign_id ) : null;
							$source_url  = get_post_meta( $log_id, '_diyara_log_source_url', true );
							$target_url  = get_post_meta( $log_id, '_diyara_log_target_url', true );
							$post_id     = (int) get_post_meta( $log_id, '_diyara_log_wordpress_post_id', true );
							$status      = get_post_meta( $log_id, '_diyara_log_status', true );
							$tokens      = (int) get_post_meta( $log_id, '_diyara_log_tokens_used', true );
							$messages    = get_post_meta( $log_id, '_diyara_log_messages', true );
							if ( ! is_array( $messages ) ) {
								$messages = array();
							}
							// Build preview and full text.
							$first_msg = '';
							foreach ( $messages as $m ) {
								if ( '' !== trim( $m ) ) {
									$first_msg = $m;
									break;
								}
							}
							$preview  = $first_msg ? wp_trim_words( $first_msg, 12, '…' ) : '';
							?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $created_at ) ); ?></td>
								<td>
									<?php
									if ( $campaign ) {
										$edit_campaign_url = get_edit_post_link( $campaign_id );
										echo '<a href="' . esc_url( $edit_campaign_url ) . '">' . esc_html( get_the_title( $campaign ) ) . '</a>';
									} else {
										echo '&mdash;';
									}
									?>
								</td>
								<td>
									<?php if ( $source_url ) : ?>
										<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noreferrer">
											<?php echo esc_html( wp_trim_words( $source_url, 5, '…' ) ); ?>
										</a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $post_id ) {
										$edit_link = get_edit_post_link( $post_id );
										$view_link = get_permalink( $post_id );
										echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Edit', 'diyara-core' ) . '</a>';
										echo ' | ';
										echo '<a href="' . esc_url( $view_link ) . '" target="_blank" rel="noreferrer">' . esc_html__( 'View', 'diyara-core' ) . '</a>';
									} elseif ( $target_url ) {
										echo '<a href="' . esc_url( $target_url ) . '" target="_blank" rel="noreferrer">' . esc_html__( 'External', 'diyara-core' ) . '</a>';
									} else {
										echo '&mdash;';
									}
									?>
								</td>
								<td>
									<?php
									$label = $status ? $status : __( 'unknown', 'diyara-core' );
									$css   = 'diyara-status-' . sanitize_html_class( $status ? $status : 'unknown' );
									echo '<span class="' . esc_attr( $css ) . '">' . esc_html( $label ) . '</span>';
									?>
								</td>
								<td><?php echo $tokens ? esc_html( number_format_i18n( $tokens ) ) : '&mdash;'; ?></td>
								<td>
									<?php if ( ! empty( $messages ) ) : ?>
										<div class="diyara-log-msg-cell">
											<span class="diyara-log-msg-preview">
												<?php
												if ( $preview ) {
													echo esc_html( $preview );
												} else {
													// Fallback: combine first few messages if no first_msg.
													$combined = wp_trim_words( implode( ' ', $messages ), 12, '…' );
													echo esc_html( $combined );
												}
												?>
											</span>
											<button type="button"
											        class="button-link diyara-log-msg-toggle"
											        data-show-label="<?php echo esc_attr( __( 'Details', 'diyara-core' ) ); ?>"
											        data-hide-label="<?php echo esc_attr( __( 'Hide', 'diyara-core' ) ); ?>">
												<?php esc_html_e( 'Details', 'diyara-core' ); ?>
											</button>
											<div class="diyara-log-msg-full" style="display:none;">
												<?php foreach ( $messages as $line ) : ?>
													<?php if ( '' === trim( $line ) ) { continue; } ?>
													<div class="diyara-log-msg-line"><?php echo esc_html( $line ); ?></div>
												<?php endforeach; ?>
											</div>
										</div>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</form>

		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var toggles = document.querySelectorAll('.diyara-log-msg-toggle');
			toggles.forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.preventDefault();
					var cell = this.closest('.diyara-log-msg-cell');
					if (!cell) return;
					var full = cell.querySelector('.diyara-log-msg-full');
					if (!full) return;

					var isVisible = full.style.display !== 'none';
					full.style.display = isVisible ? 'none' : 'block';

					var showLabel = this.getAttribute('data-show-label') || 'Details';
					var hideLabel = this.getAttribute('data-hide-label') || 'Hide';
					this.textContent = isVisible ? showLabel : hideLabel;
				});
			});
		});
		</script>

		<?php
		$this->render_footer();
	}
}