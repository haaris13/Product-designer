=== Lecouz Product Designer ===
Contributors: lecouz
Tags: woocommerce, product customizer, product designer, personalization, custom print
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let customers add their own text and images to WooCommerce products, and get print-ready files with every order.

== Description ==

Lecouz Product Designer adds a light, fast editor to your WooCommerce product pages. Customers place text and images inside the print areas you define, preview the result on the product, and order it. You download a print-ready PNG for each area from the order screen.

**For shop owners**

* Choose a base image and draw one or more print areas (front, back, sleeve...) with your mouse.
* Enter the real size of each area in millimetres: print files are exported at 300 dpi, and customers are warned when an image is too small to print well.
* Make the design optional or required before a product can be added to the cart.
* Download the preview and the print file of each area from the order screen.
* Works with High-Performance Order Storage.

**For customers**

* Add text with a choice of fonts, colours, sizes, bold, italic and alignment.
* Upload their own image (PNG, JPEG, WebP, GIF).
* Move, resize and rotate everything inside the print area. Anything outside it is cut off, as on the finished product.
* Works on phones and tablets.
* See their design in the cart, in the order and in emails.

**Privacy and performance**

* Nothing is loaded from a third-party server: no CDN, no external fonts, no tracking.
* The editor script loads only on product pages that have the designer enabled.
* Designs are stored in your own uploads folder under random, unguessable names. Designs that are never ordered are deleted after 3 days.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the plugin to the `/wp-content/plugins/` directory, or install it from the Plugins screen, then activate it.
3. Edit a product and open the "Product designer" tab in the Product data box.
4. Tick "Enable designer", choose a base image, click "Add print area" and place the rectangle where customers can print.
5. Save the product.

== Frequently Asked Questions ==

= Where do I find the files to print? =

Open the order in WooCommerce. Under each customised line you see the preview and one "Print file" button per print area.

= Does it work with variable products? =

Yes. The same design settings apply to every variation of the product.

= Which fonts are available? =

Fonts installed on the visitor's device (Arial, Georgia, Courier New and so on), so nothing is downloaded from a third-party service. Developers can change the list with the `lecouzpd_fonts` filter.

= Does it work with the block-based cart and checkout? =

Yes. The cart and checkout blocks show the customer's preview as the product image, and the design is saved in the order.

= What happens to my data when I delete the plugin? =

Product settings and the design files of existing orders are kept, so you never lose an order's artwork. Delete the `uploads/lecouz-product-designer` folder yourself if you no longer need them.

== Third-party libraries ==

This plugin bundles [Fabric.js](https://fabricjs.com/) 5.3.0, released under the MIT license. The file `assets/js/vendor/fabric.min.js` is the official minified build. Its unminified source is at https://github.com/fabricjs/fabric.js/tree/v5.3.0 and on npm as `fabric@5.3.0`.

== Changelog ==

= 0.1.0 =
* First release.
