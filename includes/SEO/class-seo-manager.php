<?php
/**
 * SEO Manager: global SEO settings, per-post meta, and meta tag output.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SEO_Manager
 */
class SEO_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var SEO_Manager|null
	 */
	protected static $instance = null;

	/**
	 * Option name for global SEO settings.
	 *
	 * @var string
	 */
	protected $option_name = 'diyara_core_seo_options';

  /**
	 * Last calculated description for current request.
	 *
	 * @var string
	 */
	protected $current_description = '';

	/**
	 * Last post ID used for current request.
	 *
	 * @var int
	 */
	protected $current_post_id = 0;
	/**
	 * Get singleton instance.
	 *
	 * @return SEO_Manager
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Admin global settings + meta box.
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_post_meta' ), 10, 2 );

		// Front-end output.
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ) );
		add_action( 'wp_head', array( $this, 'output_meta_tags' ), 1 );
		add_action( 'wp_head', array( $this, 'output_open_graph_tags' ), 5 );
		add_action( 'wp_head', array( $this, 'output_twitter_tags' ), 6 );
	}

	/**
	 * Get global SEO options with defaults.
	 *
	 * @return array
	 */
	public function get_options() {
		$defaults = array(
			'home_title'            => get_bloginfo( 'name' ),
			'home_description'      => get_bloginfo( 'description' ),
			'default_robots_index'  => 'index',
			'default_robots_follow' => 'follow',
		);

		$options = get_option( $this->option_name, array() );

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return wp_parse_args( $options, $defaults );
	}

	/**
	 * Sanitize global SEO options.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$output = array();

		$output['home_title'] = isset( $input['home_title'] )
			? sanitize_text_field( $input['home_title'] )
			: '';

		$output['home_description'] = isset( $input['home_description'] )
			? sanitize_textarea_field( $input['home_description'] )
			: '';

		$index = isset( $input['default_robots_index'] ) ? $input['default_robots_index'] : 'index';
		$follow = isset( $input['default_robots_follow'] ) ? $input['default_robots_follow'] : 'follow';

		$output['default_robots_index']  = in_array( $index, array( 'index', 'noindex' ), true ) ? $index : 'index';
		$output['default_robots_follow'] = in_array( $follow, array( 'follow', 'nofollow' ), true ) ? $follow : 'follow';

		$output['og_default_image'] = isset( $input['og_default_image'] )
			? esc_url_raw( $input['og_default_image'] )
			: '';

		$output['twitter_username'] = isset( $input['twitter_username'] )
			? sanitize_text_field( $input['twitter_username'] )
			: '';

		$card_type = isset( $input['twitter_card_type'] ) ? $input['twitter_card_type'] : 'summary_large_image';
		$output['twitter_card_type'] = in_array( $card_type, array( 'summary', 'summary_large_image' ), true )
			? $card_type
			: 'summary_large_image';
			
		return $output;
	}

	/**
	 * Register settings (global SEO options) using Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {

			// Keep existing group name.
			register_setting(
					'diyara_core_seo',
					$this->option_name,
					array( $this, 'sanitize_options' )
			);

			add_settings_section(
					'diyara_core_seo_main',
					__( 'Global SEO Settings', 'diyara-core' ),
					array( $this, 'render_settings_section_intro' ),
					'diyara-settings-general' // ← page slug for General tab
			);

			add_settings_field(
					'home_title',
					__( 'Homepage SEO title', 'diyara-core' ),
					array( $this, 'field_home_title' ),
					'diyara-settings-general',
					'diyara_core_seo_main'
			);

			add_settings_field(
					'home_description',
					__( 'Homepage meta description', 'diyara-core' ),
					array( $this, 'field_home_description' ),
					'diyara-settings-general',
					'diyara_core_seo_main'
			);

			add_settings_field(
					'default_robots',
					__( 'Default robots meta', 'diyara-core' ),
					array( $this, 'field_default_robots' ),
					'diyara-settings-general',
					'diyara_core_seo_main'
			);

			add_settings_field(
					'og_default_image',
					__( 'Default Open Graph image URL', 'diyara-core' ),
					array( $this, 'field_og_default_image' ),
					'diyara-settings-general',
					'diyara_core_seo_main'
			);

			add_settings_field(
					'twitter_username',
					__( 'Twitter / X username', 'diyara-core' ),
					array( $this, 'field_twitter_username' ),
					'diyara-settings-general',
					'diyara_core_seo_main'
			);

			add_settings_field(
					'twitter_card_type',
					__( 'Twitter card type', 'diyara-core' ),
					array( $this, 'field_twitter_card_type' ),
					'diyara-settings-general',
					'diyara_core_seo_main'
			);
	}

	/**
	 * Section intro text.
	 *
	 * @return void
	 */
	public function render_settings_section_intro() {
		echo '<p>' . esc_html__( 'Configure default SEO meta for your site. Individual posts and pages can override these values.', 'diyara-core' ) . '</p>';
	}

	/**
	 * Field: home title.
	 *
	 * @return void
	 */
	public function field_home_title() {
		$options = $this->get_options();
		$value   = isset( $options['home_title'] ) ? $options['home_title'] : '';
		?>
		<input type="text"
			name="<?php echo esc_attr( $this->option_name . '[home_title]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text" />
		<p class="description">
			<?php esc_html_e( 'Used as the SEO title on the homepage when no custom title is set.', 'diyara-core' ); ?>
		</p>
		<?php
	}

	/**
	 * Field: home description.
	 *
	 * @return void
	 */
	public function field_home_description() {
		$options = $this->get_options();
		$value   = isset( $options['home_description'] ) ? $options['home_description'] : '';
		?>
		<textarea
			name="<?php echo esc_attr( $this->option_name . '[home_description]' ); ?>"
			rows="3"
			class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Short description for the homepage (usually 120–160 characters).', 'diyara-core' ); ?>
		</p>
		<?php
	}

	/**
	 * Field: default robots meta.
	 *
	 * @return void
	 */
	public function field_default_robots() {
		$options = $this->get_options();

		$index  = isset( $options['default_robots_index'] ) ? $options['default_robots_index'] : 'index';
		$follow = isset( $options['default_robots_follow'] ) ? $options['default_robots_follow'] : 'follow';
		?>
		<label>
			<?php esc_html_e( 'Indexing:', 'diyara-core' ); ?>
			<select name="<?php echo esc_attr( $this->option_name . '[default_robots_index]' ); ?>">
				<option value="index" <?php selected( $index, 'index' ); ?>>index</option>
				<option value="noindex" <?php selected( $index, 'noindex' ); ?>>noindex</option>
			</select>
		</label>
		<br /><br />
		<label>
			<?php esc_html_e( 'Following links:', 'diyara-core' ); ?>
			<select name="<?php echo esc_attr( $this->option_name . '[default_robots_follow]' ); ?>">
				<option value="follow" <?php selected( $follow, 'follow' ); ?>>follow</option>
				<option value="nofollow" <?php selected( $follow, 'nofollow' ); ?>>nofollow</option>
			</select>
		</label>
		<p class="description">
			<?php esc_html_e( 'These defaults apply site‑wide but can be overridden per post.', 'diyara-core' ); ?>
		</p>
		<?php
	}
	
	/**
	 * Field: default Open Graph image URL.
	 *
	 * @return void
	 */
	public function field_og_default_image() {
		$options = $this->get_options();
		$value   = isset( $options['og_default_image'] ) ? $options['og_default_image'] : '';
		?>
		<input type="url"
			name="<?php echo esc_attr( $this->option_name . '[og_default_image]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text" />
		<p class="description">
			<?php esc_html_e( 'Optional full URL to a default image used for social sharing when no featured image is available (recommended 1200×630).', 'diyara-core' ); ?>
		</p>
		<?php
	}

	/**
	 * Field: Twitter username.
	 *
	 * @return void
	 */
	public function field_twitter_username() {
		$options = $this->get_options();
		$value   = isset( $options['twitter_username'] ) ? $options['twitter_username'] : '';
		?>
		<input type="text"
			name="<?php echo esc_attr( $this->option_name . '[twitter_username]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text" />
		<p class="description">
			<?php esc_html_e( 'Your Twitter / X username (without @). Used for twitter:site meta tag.', 'diyara-core' ); ?>
		</p>
		<?php
	}

	/**
	 * Field: Twitter card type.
	 *
	 * @return void
	 */
	public function field_twitter_card_type() {
		$options = $this->get_options();
		$value   = isset( $options['twitter_card_type'] ) ? $options['twitter_card_type'] : 'summary_large_image';
		?>
		<select name="<?php echo esc_attr( $this->option_name . '[twitter_card_type]' ); ?>">
			<option value="summary" <?php selected( $value, 'summary' ); ?>>summary</option>
			<option value="summary_large_image" <?php selected( $value, 'summary_large_image' ); ?>>summary_large_image</option>
		</select>
		<p class="description">
			<?php esc_html_e( 'Choose how links from your site appear on Twitter / X.', 'diyara-core' ); ?>
		</p>
		<?php
	}	

	/**
	 * Register SEO meta box for posts and pages.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		$post_types = array( 'post', 'page' );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'diyara_seo_meta',
				__( 'Diyara SEO', 'diyara-core' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Render SEO meta box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'diyara_seo_meta_save', 'diyara_seo_meta_nonce' );

		$title       = get_post_meta( $post->ID, '_diyara_seo_title', true );
		$description = get_post_meta( $post->ID, '_diyara_seo_description', true );
		$focus       = get_post_meta( $post->ID, '_diyara_seo_focus_keyword', true );
		$noindex     = get_post_meta( $post->ID, '_diyara_seo_noindex', true );
		$nofollow    = get_post_meta( $post->ID, '_diyara_seo_nofollow', true );
		?>
		<p>
			<label for="diyara_seo_title"><strong><?php esc_html_e( 'SEO title', 'diyara-core' ); ?></strong></label><br />
			<input type="text" id="diyara_seo_title" name="diyara_seo_title" class="large-text"
			       value="<?php echo esc_attr( $title ); ?>" />
			<span class="description"><?php esc_html_e( 'Optional custom title for search results. Leave empty to use the post title.', 'diyara-core' ); ?></span>
		</p>

		<p>
			<label for="diyara_seo_description"><strong><?php esc_html_e( 'Meta description', 'diyara-core' ); ?></strong></label><br />
			<textarea id="diyara_seo_description" name="diyara_seo_description" rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
			<span class="description"><?php esc_html_e( 'Short summary for search results (about 120–160 characters).', 'diyara-core' ); ?></span>
		</p>

		<p>
			<label for="diyara_seo_focus_keyword"><strong><?php esc_html_e( 'Focus keyword (optional)', 'diyara-core' ); ?></strong></label><br />
			<input type="text" id="diyara_seo_focus_keyword" name="diyara_seo_focus_keyword" class="regular-text"
			       value="<?php echo esc_attr( $focus ); ?>" />
			<span class="description"><?php esc_html_e( 'Used only for simple analysis in a later phase.', 'diyara-core' ); ?></span>
		</p>

		<p>
			<label>
				<input type="checkbox" name="diyara_seo_noindex" value="1" <?php checked( $noindex, '1' ); ?> />
				<?php esc_html_e( 'Ask search engines not to index this page (noindex).', 'diyara-core' ); ?>
			</label>
			<br />
			<label>
				<input type="checkbox" name="diyara_seo_nofollow" value="1" <?php checked( $nofollow, '1' ); ?> />
				<?php esc_html_e( 'Ask search engines not to follow links on this page (nofollow).', 'diyara-core' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Save SEO meta box data.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_post_meta( $post_id, $post ) {
		// Security / capability checks.
		if ( ! isset( $_POST['diyara_seo_meta_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['diyara_seo_meta_nonce'] ), 'diyara_seo_meta_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( 'revision' === $post->post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// SEO title.
		$title = isset( $_POST['diyara_seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['diyara_seo_title'] ) ) : '';
		if ( $title ) {
			update_post_meta( $post_id, '_diyara_seo_title', $title );
		} else {
			delete_post_meta( $post_id, '_diyara_seo_title' );
		}

		// Meta description.
		$description = isset( $_POST['diyara_seo_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['diyara_seo_description'] ) ) : '';
		if ( $description ) {
			update_post_meta( $post_id, '_diyara_seo_description', $description );
		} else {
			delete_post_meta( $post_id, '_diyara_seo_description' );
		}

		// Focus keyword.
		$focus = isset( $_POST['diyara_seo_focus_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['diyara_seo_focus_keyword'] ) ) : '';
		if ( $focus ) {
			update_post_meta( $post_id, '_diyara_seo_focus_keyword', $focus );
		} else {
			delete_post_meta( $post_id, '_diyara_seo_focus_keyword' );
		}

		// Robots flags.
		$noindex  = isset( $_POST['diyara_seo_noindex'] ) ? '1' : '';
		$nofollow = isset( $_POST['diyara_seo_nofollow'] ) ? '1' : '';

		if ( $noindex ) {
			update_post_meta( $post_id, '_diyara_seo_noindex', '1' );
		} else {
			delete_post_meta( $post_id, '_diyara_seo_noindex' );
		}

		if ( $nofollow ) {
			update_post_meta( $post_id, '_diyara_seo_nofollow', '1' );
		} else {
			delete_post_meta( $post_id, '_diyara_seo_nofollow' );
		}
	}

	/**
	 * Filter document title with SEO title if set.
	 *
	 * @param string $title Default title.
	 * @return string
	 */
	public function filter_document_title( $title ) {

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( $post_id ) {
				$custom = get_post_meta( $post_id, '_diyara_seo_title', true );
				if ( $custom ) {
					return $custom;
				}
			}
		}

		if ( is_front_page() || is_home() ) {
			$options = $this->get_options();
			if ( ! empty( $options['home_title'] ) ) {
				return $options['home_title'];
			}
		}

		return $title;
	}

	/**
	 * Output meta description and robots tags.
	 *
	 * @return void
	 */
	public function output_meta_tags() {
		if ( is_admin() ) {
			return;
		}

		$options = $this->get_options();

		// Reset current info.
		$this->current_description = '';
		$this->current_post_id     = 0;

		$desc    = '';
		$post_id = 0;

		// FRONT PAGE / BLOG HOME FIRST.
		if ( is_front_page() || is_home() ) {
			$desc = ! empty( $options['home_description'] )
				? $options['home_description']
				: get_bloginfo( 'description' );

			if ( is_front_page() && is_singular() ) {
				$post_id = get_queried_object_id();
			}
		} elseif ( is_singular() ) {
			$post_id = get_queried_object_id();

			if ( $post_id ) {
				$custom_desc = get_post_meta( $post_id, '_diyara_seo_description', true );
				if ( $custom_desc ) {
					$desc = $custom_desc;
				} elseif ( has_excerpt( $post_id ) ) {
					$desc = wp_strip_all_tags( get_the_excerpt( $post_id ) );
				} else {
					$content = get_post_field( 'post_content', $post_id );
					$desc    = wp_trim_words( wp_strip_all_tags( $content ), 30 );
				}
			}
		}

		// Save for OG/Twitter methods.
		$this->current_description = $desc;
		$this->current_post_id     = $post_id;

		// Output meta description if we have one.
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . "\" />\n";
		}

		// Robots meta: start from global defaults.
		$index  = isset( $options['default_robots_index'] ) ? $options['default_robots_index'] : 'index';
		$follow = isset( $options['default_robots_follow'] ) ? $options['default_robots_follow'] : 'follow';

		// Per‑post override.
		if ( $post_id ) {
			$noindex  = get_post_meta( $post_id, '_diyara_seo_noindex', true );
			$nofollow = get_post_meta( $post_id, '_diyara_seo_nofollow', true );

			if ( '1' === $noindex ) {
				$index = 'noindex';
			}
			if ( '1' === $nofollow ) {
				$follow = 'nofollow';
			}
		}

		$robots_value = trim( $index . ',' . $follow, ' ,' );
		if ( $robots_value ) {
			echo '<meta name="robots" content="' . esc_attr( $robots_value ) . "\" />\n";
		}
	}
	
	/**
	 * Output Open Graph tags for social sharing.
	 *
	 * @return void
	 */
	public function output_open_graph_tags() {
		if ( is_admin() ) {
			return;
		}

		$options = $this->get_options();

		$title = wp_get_document_title();
		$desc  = $this->current_description;

		$post_id = $this->current_post_id;

		// URL.
		if ( $post_id && is_singular() && ! ( is_front_page() || is_home() ) ) {
			$url = get_permalink( $post_id );
		} else {
			$url = home_url( add_query_arg( array(), '/' ) );
		}

		// Type.
		$type = ( $post_id && is_singular() ) ? 'article' : 'website';

		// Image: featured image or default OG image.
		$image = '';
		if ( $post_id && has_post_thumbnail( $post_id ) ) {
			$img = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
			if ( $img ) {
				$image = $img[0];
			}
		}
		if ( ! $image && ! empty( $options['og_default_image'] ) ) {
			$image = $options['og_default_image'];
		}

		$site_name = get_bloginfo( 'name' );

		echo '<meta property="og:title" content="' . esc_attr( $title ) . "\" />\n";
		if ( $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . "\" />\n";
		}
		echo '<meta property="og:type" content="' . esc_attr( $type ) . "\" />\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . "\" />\n";
		if ( $site_name ) {
			echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . "\" />\n";
		}
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . "\" />\n";
		}
	}
	
	/**
	 * Output Twitter card meta tags.
	 *
	 * @return void
	 */
	public function output_twitter_tags() {
		if ( is_admin() ) {
			return;
		}

		$options = $this->get_options();

		$title = wp_get_document_title();
		$desc  = $this->current_description;
		$post_id = $this->current_post_id;

		// Image same as OG.
		$image = '';
		if ( $post_id && has_post_thumbnail( $post_id ) ) {
			$img = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
			if ( $img ) {
				$image = $img[0];
			}
		}
		if ( ! $image && ! empty( $options['og_default_image'] ) ) {
			$image = $options['og_default_image'];
		}

		$card_type = ! empty( $options['twitter_card_type'] ) ? $options['twitter_card_type'] : 'summary_large_image';
		$username  = ! empty( $options['twitter_username'] ) ? ltrim( $options['twitter_username'], '@' ) : '';

		echo '<meta name="twitter:card" content="' . esc_attr( $card_type ) . "\" />\n";

		if ( $username ) {
			echo '<meta name="twitter:site" content="@' . esc_attr( $username ) . "\" />\n";
		}

		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . "\" />\n";

		if ( $desc ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . "\" />\n";
		}

		if ( $image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . "\" />\n";
		}
	}	

}