<?php
/**
 * Campaigns: configuration for auto-blogging.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Campaigns
 *
 * Extended to mirror your web app Campaign model:
 * - Source URL + type (RSS/DIRECT)
 * - Filtering (start date, URL keywords)
 * - Processing mode (AS_IS, AI_REWRITE, AI_URL_DIRECT, TRANSLATOR_SPIN)
 * - AI model, prompt type, custom prompt, word counts
 * - Tone, audience, brand voice, language
 * - Scheduling (days, hours, interval, batch size, delay)
 * - Limits (max posts), status (active/paused)
 * - Target category, post status, SEO plugin
 */
class Campaigns {

	/**
	 * Singleton.
	 *
	 * @var Campaigns|null
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Campaigns
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_diyara_campaign', array( $this, 'save_campaign_meta' ) );
	}

	/**
	 * Register custom post type diyara_campaign.
	 *
	 * @return void
	 */
	public function register_cpt() {
		$labels = array(
			'name'               => __( 'Campaigns', 'diyara-core' ),
			'singular_name'      => __( 'Campaign', 'diyara-core' ),
			'add_new'            => __( 'Add New', 'diyara-core' ),
			'add_new_item'       => __( 'Add New Campaign', 'diyara-core' ),
			'edit_item'          => __( 'Edit Campaign', 'diyara-core' ),
			'new_item'           => __( 'New Campaign', 'diyara-core' ),
			'view_item'          => __( 'View Campaign', 'diyara-core' ),
			'search_items'       => __( 'Search Campaigns', 'diyara-core' ),
			'not_found'          => __( 'No campaigns found.', 'diyara-core' ),
			'not_found_in_trash' => __( 'No campaigns found in Trash.', 'diyara-core' ),
			'menu_name'          => __( 'Campaigns', 'diyara-core' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'supports'           => array( 'title' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
		);

		register_post_type( 'diyara_campaign', $args );
	}

	/**
	 * Register meta boxes for campaigns.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'diyara_campaign_source_target',
			__( 'Source & Target', 'diyara-core' ),
			array( $this, 'render_source_target_meta_box' ),
			'diyara_campaign',
			'normal',
			'high'
		);

		add_meta_box(
			'diyara_campaign_processing',
			__( 'Processing & AI', 'diyara-core' ),
			array( $this, 'render_processing_meta_box' ),
			'diyara_campaign',
			'normal',
			'default'
		);

		add_meta_box(
			'diyara_campaign_scheduling',
			__( 'Scheduling & Limits', 'diyara-core' ),
			array( $this, 'render_scheduling_meta_box' ),
			'diyara_campaign',
			'normal',
			'default'
		);

		add_meta_box(
			'diyara_campaign_actions',
			__( 'AI Actions', 'diyara-core' ),
			array( $this, 'render_actions_meta_box' ),
			'diyara_campaign',
			'side',
			'high'
		);
	}

	/* -------------------------------------------------------------------------
	 *  Helpers to read meta with defaults
	 * ---------------------------------------------------------------------- */

	protected function get_meta( $post_id, $key, $default = '' ) {
		$val = get_post_meta( $post_id, '_diyara_campaign_' . $key, true );
		return ( '' === $val || null === $val ) ? $default : $val;
	}

	protected function get_meta_array( $post_id, $key, $default = array() ) {
		$val = get_post_meta( $post_id, '_diyara_campaign_' . $key, true );
		if ( empty( $val ) || ! is_array( $val ) ) {
			return $default;
		}
		return $val;
	}

	/* -------------------------------------------------------------------------
	 *  Meta box renderers
	 * ---------------------------------------------------------------------- */

	public function render_source_target_meta_box( $post ) {
		wp_nonce_field( 'diyara_campaign_save', 'diyara_campaign_nonce' );

		$source_url     = $this->get_meta( $post->ID, 'source_url', '' );
		$source_type    = $this->get_meta( $post->ID, 'source_type', 'RSS' );
		$start_date_raw = $this->get_meta( $post->ID, 'start_date', '' );
		$url_keywords   = $this->get_meta( $post->ID, 'url_keywords', '' );
		$target_cat_id  = (int) $this->get_meta( $post->ID, 'target_category_id', 0 );
		$post_status    = $this->get_meta( $post->ID, 'post_status', 'draft' );
		$seo_plugin     = $this->get_meta( $post->ID, 'seo_plugin', 'none' );
		$status         = $this->get_meta( $post->ID, 'status', 'active' );

		$start_value = '';
		if ( ! empty( $start_date_raw ) ) {
			$ts = strtotime( $start_date_raw );
			if ( $ts ) {
				$start_value = date( 'Y-m-d\TH:i', $ts );
			}
		}

		$categories = get_categories(
			array(
				'hide_empty' => false,
			)
		);
		?>
		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Source type', 'diyara-core' ); ?></th>
				<td>
					<label>
						<input type="radio" name="diyara_source_type" value="RSS" <?php checked( $source_type, 'RSS' ); ?> />
						<?php esc_html_e( 'RSS feed', 'diyara-core' ); ?>
					</label>
					&nbsp;&nbsp;
					<label>
						<input type="radio" name="diyara_source_type" value="DIRECT" <?php checked( $source_type, 'DIRECT' ); ?> />
						<?php esc_html_e( 'Website (sitemap discovery)', 'diyara-core' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'RSS: use a feed URL (e.g. https://site.com/feed). DIRECT: use a site URL, Diyara will discover posts from sitemaps.', 'diyara-core' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="diyara_source_url"><?php esc_html_e( 'Source URL', 'diyara-core' ); ?></label>
				</th>
				<td>
					<input type="text" id="diyara_source_url" name="diyara_source_url" class="regular-text"
					       value="<?php echo esc_attr( $source_url ); ?>" />
					<p class="description">
						<?php esc_html_e( 'RSS feed URL or site homepage from which to discover articles.', 'diyara-core' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="diyara_start_date"><?php esc_html_e( 'Start processing from', 'diyara-core' ); ?></label>
				</th>
				<td>
					<input type="datetime-local" id="diyara_start_date" name="diyara_start_date"
					       value="<?php echo esc_attr( $start_value ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Only process posts/items published after this date/time.', 'diyara-core' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="diyara_url_keywords"><?php esc_html_e( 'URL keyword filter (optional)', 'diyara-core' ); ?></label>
				</th>
				<td>
					<input type="text" id="diyara_url_keywords" name="diyara_url_keywords" class="regular-text"
					       value="<?php echo esc_attr( $url_keywords ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Comma-separated. Only URLs that contain at least one of these keywords will be processed.', 'diyara-core' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="diyara_target_category_id"><?php esc_html_e( 'Target category', 'diyara-core' ); ?></label>
				</th>
				<td>
					<select id="diyara_target_category_id" name="diyara_target_category_id">
						<option value="0"><?php esc_html_e( '— None —', 'diyara-core' ); ?></option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $target_cat_id, (int) $cat->term_id ); ?>>
								<?php echo esc_html( $cat->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'New AI-generated posts will be assigned to this category (if selected).', 'diyara-core' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="diyara_post_status"><?php esc_html_e( 'Post status for new posts', 'diyara-core' ); ?></label>
				</th>
				<td>
					<select id="diyara_post_status" name="diyara_post_status">
						<option value="draft" <?php selected( $post_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'diyara-core' ); ?></option>
						<option value="publish" <?php selected( $post_status, 'publish' ); ?>><?php esc_html_e( 'Publish immediately', 'diyara-core' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="diyara_seo_plugin"><?php esc_html_e( 'SEO plugin compatibility', 'diyara-core' ); ?></label>
				</th>
				<td>
					<select id="diyara_seo_plugin" name="diyara_seo_plugin">
						<option value="none" <?php selected( $seo_plugin, 'none' ); ?>><?php esc_html_e( 'No external plugin (use Diyara SEO)', 'diyara-core' ); ?></option>
						<option value="yoast" <?php selected( $seo_plugin, 'yoast' ); ?>><?php esc_html_e( 'Yoast SEO', 'diyara-core' ); ?></option>
						<option value="rank_math" <?php selected( $seo_plugin, 'rank_math' ); ?>><?php esc_html_e( 'Rank Math', 'diyara-core' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'If Yoast or Rank Math are active, AI SEO data can be written into their meta fields as well.', 'diyara-core' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Campaign status', 'diyara-core' ); ?></th>
				<td>
					<select name="diyara_status">
						<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active (eligible for cron runs)', 'diyara-core' ); ?></option>
						<option value="paused" <?php selected( $status, 'paused' ); ?>><?php esc_html_e( 'Paused', 'diyara-core' ); ?></option>
					</select>
				</td>
			</tr>
			</tbody>
		</table>
		<?php
	}

	public function render_processing_meta_box( $post ) {
		$processing_mode = $this->get_meta( $post->ID, 'processing_mode', 'AS_IS' );
		$ai_model        = $this->get_meta( $post->ID, 'ai_model', 'gemini-2.5-flash' );
		$prompt_type     = $this->get_meta( $post->ID, 'prompt_type', 'default' );
		$custom_prompt   = $this->get_meta( $post->ID, 'custom_prompt', '' );
		$min_words       = (int) $this->get_meta( $post->ID, 'min_word_count', 600 );
		$max_words       = (int) $this->get_meta( $post->ID, 'max_word_count', 1000 );
		$ai_temperature  = (float) $this->get_meta( $post->ID, 'ai_temperature', 0.7 );
		$ai_max_tokens   = (int) $this->get_meta( $post->ID, 'ai_max_tokens', 2048 );

		$tone        = $this->get_meta( $post->ID, 'tone', 'enthusiastic and conversational' );
		$audience    = $this->get_meta( $post->ID, 'audience', 'general online readers' );
		$brand_voice = $this->get_meta( $post->ID, 'brand_voice', '' );
		$language    = $this->get_meta( $post->ID, 'language', 'English' );

		$max_headings        = (int) $this->get_meta( $post->ID, 'max_headings', 1 );
		$match_length        = (bool) $this->get_meta( $post->ID, 'match_source_length', '' );
		$match_headings      = (bool) $this->get_meta( $post->ID, 'match_source_headings', '' );
		$match_tone          = (bool) $this->get_meta( $post->ID, 'match_source_tone', '' );
		$match_brand_voice   = (bool) $this->get_meta( $post->ID, 'match_source_brand_voice', '' );
		$rewrite_mode = $this->get_meta( $post->ID, 'rewrite_mode', 'normal' ); // loose|normal|strict

		?>
		<p><strong><?php esc_html_e( 'Processing mode', 'diyara-core' ); ?></strong></p>
		<p>
			<label>
				<input type="radio" name="diyara_processing_mode" value="AS_IS" <?php checked( $processing_mode, 'AS_IS' ); ?> />
				<?php esc_html_e( 'As is (no AI rewrite)', 'diyara-core' ); ?>
			</label><br />
			<label>
				<input type="radio" name="diyara_processing_mode" value="AI_REWRITE" <?php checked( $processing_mode, 'AI_REWRITE' ); ?> />
				<?php esc_html_e( 'AI Rewrite (scrape & rewrite content)', 'diyara-core' ); ?>
			</label><br />
			<label>
				<input type="radio" name="diyara_processing_mode" value="AI_URL_DIRECT" <?php checked( $processing_mode, 'AI_URL_DIRECT' ); ?> />
				<?php esc_html_e( 'AI URL (treat source URL as primary input)', 'diyara-core' ); ?>
			</label><br />
			<label>
				<input type="radio" name="diyara_processing_mode" value="TRANSLATOR_SPIN" <?php checked( $processing_mode, 'TRANSLATOR_SPIN' ); ?> />
				<?php esc_html_e( 'Translator spin (future)', 'diyara-core' ); ?>
			</label>
		</p>

		<hr />

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="diyara_ai_model"><?php esc_html_e( 'AI model', 'diyara-core' ); ?></label>
					</th>
					<td>
						<select id="diyara_ai_model" name="diyara_ai_model">
							<optgroup label="<?php esc_attr_e( 'Google Gemini', 'diyara-core' ); ?>">
								<option value="gemini-2.5-flash" <?php selected( $ai_model, 'gemini-2.5-flash' ); ?>>gemini-2.5-flash</option>
								<option value="gemini-1.5-pro" <?php selected( $ai_model, 'gemini-1.5-pro' ); ?>>gemini-1.5-pro</option>
							</optgroup>
							<optgroup label="<?php esc_attr_e( 'OpenAI (future)', 'diyara-core' ); ?>">
								<option value="gpt-4o" <?php selected( $ai_model, 'gpt-4o' ); ?>>gpt-4o</option>
								<option value="gpt-4-turbo" <?php selected( $ai_model, 'gpt-4-turbo' ); ?>>gpt-4-turbo</option>
								<option value="gpt-3.5-turbo" <?php selected( $ai_model, 'gpt-3.5-turbo' ); ?>>gpt-3.5-turbo</option>
							</optgroup>
							<optgroup label="<?php esc_attr_e( 'Anthropic Claude (future)', 'diyara-core' ); ?>">
								<option value="claude-3-opus-20240229" <?php selected( $ai_model, 'claude-3-opus-20240229' ); ?>>claude-3-opus-20240229</option>
								<option value="claude-3-sonnet-20240229" <?php selected( $ai_model, 'claude-3-sonnet-20240229' ); ?>>claude-3-sonnet-20240229</option>
								<option value="claude-3-haiku-20240307" <?php selected( $ai_model, 'claude-3-haiku-20240307' ); ?>>claude-3-haiku-20240307</option>
							</optgroup>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="diyara_rewrite_mode"><?php esc_html_e( 'Rewrite mode', 'diyara-core' ); ?></label>
					</th>
					<td>
						<select id="diyara_rewrite_mode" name="diyara_rewrite_mode">
							<option value="loose" <?php selected( $rewrite_mode, 'loose' ); ?>>
								<?php esc_html_e( 'Loose (more creative)', 'diyara-core' ); ?>
							</option>
							<option value="normal" <?php selected( $rewrite_mode, 'normal' ); ?>>
								<?php esc_html_e( 'Normal (balanced)', 'diyara-core' ); ?>
							</option>
							<option value="strict" <?php selected( $rewrite_mode, 'strict' ); ?>>
								<?php esc_html_e( 'Strict (close to source)', 'diyara-core' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Loose: more freedom. Normal: balanced. Strict: keep close to source article length, tone, and structure (best for news mirroring).', 'diyara-core' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Prompt strategy', 'diyara-core' ); ?></th>
					<td>
						<label>
							<input type="radio" name="diyara_prompt_type" value="default" <?php checked( $prompt_type, 'default' ); ?> />
							<?php esc_html_e( 'Default best-practice prompt (recommended)', 'diyara-core' ); ?>
						</label><br />
						<label>
							<input type="radio" name="diyara_prompt_type" value="custom" <?php checked( $prompt_type, 'custom' ); ?> />
							<?php esc_html_e( 'Custom system prompt', 'diyara-core' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'For custom prompts you can use placeholders like {{SOURCE_TITLE}}, {{SOURCE_CONTENT}} or {{SOURCE_URL}}. Diyara will still append JSON-output and SEO instructions.', 'diyara-core' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="diyara_custom_prompt"><?php esc_html_e( 'Custom prompt template', 'diyara-core' ); ?></label>
					</th>
					<td>
						<textarea id="diyara_custom_prompt" name="diyara_custom_prompt" rows="6" class="large-text code"><?php echo esc_textarea( $custom_prompt ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Only used when “Custom system prompt” is selected. Leave blank to use the default built-in prompt.', 'diyara-core' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="diyara_min_word_count"><?php esc_html_e( 'Minimum word count', 'diyara-core' ); ?></label></th>
					<td><input type="number" id="diyara_min_word_count" name="diyara_min_word_count" min="50" value="<?php echo esc_attr( $min_words ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="diyara_max_word_count"><?php esc_html_e( 'Maximum word count', 'diyara-core' ); ?></label></th>
					<td>
						<input type="number" id="diyara_max_word_count" name="diyara_max_word_count" min="50" value="<?php echo esc_attr( $max_words ); ?>" />
						<label style="margin-left:10px;">
							<input type="checkbox" name="diyara_match_source_length" value="1" <?php checked( $match_length, true ); ?> />
							<?php esc_html_e( 'Match source article length (± ~30 words)', 'diyara-core' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'If checked, the model will aim for the same length as the source instead of the fixed min/max.', 'diyara-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="diyara_max_headings"><?php esc_html_e( 'Max H2/H3 headings', 'diyara-core' ); ?></label>
					</th>
					<td>
						<input type="number" id="diyara_max_headings" name="diyara_max_headings" min="0" max="10"
									value="<?php echo esc_attr( $max_headings ); ?>" />
						<label style="margin-left:10px;">
							<input type="checkbox" name="diyara_match_source_headings" value="1" <?php checked( $match_headings, true ); ?> />
							<?php esc_html_e( 'Match source heading count', 'diyara-core' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'For short 200–300 word news (like Gulte/Tupaki), set Max headings = 0 or match the source (which often has 0 headings).', 'diyara-core' ); ?>
						</p>
					</td>
				</tr>		
				<tr>
					<th scope="row"><label for="diyara_ai_temperature"><?php esc_html_e( 'AI temperature', 'diyara-core' ); ?></label></th>
					<td>
						<input type="number" step="0.01" min="0" max="1" id="diyara_ai_temperature" name="diyara_ai_temperature" value="<?php echo esc_attr( $ai_temperature ); ?>" />
						<p class="description"><?php esc_html_e( 'Controls randomness. 0 = deterministic, 1 = very creative. Recommended 0.5–0.9.', 'diyara-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="diyara_ai_max_tokens"><?php esc_html_e( 'Max tokens per response', 'diyara-core' ); ?></label></th>
					<td>
						<input type="number" min="128" id="diyara_ai_max_tokens" name="diyara_ai_max_tokens" value="<?php echo esc_attr( $ai_max_tokens ); ?>" />
						<p class="description"><?php esc_html_e( 'Upper limit on tokens returned by the model. For JSON mode we normally rely on length + word count; keep this generous.', 'diyara-core' ); ?></p>
					</td>
				</tr>
				<tr><th colspan="2"><hr /><strong><?php esc_html_e( 'Tone & voice', 'diyara-core' ); ?></strong></th></tr>
				<tr>
					<th scope="row"><label for="diyara_tone"><?php esc_html_e( 'Tone', 'diyara-core' ); ?></label></th>
					<td>
						<input type="text" id="diyara_tone" name="diyara_tone" class="regular-text" value="<?php echo esc_attr( $tone ); ?>" />
						<label style="margin-left:10px;">
							<input type="checkbox" name="diyara_match_source_tone" value="1" <?php checked( $match_tone, true ); ?> />
							<?php esc_html_e( 'Match source tone', 'diyara-core' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'If checked, the AI will keep the tone/feel of the source article as closely as possible, instead of forcing this text.', 'diyara-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="diyara_brand_voice"><?php esc_html_e( 'Brand voice notes', 'diyara-core' ); ?></label></th>
					<td>
						<textarea id="diyara_brand_voice" name="diyara_brand_voice" rows="3" class="large-text"><?php echo esc_textarea( $brand_voice ); ?></textarea>
						<label style="margin-left:10px;">
							<input type="checkbox" name="diyara_match_source_brand_voice" value="1" <?php checked( $match_brand_voice, true ); ?> />
							<?php esc_html_e( 'Match source writing style', 'diyara-core' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'If checked, AI will try to keep the writing style similar to the source rather than a global brand voice.', 'diyara-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="diyara_audience"><?php esc_html_e( 'Audience', 'diyara-core' ); ?></label></th>
					<td>
						<input type="text" id="diyara_audience" name="diyara_audience" class="regular-text" value="<?php echo esc_attr( $audience ); ?>" />
						<p class="description"><?php esc_html_e( 'e.g., “movie fans in India”, “deal hunters in the US”, “tech-savvy Android users”.', 'diyara-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="diyara_language"><?php esc_html_e( 'Language', 'diyara-core' ); ?></label></th>
					<td>
						<input type="text" id="diyara_language" name="diyara_language" class="regular-text" value="<?php echo esc_attr( $language ); ?>" />
						<p class="description"><?php esc_html_e( 'Language to write in, e.g., “English”, “Hindi”, “Telugu”.', 'diyara-core' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public function render_scheduling_meta_box( $post ) {
		$schedule_days   = $this->get_meta_array( $post->ID, 'schedule_days', array( 1, 2, 3, 4, 5 ) );
		$start_hour      = (int) $this->get_meta( $post->ID, 'schedule_start_hour', 10 );
		$end_hour        = (int) $this->get_meta( $post->ID, 'schedule_end_hour', 22 );
		$min_interval    = (int) $this->get_meta( $post->ID, 'min_interval_minutes', 60 );
		$batch_size      = (int) $this->get_meta( $post->ID, 'batch_size', 5 );
		$delay_seconds   = (int) $this->get_meta( $post->ID, 'delay_seconds', 10 );
		$max_posts_limit = (int) $this->get_meta( $post->ID, 'max_posts_limit', 5000 );
		$last_run_at     = $this->get_meta( $post->ID, 'last_run_at', '' );

		$days_labels = array(
			0 => __( 'Sun', 'diyara-core' ),
			1 => __( 'Mon', 'diyara-core' ),
			2 => __( 'Tue', 'diyara-core' ),
			3 => __( 'Wed', 'diyara-core' ),
			4 => __( 'Thu', 'diyara-core' ),
			5 => __( 'Fri', 'diyara-core' ),
			6 => __( 'Sat', 'diyara-core' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Active days', 'diyara-core' ); ?></th>
				<td>
					<?php foreach ( $days_labels as $index => $label ) : ?>
						<label style="margin-right:8px; display:inline-block;">
							<input type="checkbox" name="diyara_schedule_days[]" value="<?php echo esc_attr( $index ); ?>" <?php checked( in_array( $index, $schedule_days, true ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'WP-Cron will only run this campaign on the selected days of the week.', 'diyara-core' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Active hours (0–23)', 'diyara-core' ); ?></th>
				<td>
					<label>
						<?php esc_html_e( 'Start hour', 'diyara-core' ); ?>
						<input type="number" name="diyara_schedule_start_hour" min="0" max="23" value="<?php echo esc_attr( $start_hour ); ?>" style="width:80px;" />
					</label>
					&nbsp;&nbsp;
					<label>
						<?php esc_html_e( 'End hour', 'diyara-core' ); ?>
						<input type="number" name="diyara_schedule_end_hour" min="0" max="23" value="<?php echo esc_attr( $end_hour ); ?>" style="width:80px;" />
					</label>
					<p class="description"><?php esc_html_e( 'Campaign will only run between these hours (server time).', 'diyara-core' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="diyara_min_interval_minutes"><?php esc_html_e( 'Minimum interval (minutes)', 'diyara-core' ); ?></label></th>
				<td>
					<input type="number" id="diyara_min_interval_minutes" name="diyara_min_interval_minutes" min="5" value="<?php echo esc_attr( $min_interval ); ?>" />
					<p class="description"><?php esc_html_e( 'Minimum time between automatic runs for this campaign.', 'diyara-core' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="diyara_batch_size"><?php esc_html_e( 'Posts per run (batch size)', 'diyara-core' ); ?></label></th>
				<td>
					<input type="number" id="diyara_batch_size" name="diyara_batch_size" min="1" max="20" value="<?php echo esc_attr( $batch_size ); ?>" />
					<p class="description"><?php esc_html_e( 'Maximum number of posts to process in a single cron run.', 'diyara-core' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="diyara_delay_seconds"><?php esc_html_e( 'Delay between posts (seconds)', 'diyara-core' ); ?></label></th>
				<td>
					<input type="number" id="diyara_delay_seconds" name="diyara_delay_seconds" min="0" value="<?php echo esc_attr( $delay_seconds ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional small pause between generating individual posts to reduce API bursts.', 'diyara-core' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="diyara_max_posts_limit"><?php esc_html_e( 'Maximum posts limit', 'diyara-core' ); ?></label></th>
				<td>
					<input type="number" id="diyara_max_posts_limit" name="diyara_max_posts_limit" min="0" value="<?php echo esc_attr( $max_posts_limit ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional hard cap on total successful posts for this campaign (0 for unlimited).', 'diyara-core' ); ?></p>
				</td>
			</tr>

			<?php if ( ! empty( $last_run_at ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last run at', 'diyara-core' ); ?></th>
					<td><p><?php echo esc_html( $last_run_at ); ?></p></td>
				</tr>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_actions_meta_box( $post ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=diyara_run_campaign&campaign_id=' . $post->ID ),
			'diyara_run_campaign_' . $post->ID
		);
		?>
		<p><?php esc_html_e( 'Use this button to generate a post immediately using the current campaign settings.', 'diyara-core' ); ?></p>
		<p><a href="<?php echo esc_url( $url ); ?>" class="button button-primary"><?php esc_html_e( 'Generate 1 post now', 'diyara-core' ); ?></a></p>
		<?php
	}

	/* -------------------------------------------------------------------------
	 *  Save meta
	 * ---------------------------------------------------------------------- */

	public function save_campaign_meta( $post_id ) {
		if ( ! isset( $_POST['diyara_campaign_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['diyara_campaign_nonce'] ), 'diyara_campaign_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Text fields.
		$text_fields = array(
			'source_url',
			'source_type',
			'url_keywords',
			'processing_mode',
			'ai_model',
			'prompt_type',
			'custom_prompt',
			'post_status',
			'seo_plugin',
			'status',
			'tone',
			'audience',
			'brand_voice',
			'language',
			'rewrite_mode',
		);

		foreach ( $text_fields as $field ) {
			$key   = '_diyara_campaign_' . $field;
			$value = isset( $_POST[ 'diyara_' . $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'diyara_' . $field ] ) ) : '';
			if ( '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}

		// Start date.
		if ( isset( $_POST['diyara_start_date'] ) && '' !== $_POST['diyara_start_date'] ) {
			$raw = sanitize_text_field( wp_unslash( $_POST['diyara_start_date'] ) );
			$ts  = strtotime( $raw );
			if ( $ts ) {
				$iso = gmdate( 'Y-m-d H:i:s', $ts );
				update_post_meta( $post_id, '_diyara_campaign_start_date', $iso );
			}
		} else {
			delete_post_meta( $post_id, '_diyara_campaign_start_date' );
		}

		// Integers.
		$int_fields = array(
			'min_word_count',
			'max_word_count',
			'schedule_start_hour',
			'schedule_end_hour',
			'min_interval_minutes',
			'batch_size',
			'delay_seconds',
			'max_posts_limit',
			'target_category_id',
			'ai_max_tokens',
			'max_headings',
		);

		// Boolean flags (checkboxes).
		$bool_flags = array(
			'match_source_length',
			'match_source_headings',
			'match_source_tone',
			'match_source_brand_voice',
		);

		foreach ( $bool_flags as $flag ) {
			$key = '_diyara_campaign_' . $flag;
			$posted_name = 'diyara_' . $flag;
			$value = isset( $_POST[ $posted_name ] ) ? '1' : '';
			if ( $value ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}

		foreach ( $int_fields as $field ) {
			$key   = '_diyara_campaign_' . $field;
			$value = isset( $_POST[ 'diyara_' . $field ] ) ? intval( $_POST[ 'diyara_' . $field ] ) : 0;
			if ( $value || 'max_posts_limit' === $field ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}

		// Float field: ai_temperature.
		if ( isset( $_POST['diyara_ai_temperature'] ) && '' !== $_POST['diyara_ai_temperature'] ) {
			$temp = (float) wp_unslash( $_POST['diyara_ai_temperature'] );
			if ( $temp < 0 ) {
				$temp = 0;
			}
			if ( $temp > 1 ) {
				$temp = 1;
			}
			update_post_meta( $post_id, '_diyara_campaign_ai_temperature', $temp );
		} else {
			delete_post_meta( $post_id, '_diyara_campaign_ai_temperature' );
		}

		// Schedule days.
		$schedule_days = array();
		if ( isset( $_POST['diyara_schedule_days'] ) && is_array( $_POST['diyara_schedule_days'] ) ) {
			foreach ( $_POST['diyara_schedule_days'] as $day ) {
				$day = (int) $day;
				if ( $day >= 0 && $day <= 6 ) {
					$schedule_days[] = $day;
				}
			}
			$schedule_days = array_values( array_unique( $schedule_days ) );
		}
		if ( ! empty( $schedule_days ) ) {
			update_post_meta( $post_id, '_diyara_campaign_schedule_days', $schedule_days );
		} else {
			delete_post_meta( $post_id, '_diyara_campaign_schedule_days' );
		}
	}
}