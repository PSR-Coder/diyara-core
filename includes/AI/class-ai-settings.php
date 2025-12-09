<?php
/**
 * Global AI settings (provider + API keys).
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AI_Settings
 */
class AI_Settings {

	/**
	 * Singleton instance.
	 *
	 * @var AI_Settings|null
	 */
	protected static $instance = null;

	/**
	 * Option name.
	 *
	 * @var string
	 */
	protected $option_name = 'diyara_core_ai_options';

	/**
	 * Get instance.
	 *
	 * @return AI_Settings
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
		// Register fields in the same settings group as SEO so they save in the existing form.
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/* -------------------------------------------------------------------------
	 *  Options helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Get options with defaults.
	 *
	 * @return array
	 */
	public function get_options() {
		$defaults = array(
			'active_provider'     => 'gemini',
			'gemini_api_key'      => '',
			'openai_api_key'      => '',
			'claude_api_key'      => '',
			'google_translate_key'=> '',
		);

		$options = get_option( $this->option_name, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return wp_parse_args( $options, $defaults );
	}

	/**
	 * Get active provider slug (gemini, openai, claude, ...).
	 *
	 * @return string
	 */
	public function get_active_provider() {
		$options = $this->get_options();
		return isset( $options['active_provider'] ) ? $options['active_provider'] : 'gemini';
	}

	/**
	 * Get API key for a provider.
	 *
	 * @param string $provider Provider slug (gemini, openai, claude, google_translate).
	 * @return string
	 */
	public function get_api_key( $provider ) {
		$options = $this->get_options();
		switch ( strtolower( $provider ) ) {
			case 'gemini':
				return isset( $options['gemini_api_key'] ) ? $options['gemini_api_key'] : '';
			case 'openai':
				return isset( $options['openai_api_key'] ) ? $options['openai_api_key'] : '';
			case 'claude':
				return isset( $options['claude_api_key'] ) ? $options['claude_api_key'] : '';
			case 'google_translate':
				return isset( $options['google_translate_key'] ) ? $options['google_translate_key'] : '';
			default:
				return '';
		}
	}

	/**
	 * Sanitize options.
	 *
	 * @param array $input Raw.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$output = array();

		$allowed_providers = array( 'gemini', 'openai', 'claude' );
		$prov              = isset( $input['active_provider'] ) ? strtolower( $input['active_provider'] ) : 'gemini';
		$output['active_provider'] = in_array( $prov, $allowed_providers, true ) ? $prov : 'gemini';

		$output['gemini_api_key'] = isset( $input['gemini_api_key'] )
			? trim( sanitize_text_field( $input['gemini_api_key'] ) )
			: '';

		$output['openai_api_key'] = isset( $input['openai_api_key'] )
			? trim( sanitize_text_field( $input['openai_api_key'] ) )
			: '';

		$output['claude_api_key'] = isset( $input['claude_api_key'] )
			? trim( sanitize_text_field( $input['claude_api_key'] ) )
			: '';

		$output['google_translate_key'] = isset( $input['google_translate_key'] )
			? trim( sanitize_text_field( $input['google_translate_key'] ) )
			: '';

		return $output;
	}

	/* -------------------------------------------------------------------------
	 *  Settings API
	 * ---------------------------------------------------------------------- */

	/**
	 * Register settings and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		// Dedicated group for AI options.
		register_setting(
			'diyara_core_ai',          // ← separate group
			$this->option_name,        // e.g. diyara_core_ai_options
			array( $this, 'sanitize_options' )
		);

		add_settings_section(
			'diyara_core_ai_main',
			__( 'AI Settings', 'diyara-core' ),
			array( $this, 'render_section_intro' ),
			'diyara-settings-ai'       // page slug for AI tab
		);

		add_settings_field(
			'active_provider',
			__( 'Active provider', 'diyara-core' ),
			array( $this, 'field_active_provider' ),
			'diyara-settings-ai',
			'diyara_core_ai_main'
		);

		add_settings_field(
			'gemini_api_key',
			__( 'Google Gemini API key', 'diyara-core' ),
			array( $this, 'field_gemini_api_key' ),
			'diyara-settings-ai',
			'diyara_core_ai_main'
		);

		add_settings_field(
			'openai_api_key',
			__( 'OpenAI API key', 'diyara-core' ),
			array( $this, 'field_openai_api_key' ),
			'diyara-settings-ai',
			'diyara_core_ai_main'
		);

		add_settings_field(
			'claude_api_key',
			__( 'Anthropic Claude API key', 'diyara-core' ),
			array( $this, 'field_claude_api_key' ),
			'diyara-settings-ai',
			'diyara_core_ai_main'
		);

		add_settings_field(
			'google_translate_key',
			__( 'Google Translate API key', 'diyara-core' ),
			array( $this, 'field_google_translate_key' ),
			'diyara-settings-ai',
			'diyara_core_ai_main'
		);
	}

	/**
	 * Section intro.
	 *
	 * @return void
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Configure API keys for supported AI providers. Model, temperature, and max tokens are configured per campaign.', 'diyara-core' ) . '</p>';
	}

	/**
	 * Field: active provider.
	 *
	 * @return void
	 */
	public function field_active_provider() {
		$options  = $this->get_options();
		$active   = $options['active_provider'];
		?>
		<label>
			<input type="radio" name="<?php echo esc_attr( $this->option_name . '[active_provider]' ); ?>" value="gemini" <?php checked( $active, 'gemini' ); ?> />
			<?php esc_html_e( 'Google Gemini (current implementation)', 'diyara-core' ); ?>
		</label>
		<br />
		<label>
			<input type="radio" name="<?php echo esc_attr( $this->option_name . '[active_provider]' ); ?>" value="openai" <?php checked( $active, 'openai' ); ?> disabled />
			<?php esc_html_e( 'OpenAI (planned)', 'diyara-core' ); ?>
		</label>
		<br />
		<label>
			<input type="radio" name="<?php echo esc_attr( $this->option_name . '[active_provider]' ); ?>" value="claude" <?php checked( $active, 'claude' ); ?> disabled />
			<?php esc_html_e( 'Anthropic Claude (planned)', 'diyara-core' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Currently only Gemini is wired into the engine. Other providers are for future compatibility.', 'diyara-core' ); ?>
		</p>
		<?php
	}

	/**
	 * Field: Gemini API key.
	 *
	 * @return void
	 */
	public function field_gemini_api_key() {
			$options = $this->get_options();
			$value   = $options['gemini_api_key'];
			?>
			<div class="diyara-ai-provider-panel" data-provider="gemini">
					<input type="password"
								name="<?php echo esc_attr( $this->option_name . '[gemini_api_key]' ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
								class="regular-text" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Required for Gemini-based AI features (e.g., gemini-1.5-flash).', 'diyara-core' ); ?></p>
			</div>
			<?php
	}

	/**
	 * Field: OpenAI API key.
	 *
	 * @return void
	 */
	public function field_openai_api_key() {
			$options = $this->get_options();
			$value   = $options['openai_api_key'];
			?>
			<div class="diyara-ai-provider-panel" data-provider="openai">
					<input type="password"
								name="<?php echo esc_attr( $this->option_name . '[openai_api_key]' ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
								class="regular-text" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Reserved for future OpenAI integration.', 'diyara-core' ); ?></p>
			</div>
			<?php
	}

	/**
	 * Field: Claude API key.
	 *
	 * @return void
	 */
	public function field_claude_api_key() {
			$options = $this->get_options();
			$value   = $options['claude_api_key'];
			?>
			<div class="diyara-ai-provider-panel" data-provider="claude">
					<input type="password"
								name="<?php echo esc_attr( $this->option_name . '[claude_api_key]' ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
								class="regular-text" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Reserved for future Claude integration.', 'diyara-core' ); ?></p>
			</div>
			<?php
	}

	/**
	 * Field: Google Translate API key.
	 *
	 * @return void
	 */
	public function field_google_translate_key() {
			$options = $this->get_options();
			$value   = $options['google_translate_key'];
			?>
			<div class="diyara-ai-provider-panel" data-provider="google-translate">
					<input type="password"
								name="<?php echo esc_attr( $this->option_name . '[google_translate_key]' ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
								class="regular-text" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Reserved for translator spin / translation features.', 'diyara-core' ); ?></p>
			</div>
			<?php
	}
}