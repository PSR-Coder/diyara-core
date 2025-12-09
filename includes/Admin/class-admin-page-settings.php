<?php
/**
 * Diyara Settings admin page.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Page_Settings
 */
class Admin_Page_Settings extends Admin_Page_Base {

	public function get_menu_slug() {
		return 'diyara-settings';
	}

	public function get_page_title() {
		return __( 'Diyara Settings', 'diyara-core' );
	}

	public function get_menu_title() {
		return __( 'Settings', 'diyara-core' );
	}

	public function render() {
		$this->render_header(
			__( 'Diyara Settings', 'diyara-core' ),
			__( 'Configure SEO defaults and AI providers.', 'diyara-core' )
		);

		// Show any settings API messages.
		settings_errors();
		?>

		<div class="diyara-settings-wrapper">

			<!-- Main Tabs -->
			<h2 class="nav-tab-wrapper diyara-settings-tabs">
				<a href="#diyara-tab-general" class="nav-tab nav-tab-active diyara-settings-tab" data-tab="general">
					<?php esc_html_e( 'General (SEO)', 'diyara-core' ); ?>
				</a>
				<a href="#diyara-tab-ai" class="nav-tab diyara-settings-tab" data-tab="ai">
					<?php esc_html_e( 'AI Providers', 'diyara-core' ); ?>
				</a>
			</h2>

			<div class="diyara-settings-panels">

				<!-- General / SEO Tab -->
				<div id="diyara-tab-general" class="diyara-settings-panel is-active">
					<form method="post" action="options.php">
						<?php
						// Same group used for SEO & AI.
						settings_fields( 'diyara_core_seo' );
						do_settings_sections( 'diyara-settings-general' );
						submit_button( __( 'Save SEO Settings', 'diyara-core' ) );
						?>
					</form>
				</div>

				<!-- AI Tab -->
				<div id="diyara-tab-ai" class="diyara-settings-panel" style="display:none;">
					<form method="post" action="options.php">
						<?php
						// Same group so AI fields still saved with diyara_core_seo.
						settings_fields( 'diyara_core_ai' );
						?>

						<div class="diyara-ai-settings-layout">
							<div class="diyara-ai-providers">
								<p><strong><?php esc_html_e( 'Providers', 'diyara-core' ); ?></strong></p>
								<button type="button" class="button button-secondary diyara-ai-provider-tab active" data-provider="gemini">
									<?php esc_html_e( 'Google Gemini', 'diyara-core' ); ?>
								</button>
								<button type="button" class="button button-secondary diyara-ai-provider-tab" data-provider="openai" disabled>
									<?php esc_html_e( 'OpenAI (future)', 'diyara-core' ); ?>
								</button>
								<button type="button" class="button button-secondary diyara-ai-provider-tab" data-provider="claude" disabled>
									<?php esc_html_e( 'Claude (future)', 'diyara-core' ); ?>
								</button>
								<button type="button" class="button button-secondary diyara-ai-provider-tab" data-provider="google-translate" disabled>
									<?php esc_html_e( 'Google Translate (future)', 'diyara-core' ); ?>
								</button>
							</div>

							<div class="diyara-ai-provider-fields">
								<?php
								// Renders active provider + its API key fields.
								do_settings_sections( 'diyara-settings-ai' );
								?>
							</div>
						</div>

						<?php submit_button( __( 'Save AI Settings', 'diyara-core' ) ); ?>
					</form>
				</div>
			</div>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function () {
			// Main tabs.
			const tabLinks = document.querySelectorAll('.diyara-settings-tab');
			const panels   = document.querySelectorAll('.diyara-settings-panel');

			function showSettingsTab(tab) {
				panels.forEach(function (panel) {
					if (panel.id === 'diyara-tab-' + tab) {
						panel.style.display = '';
						panel.classList.add('is-active');
					} else {
						panel.style.display = 'none';
						panel.classList.remove('is-active');
					}
				});

				tabLinks.forEach(function (link) {
					if (link.dataset.tab === tab) {
						link.classList.add('nav-tab-active');
					} else {
						link.classList.remove('nav-tab-active');
					}
				});
			}

			tabLinks.forEach(function (link) {
				link.addEventListener('click', function (e) {
					e.preventDefault();
					const tab = this.dataset.tab;
					showSettingsTab(tab);
				});
			});

			// Provider tabs in AI section.
			const providerTabs   = document.querySelectorAll('.diyara-ai-provider-tab');
			const providerPanels = document.querySelectorAll('.diyara-ai-provider-panel');

			function showProvider(provider) {
				providerPanels.forEach(function (panel) {
					if (panel.dataset.provider === provider) {
						panel.style.display = '';
						panel.classList.add('is-active');
					} else {
						panel.style.display = 'none';
						panel.classList.remove('is-active');
					}
				});

				providerTabs.forEach(function (btn) {
					if (btn.dataset.provider === provider) {
						btn.classList.add('active');
					} else {
						btn.classList.remove('active');
					}
				});
			}

			if (providerTabs.length > 0) {
				// Default to Gemini on load.
				showProvider('gemini');

				providerTabs.forEach(function (btn) {
					btn.addEventListener('click', function (e) {
						e.preventDefault();
						if (this.hasAttribute('disabled')) {
							return;
						}
						const provider = this.dataset.provider;
						showProvider(provider);
					});
				});
			}
		});
		</script>

		<style>
		.diyara-settings-wrapper {
			margin-top: 10px;
		}
		.diyara-ai-settings-layout {
			display: flex;
			gap: 20px;
			align-items: flex-start;
		}
		.diyara-ai-providers {
			min-width: 200px;
		}
		.diyara-ai-providers .button {
			display: block;
			width: 100%;
			margin-bottom: 5px;
			text-align: left;
		}
		.diyara-ai-providers .button.active {
			background-color: #2271b1;
			color: #fff;
		}
		.diyara-ai-provider-fields .diyara-ai-provider-panel {
			display: none;
		}
		.diyara-ai-provider-fields .diyara-ai-provider-panel.is-active {
			display: block;
		}
		</style>

		<?php
		$this->render_footer();
	}
}