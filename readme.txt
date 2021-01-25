=== AyeCode Connect ===
Contributors: stiofansisland, paoltaia, ayecode, Ismiaini
Donate link: https://www.ko-fi.com/stiofan
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Tags:  ayecode, service, geodirectory, userswp, wpinvoicing
Requires at least: 4.7
Requires PHP: 5.6
Tested up to: 5.5
Stable tag: 1.1.7


AyeCode Connect allows you to install any purchased AyeCode Ltd product add-ons without a zip file. It also installs and activates licences automatically, so there is no need to copy/paste licenses.

== Description ==

To take full advantage of this plugin you should have one of our plugins installed.

[GeoDirectory](https://wordpress.org/plugins/geodirectory/) | [UsersWP](https://wordpress.org/plugins/userswp/) | [WP Invoicing](https://wordpress.org/plugins/invoicing/)

The AyeCode Connect Service plugin will link your website with your user account on our website letting you install any purchased addons and keep any purchase licences up to date automatically.

You will be able to remotely manage your activated sites and licences all from your account area on our site.

== Installation ==

= Minimum Requirements =

* WordPress 4.7 or greater
* PHP version 5.6 or greater
* MySQL version 5.0 or greater

= Automatic installation =

Automatic installation is the easiest option. To do an automatic install of AyeCode Connect, log in to your WordPress dashboard, navigate to the Plugins menu and click Add New.

In the search field type "AyeCode Connect" and click Search Plugins. Once you've found our plugin you install it by simply clicking Install Now.

= Manual installation =

The manual installation method involves downloading our plugin and uploading it to your webserver via your favourite FTP application. The WordPress codex will tell you more [here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

= Updating =

Automatic updates should seamlessly work. We always suggest you backup up your website before performing any automated update to avoid unforeseen problems.


== Frequently Asked Questions ==

TBA

== Screenshots ==

1. Pre Connection.
2. Connection Screen.
3. Connected.

== Changelog ==

= 1.1.8 =
* multisite undefined function wpmu_delete_user() issue - FIXED

= 1.1.7 =
* WP 5.5 requires API permissions callback param - ADDED

= 1.1.6 =
* CloudFlare can cause our server validation methods to fail resulting in licenses not being added - FIXED
* Stored keys will be cleared when deactivating 'One click addon installs' - CHANGED

= 1.1.5 =
* WPML dynamic URL change can disconnect a site - FIXED
* Warning added if another plugin may be calling the get_plugins() function too early in which case we can install a must use plugin to try and resolve - ADDED

= 1.1.4 =
* License sync now checks if site_id and site_url are correct and will work before syncing - CHANGED
* Detect and disconnect if site_url changes and invalidates licences - ADDED

= 1.1.3 =
* Support user on network not able to access all plugin settings - FIXED
* Max API timeout changed from 10 to 20 seconds - CHANGED
* When the website URL changes a notice will show asking to re-connect the new URL - ADDED

= 1.1.2 =
* WP_DEBUG being active can affect initial connection - FIXED

= 1.1.1 =
* If support user not set PHP Notice can show if debugging enabled - FIXED
* Remove support user if plugin deactivated - ADDED
* Remove support user immediately if site disconnected - CHANGED

= 1.1.0 =
* Support widget and live documentation search now available - ADDED
* Temporary Support User Access feature now available - ADDED

= 1.0.6 =
* Extensions screen can still request key if no addons installed on first sync - FIXED
* Small spelling mistakes - FIXED

= 1.0.5 =
* If switching a connected user account the license keys are not immediately updated - FIXED
* Deactivate and remove all licence keys when disconnecting a site - CHANGED

= 1.0.4 =
* Added connected notice when activating from product extensions page - ADDED
* Changes added to be able to detect if activating site is a network site - ADDED

= 1.0.3 =
* Added settings link to plugins page - ADDED

= 1.0.2 =
* First wp.org release - WOOHOO

= 1.0.1 =
* Warning added that localhost sites won't work - ADDED

= 1.0.0 =
* First release - YAY