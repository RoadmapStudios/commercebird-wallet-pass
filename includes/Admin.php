<?php

declare(strict_types=1);

namespace Tickera\WalletPass;

final class Admin
{
    private const OPTION_KEY = 'tc_apple_wallet_settings';

    public static function register(): void
    {
        \add_filter('tc_settings_new_menus', [self::class, 'addMenu']);
        \add_action('tc_settings_menu_wallet', [self::class, 'renderSettingsPage']);
    }

    public static function addMenu(array $menus): array
    {
        $menus['wallet'] = \__('Apple Wallet Pass', 'tc');
        return $menus;
    }

    public static function renderSettingsPage(): void
    {
        if (\function_exists('wp_enqueue_media')) {
            \wp_enqueue_media();
        }

        $settings = self::saveSettings();

        if ($settings === null) {
            $settings = self::getSettings();
        }

        ?>
        <div class="wrap tc_wrap">
            <div id="poststuff">
                <form action="" method="post">
                    <div class="postbox">
                        <h3 class="hndle"><span><?php \esc_html_e('Apple Wallet Pass', 'tcawp'); ?></span></h3>
                        <div class="inside">
                            <table class="form-table">
                                <tbody>
                                    <tr>
                                        <th scope="row"><label for="qr_code_type"><?php \esc_html_e('QR Code type', 'tcawp'); ?></label></th>
                                        <td>
                                            <select name="tc_apple_wallet[qr_code_type]" id="qr_code_type">
                                                <option value="PKBarcodeFormatQR" <?php \selected($settings['qr_code_type'], 'PKBarcodeFormatQR'); ?>>PKBarcodeFormatQR</option>
                                                <option value="PKBarcodeFormatPDF417" <?php \selected($settings['qr_code_type'], 'PKBarcodeFormatPDF417'); ?>>PKBarcodeFormatPDF417</option>
                                                <option value="PKBarcodeFormatAztec" <?php \selected($settings['qr_code_type'], 'PKBarcodeFormatAztec'); ?>>PKBarcodeFormatAztec</option>
                                                <option value="PKBarcodeFormatCode128" <?php \selected($settings['qr_code_type'], 'PKBarcodeFormatCode128'); ?>>PKBarcodeFormatCode128</option>
                                            </select>
                                            <p class="description"><?php \esc_html_e('QR code type', 'tcawp'); ?></p>
                                        </td>
                                    </tr>
                                    <?php if (!empty($settings['icon_file'])) : ?>
                                    <tr>
                                        <th scope="row">&nbsp;</th>
                                        <td><img src="<?php echo \esc_url($settings['icon_file']); ?>" width="100" alt="" /></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th scope="row"><label for="icon_file"><?php \esc_html_e('Icon File', 'tcawp'); ?></label></th>
                                        <td>
                                            <input type="hidden" name="icon_file_id" id="icon_file_id" value="<?php echo \esc_attr((string) $settings['icon_file_id']); ?>" />
                                            <input name="icon_file" type="text" id="icon_file" value="<?php echo \esc_attr($settings['icon_file']); ?>" class="regular-text" />
                                            <input type="button" id="upload_icon" value="Choose File" class="button" />
                                            <p class="description"><?php \esc_html_e('Icon image URL', 'tcawp'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="logo_text"><?php \esc_html_e('Logo Text', 'tcawp'); ?></label></th>
                                        <td>
                                            <input name="tc_apple_wallet[logo_text]" type="text" id="logo_text" value="<?php echo \esc_attr($settings['logo_text']); ?>" class="regular-text" />
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="background_color"><?php \esc_html_e('Background Color', 'tcawp'); ?></label></th>
                                        <td>
                                            <input name="tc_apple_wallet[background_color]" type="text" id="background_color" value="<?php echo \esc_attr($settings['background_color']); ?>" class="regular-text" />
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="organisation_name"><?php \esc_html_e('Organization Name', 'tcawp'); ?></label></th>
                                        <td>
                                            <input name="tc_apple_wallet[organisation_name]" type="text" id="organisation_name" value="<?php echo \esc_attr($settings['organisation_name']); ?>" class="regular-text" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php \wp_nonce_field('save_apple_wallet_settings', 'save_apple_wallet_settings_nonce'); ?>
                    <?php \submit_button(); ?>
                </form>
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
        });
        </script>
        <?php
    }

    private static function saveSettings(): ?array
    {
        if (!\current_user_can('manage_options')) {
            return null;
        }

        if (
            !isset($_POST['save_apple_wallet_settings_nonce'])
            || !\wp_verify_nonce(\sanitize_text_field(\wp_unslash($_POST['save_apple_wallet_settings_nonce'])), 'save_apple_wallet_settings')
        ) {
            return null;
        }

        if (!isset($_POST['tc_apple_wallet']) || !is_array($_POST['tc_apple_wallet'])) {
            return self::getSettings();
        }

        $posted = \wp_unslash($_POST['tc_apple_wallet']);

        $settings = [
            'qr_code_type'     => \sanitize_text_field((string) ($posted['qr_code_type'] ?? 'PKBarcodeFormatQR')),
            'logo_text'        => \sanitize_text_field((string) ($posted['logo_text'] ?? '')),
            'background_color' => \sanitize_text_field((string) ($posted['background_color'] ?? '#aaaaaa')),
            'organisation_name' => \sanitize_text_field((string) ($posted['organisation_name'] ?? '')),
            'icon_file'        => isset($_POST['icon_file']) ? \esc_url_raw((string) \wp_unslash($_POST['icon_file'])) : '',
            'icon_file_id'     => isset($_POST['icon_file_id']) ? \absint($_POST['icon_file_id']) : 0,
        ];

        \update_option(self::OPTION_KEY, $settings);

        return self::getSettings();
    }

    public static function getSettings(): array
    {
        $settings = \get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return array_merge(
            [
                'qr_code_type'     => 'PKBarcodeFormatQR',
                'icon_file'        => '',
                'icon_file_id'     => 0,
                'logo_text'        => '',
                'background_color' => '#aaaaaa',
                'organisation_name' => '',
            ],
            $settings
        );
    }
}
