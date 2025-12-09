<?php
/**
 * Diyara Campaigns admin page.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Page_Campaigns
 */
class Admin_Page_Campaigns extends Admin_Page_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_menu_slug() {
		return 'diyara-campaigns';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_page_title() {
		return __( 'Diyara Campaigns', 'diyara-core' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_menu_title() {
		return __( 'Campaigns', 'diyara-core' );
	}

	/**
	 * {@inheritdoc}
	 */
/*
	public function render() {
		$this->render_header(
			__( 'Campaigns', 'diyara-core' ),
			__( 'Configure AI auto-blog campaigns (topics, feeds, schedules).', 'diyara-core' )
		);
		?>

		<p><?php esc_html_e( 'Campaign management UI will be implemented in later phases. For now this is a placeholder.', 'diyara-core' ); ?></p>

		<?php
		$this->render_footer();
	}
*/
  public function render() {
		// Redirect to the built-in post type listing for diyara_campaign.
		if ( ! headers_sent() ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=diyara_campaign' ) );
		}
		exit;
	}  
}