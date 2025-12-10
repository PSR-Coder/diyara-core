<?php
/**
 * Diyara Dashboard admin page.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\Admin;

use DiyaraCore\AI\Logs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Page_Dashboard
 */
class Admin_Page_Dashboard extends Admin_Page_Base {

	public function get_menu_slug() {
		return 'diyara-dashboard';
	}

	public function get_page_title() {
		return __( 'Diyara Dashboard', 'diyara-core' );
	}

	public function get_menu_title() {
		return __( 'Dashboard', 'diyara-core' );
	}

	public function render() {
		$this->render_header(
			__( 'Diyara Dashboard', 'diyara-core' ),
			__( 'Overview of your AI auto-blogging campaigns and SEO/AI status.', 'diyara-core' )
		);

		$logs = Logs::instance();

		$campaigns = get_posts(
			array(
				'post_type'      => 'diyara_campaign',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $campaigns ) ) {
			?>
			<p><?php esc_html_e( 'No campaigns found. Create a campaign to start auto-blogging.', 'diyara-core' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=diyara_campaign' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Add New Campaign', 'diyara-core' ); ?>
				</a>
			</p>
			<?php
			$this->render_footer();
			return;
		}

		?>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Campaign', 'diyara-core' ); ?></th>
					<th><?php esc_html_e( 'Status', 'diyara-core' ); ?></th>
					<th><?php esc_html_e( 'Source', 'diyara-core' ); ?></th>
					<th><?php esc_html_e( 'Total Processed', 'diyara-core' ); ?></th>
					<th><?php esc_html_e( 'Published', 'diyara-core' ); ?></th>
					<th><?php esc_html_e( 'Failed', 'diyara-core' ); ?></th>
					<th><?php esc_html_e( 'Last Run', 'diyara-core' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'diyara-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $campaigns as $c ) : ?>
					<?php
					$campaign_id = $c->ID;
					$stats       = $logs->get_campaign_stats( $campaign_id );

					$status  = get_post_meta( $campaign_id, '_diyara_campaign_status', true );
					$status  = $status ? $status : 'active';

					$source_url  = get_post_meta( $campaign_id, '_diyara_campaign_source_url', true );
					$source_type = get_post_meta( $campaign_id, '_diyara_campaign_source_type', true );
					$source_type = $source_type ? strtoupper( $source_type ) : 'RSS';

					$last_run = get_post_meta( $campaign_id, '_diyara_campaign_last_run_at', true );
					if ( ! $last_run && ! empty( $stats['last_run_at'] ) ) {
						$last_run = $stats['last_run_at'];
					}
					?>
					<tr class="diyara-campaign-row-<?php echo esc_attr( $status ); ?>">
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( $campaign_id ) ); ?>">
								<?php echo esc_html( get_the_title( $c ) ); ?>
							</a>
						</td>
						<td>
							<span class="diyara-campaign-status diyara-campaign-status-<?php echo esc_attr( $status ); ?>">
								<?php echo esc_html( ucfirst( $status ) ); ?>
							</span>
						</td>
						<td>
							<?php if ( $source_url ) : ?>
								<strong><?php echo esc_html( $source_type ); ?></strong><br />
								<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noreferrer">
									<?php echo esc_html( wp_trim_words( $source_url, 5, '…' ) ); ?>
								</a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $stats['published'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></td>
						<td>
							<?php
							if ( $last_run ) {
								echo esc_html( mysql2date( 'Y-m-d H:i', $last_run ) );
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $campaign_id . '&action=edit' ) ); ?>" class="button">
								<?php esc_html_e( 'Edit', 'diyara-core' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $campaign_id . '&action=edit#diyara_campaign_actions' ) ); ?>" class="button">
								<?php esc_html_e( 'Run now', 'diyara-core' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=diyara-logs&campaign_id=' . $campaign_id ) ); ?>" class="button">
								<?php esc_html_e( 'View logs', 'diyara-core' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php

		$this->render_footer();
	}
}