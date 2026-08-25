# [AyeCode Connect](https://wordpress.org/plugins/ayecode-connect/) #

Use this service plugin to easily activate any of our products, open a support ticket and view documentation all from your wp-admin!

To take full advantage of this plugin you should have one of our plugins installed.

[GeoDirectory](https://wordpress.org/plugins/geodirectory/) | [UsersWP](https://wordpress.org/plugins/userswp/) | [GetPaid](https://wordpress.org/plugins/invoicing/) | [BlockStrap](https://wordpress.org/plugins/blockstrap-page-builder-blocks/)

AyeCode Connect is a service plugin, meaning that it will have no functionality until you connect your site to ours. This link allows us to provide extra services to your site such as live documentation search and submission of support tickets.
After connecting your site you can install our update plugin which will give you the ability to automatically sync license keys of purchases and also be able to remotely install and update purchased products.

You will be able to remotely manage your activated sites and licences all from your account area on our site.

You can also use our one click demo importer.

NEW: **Cloudflare Turnstile Captcha** feature. You can now activate Cloudflare turnstile on your site which will add a captcha to all AyeCode Ltd products ( GeoDirectory, UsersWP, GetPaid, BlockStrap ).
Our implementation of Turnstile is loaded only when the field is show on the screen which helps with speed and SEO of your site.
NOTE: Your site does NOT have to be using Cloudflare to be able to use Cloudflare Turnstile.

---

## Security ##

To report a security vulnerability, please review our [vulnerability disclosure policy](https://ayecode.io/vulnerability-disclosure-policy/).

---

## Installation ##

### Minimum Requirements ###

* WordPress 6.0 or greater
* PHP version 7.2 or greater
* MySQL version 8.0 or greater

### Automatic installation ###

Automatic installation is the easiest option. To do an automatic install of AyeCode Connect, log in to your WordPress dashboard, navigate to the Plugins menu and click Add New.

In the search field type "AyeCode Connect" and click Search Plugins. Once you've found our plugin you install it by simply clicking Install Now.

### Manual installation ###

The manual installation method involves downloading our AyeCode Connect plugin and uploading it to your webserver via your favourite FTP application. The WordPress codex will tell you more [here](https://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

### Updating ###

Automatic updates should seamlessly work. We always suggest you backup up your website before performing any automated update to avoid unforeseen problems.


## Frequently Asked Questions ##

### Do you have T&C's and a Privacy Policy? ###

Yes, please see our [terms & conditions](https://ayecode.io/terms-and-conditions/) and [privacy policy.](https://ayecode.io/privacy-policy/)

### Do i need to pay to use this? ###

No, you can register a free account on our site which will provide you with live documentation search and the ability to get support directly from your wp-admin area.

### Is my connection to your site safe? ###

Yes, our system will only connect via HTTPS ensuring all passed data is encrypted.
Additionally, we built our systems in such a way that;
Should your database or our database (or both) be compromised, this would not result in access to your site.
Should your files or our files (or both) be compromised, this would not result in access to your site.

### Your demo importer is not working? ###

If your host runs "mod security" on your hosting and has some specific additional rules active, this can block some of our API calls. Please contact our support for help with this.