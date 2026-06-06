=== CommerceBird - Wallet Pass for Tickera ===
Contributors: fawadinho
Author link: https://commercebird.com
Tags: woocommerce,apple,wallet,tickets,events
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.2
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Automatically generate Apple Passes for Tickera via our Plug&Play CommerceBird Platform.

== Description ==

= Wallet Pass Add-On for Tickera Events =

CommerceBird Wallet Pass for Tickera adds Apple Wallet and Android Wallet pass delivery for Tickera ticket purchases.

= What This Plugin Does =

For Tickera-powered events, this add-on generates mobile passes for attendees and provides wallet download links after purchase.

= Built for Event Ticketing and Mobile Wallets =

This plugin includes:

- Apple Wallet pass generation for iPhone and iPad
- Android Wallet compatibility for Google Wallet users
- Seamless ticket delivery with mobile wallet support
- Faster event entry and better attendee satisfaction
- Secure pass URLs and modern digital ticket workflows

= Features =

Instant Wallet Pass Downloads — deliver Apple Wallet and Android Wallet passes immediately after checkout
Tickera Native Integration — works directly with Tickera Events and WooCommerce ticket flows
Mobile Wallet Ready — support for both Apple Wallet and Android mobile ticketing
Improved Event Experience — attendees store tickets in their wallet app instead of email
Simple Setup — install, activate, and configure in minutes for Tickera event tickets
= Tickera Event Ticket Support =

This add-on extends Tickera ticket delivery by adding wallet pass options for supported devices.

= Wallet-Friendly Tickera Events =

Attendees can store supported tickets in their mobile wallet app and access them from the Thank You page and email confirmation.

== External services ==

This plugin connects to an API to generate the passes, it's needed to save the passes in the WP media library and make it available on the Order confirmation page as well as the Order confirmation template.
It sends the event's information as well as the ticket id to generate the pass. No personal data is sent.
This service is provided by "CommerceBird":  [TOS](https://commercebird.com/terms-conditions) and [Privacy Policy](https://commercebird.com/privacy-policy)

== Frequently Asked Questions ==

= Q: Do I need a paid plan to use this plugin? =
A: Yes, you need a Premium plan at commercebird.com and the main commercebird plugin. 
In addition, you also need the Bundle plan of Tickera as this solution requires the Bridge for WooCommerce add-on.

= Q: How quickly and easy can I set this up? =
A: Very easy, all you need is to follow the installation steps and you can be done in 5 minutes.

= Q: Where are the passes stored? =
A: Our server stores the generated passes in your site's media library. They get automatically deleted when an event is cancelled or has taken place.

= Q: Where can the customer view the Ticket's pass and download it? =
A: On the Thank You page after payment and in the email confirmation. 

== Installation ==

**To install CommerceBird - Wallet Pass for Tickera, follow these steps:**

1. Download and activate the CommerceBird plugin (Main plugin)
2. Go to [CommerceBird.com](https://commercebird.com/pricing) and subscribe to the Premium plan trial
3. Click on the "next steps" button to visit the my account page
4. Open a new browser tab and connect your domain to our [App](https://app.commercebird.com)
5. Create an application password as well via Settings > Application Password.
6. Click on the "Copy Token" button via the main menu in the app.
7. Go to your store's wp-admin menu > commercebird > paste the subscription id, token and your e-mail address and click save!
8. Go to Tickera > Settings > Apple Wallet Pass > configure your Passes styling settings.
9. That's all! Now you can test it by placing an event order via your iOS or Android device.

== Screenshots ==
1. Apple Wallet Settings in Tickera
2. Thankyou page to download the passes

== Changelog ==
= 1.0.2 - 4 May 2026 =
* Fix: empty vendor folder caused fatal error

= 1.0.1 - 4 May 2026 =
* Fix: set post_type to tc_tickets_instances to avoid conflicts with Tickera add-ons

= 1.0.0 - 3 May 2026 =
* Initial Release