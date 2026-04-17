<?php

declare(strict_types=1);

namespace Tickera\WalletPass;

final class Admin {

	private const OPTION_KEY = 'tc_apple_wallet_settings';

	public static function register(): void {
		\add_filter( 'tc_settings_new_menus', array( self::class, 'addMenu' ) );
		\add_action( 'tc_settings_menu_wallet', array( self::class, 'renderSettingsPage' ) );
	}

	public static function addMenu( array $menus ): array {
		$menus['wallet'] = \__( 'Apple Wallet Pass', 'tc' );
		return $menus;
	}

	public static function renderSettingsPage(): void {
		if ( \function_exists( 'wp_enqueue_media' ) ) {
			\wp_enqueue_media();
		}

		\wp_enqueue_style( 'wp-color-picker' );
		\wp_enqueue_script( 'wp-color-picker' );

		$settings = self::saveSettings();

		if ( $settings === null ) {
			$settings = self::getSettings();
		}

		?>
		<div class="wrap tc_wrap">
			<div id="poststuff">
				<form action="" method="post">
					<div class="postbox">
						<h3 class="hndle"><span><?php \esc_html_e( 'Apple Wallet Pass', 'tcawp' ); ?></span></h3>
						<div class="inside">
							<table class="form-table">
								<tbody>
									<?php if ( ! empty( $settings['icon_file'] ) ) : ?>
									<tr>
										<th scope="row">&nbsp;</th>
										<td><img src="<?php echo \esc_url( $settings['icon_file'] ); ?>" width="100" alt="" /></td>
									</tr>
									<?php endif; ?>
									<tr>
										<th scope="row"><label for="icon_file"><?php \esc_html_e( 'Icon File', 'tcawp' ); ?></label></th>
										<td>
											<input type="hidden" name="icon_file_id" id="icon_file_id" value="<?php echo \esc_attr( (string) $settings['icon_file_id'] ); ?>" />
											<input name="icon_file" type="text" id="icon_file" value="<?php echo \esc_attr( $settings['icon_file'] ); ?>" class="regular-text" />
											<input type="button" id="upload_icon" value="Choose File" class="button" />
											<p class="description"><?php \esc_html_e( 'Icon image URL', 'tcawp' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="logo_text"><?php \esc_html_e( 'Logo Text', 'tcawp' ); ?></label></th>
										<td>
											<input name="tc_apple_wallet[logo_text]" type="text" id="logo_text" value="<?php echo \esc_attr( $settings['logo_text'] ); ?>" class="regular-text" />
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="background_color"><?php \esc_html_e( 'Background Color', 'tcawp' ); ?></label></th>
										<td>
											<input name="tc_apple_wallet[background_color]" type="text" id="background_color" value="<?php echo \esc_attr( $settings['background_color'] ); ?>" class="tc-color-picker" data-default-color="#aaaaaa" />
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="organisation_name"><?php \esc_html_e( 'Organization Name', 'tcawp' ); ?></label></th>
										<td>
											<input name="tc_apple_wallet[organisation_name]" type="text" id="organisation_name" value="<?php echo \esc_attr( $settings['organisation_name'] ); ?>" class="regular-text" />
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<?php \wp_nonce_field( 'save_apple_wallet_settings', 'save_apple_wallet_settings_nonce' ); ?>
					<?php \submit_button(); ?>
				</form>
			</div>
			<br>
			<div style="margin-top:24px;padding:20px 24px;background:#fff;border-left:4px solid #FF6B00;border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,.1);display:flex;align-items:center;gap:20px;">
				<div style="flex-shrink:0;width:40px;height:40px;background:#FF6B00;border-radius:50%;display:flex;align-items:center;justify-content:center;">
					<span style="color:#fff;font-size:22px;font-weight:700;line-height:1;">&#9889;</span>
				</div>
				<div>
					<strong style="font-size:14px;color:#1d2327;"><?php \esc_html_e( 'Powered by CommerceBird — Premium Subscription Required', 'tcawp' ); ?></strong>
					<p style="margin:4px 0 0;color:#50575e;font-size:13px;">
						<?php
							\printf(
							/* translators: %s: CommerceBird link */
								\esc_html__( 'This Wallet Pass feature is powered by %s. A Premium subscription is required for passes to be generated and delivered to your customers.', 'tcawp' ),
								'<a href="https://commercebird.com" target="_blank" rel="noopener noreferrer" style="color:#FF6B00;font-weight:600;">CommerceBird</a>'
							);
						?>
					</p>
				</div>
			</div>
		</div>
		<script>
		jQuery(function($) {
			$('#upload_icon').on('click', function(e) {
				e.preventDefault();

				var frame = wp.media({
					title: 'Choose Image',
					button: { text: 'Choose Image' },
					library: { type: 'image' },
					multiple: false
				});

				frame.on('select', function() {
					var attachment = frame.state().get('selection').first().toJSON();
					$('#icon_file').val(attachment.url);
					$('#icon_file_id').val(attachment.id);
				});

				frame.open();
			});

			$('.tc-color-picker').wpColorPicker();
		});
		</script>
		<?php
	}

	private static function saveSettings(): ?array {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return null;
		}

		if (
			! isset( $_POST['save_apple_wallet_settings_nonce'] )
			|| ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['save_apple_wallet_settings_nonce'] ) ), 'save_apple_wallet_settings' )
		) {
			return null;
		}

		if ( ! isset( $_POST['tc_apple_wallet'] ) || ! is_array( $_POST['tc_apple_wallet'] ) ) {
			return self::getSettings();
		}

		$posted = \wp_unslash( $_POST['tc_apple_wallet'] );

		$settings = array(
			'logo_text'         => \sanitize_text_field( (string) ( $posted['logo_text'] ?? '' ) ),
			'background_color'  => \sanitize_text_field( (string) ( $posted['background_color'] ?? '#aaaaaa' ) ),
			'organisation_name' => \sanitize_text_field( (string) ( $posted['organisation_name'] ?? '' ) ),
			'icon_file'         => isset( $_POST['icon_file'] ) ? \esc_url_raw( (string) \wp_unslash( $_POST['icon_file'] ) ) : '',
			'icon_file_id'      => isset( $_POST['icon_file_id'] ) ? \absint( $_POST['icon_file_id'] ) : 0,
		);

		\update_option( self::OPTION_KEY, $settings );

		return self::getSettings();
	}

	public static function getSettings(): array {
		$settings = \get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array_merge(
			array(
				'icon_file'         => '',
				'icon_file_id'      => 0,
				'logo_text'         => '',
				'background_color'  => '#aaaaaa',
				'organisation_name' => '',
			),
			$settings
		);
	}
}
