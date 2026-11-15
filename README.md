# Quince Block

**Content blocks with display rules, a schedule and A/B tests, for PrestaShop 9.2.**

Quince Block lets the shop write blocks of HTML and decide where, when and to whom they
are shown. A block is placed in hooks the shop creates in the module's settings, named
`displayQuinceBlock...`, or inside the content of a CMS page or a product description
with a short code. Rules on the product, the category, the cart, the customer and the
visitor decide whether the block appears on a given page.

> Quince Block is a free, open-source module (OSL-3.0). There is nothing to buy. If it
> saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **HTML blocks per shop and language.** Each block has its HTML written separately for
  every shop and every language, plus optional code for the page `<head>` and for the
  end of the page. That code is added only on pages where the block passes its rules and
  schedule. The title of a block is seen only in the back office.
- **Hooks of your own.** The shop creates hooks in the panel. Every name starts with
  `displayQuinceBlock`, followed by letters, digits or underscores, for example
  `displayQuinceBlockCartBanner`. The module registers itself on each new hook. A block
  can be assigned to several hooks and to several shops; blocks in one hook are shown
  by position, lowest first. A hook can be switched off or deleted.
- **Short code.** `[quince_block id="12"]` in the content of a CMS page, or in the
  description or short description of a product, is replaced with block 12. The block
  still has to pass its rules and schedule.
- **Schedule.** Dates from and to, days of the week and hours from and to, in the shop
  time zone. An end hour earlier than the start hour runs past midnight.
- **Rules.** 17 rule types, each set to include or exclude: category (with or without
  subcategories), brand, supplier, product feature (with an optional value), product
  tag, customer group, country, cart value (from and to), product in the cart, currency,
  UTM parameter, search phrase, device (phone, tablet, computer), signed in or guest,
  language, page type, and page address containing a text. Include rules match all
  together (AND) or any one of them (OR), set per block. A matching exclude rule always
  hides the block. A block without include rules is shown everywhere its hooks are.
- **Placeholders.** Tokens such as `{{product_name}}`, `{{price}}`, `{{cart_total}}` or
  `{{free_shipping_remaining}}` are replaced with shop data, and condition blocks such
  as `{{#if_in_stock}}...{{/if_in_stock}}` or `{{#if_cart_empty}}...{{/if_cart_empty}}`
  show a part of the content only when the condition holds. The edit screen lists every
  token and inserts it with one click. Other modules can add their own tokens through
  the `actionQuinceBlockRegisterPlaceholders` hook.
- **Refresh and lazy loading.** A block can reload itself when the cart changes, for
  example a bar showing the amount missing to free shipping, and can load only when it
  scrolls into view. Blocks added to the page later, such as in a cart drawer or a
  product list that loads more items, are handled too.
- **A/B test.** A block can have a variant B. While the test is on, visitors see variant
  A or B and the module counts views and clicks of each. Optionally, once both variants
  have reached a set number of views (at least 100 each), only the variant with the
  higher click rate is shown. The results can be reset to start counting again.
- **Preview link.** A private link shows the saved block in the shop even when it is
  switched off, outside its schedule or hidden by its rules, with variant A or B. Anyone
  with the link can open it; a new link stops the old one working.
- **History.** The block is saved before every change, with its content, display
  settings and rules. The last 50 versions are kept, and any of them can be restored.
- **Tester.** Checks whether a block will appear on a page described in a form (product,
  category, page type, address, search phrase, shop, language, currency, country, date)
  and shows the result of every rule. Rules that depend on a real customer, cart or
  device are shown as unknown.
- **Import and export.** Blocks, their contents, hooks and rules are exported to a JSON
  file and imported from one, added to the current blocks or replacing them. The whole
  file is checked before anything is saved and is saved in one transaction. Statistics
  and history are not exported.
- **Settings.** Custom CSS per shop, added to the page head; the stock level below which
  a product counts as low stock for `{{#if_low_stock}}`; refreshing blocks after cart
  changes, on or off for the shop.
- **Multilingual.** English base with a full Polish translation, on PrestaShop's
  standard translation system.

## What is stored

- **In the database:** the blocks with their contents, schedule, rules and hooks; the
  last 50 versions of each block; for the A/B test, a daily count of views and clicks
  per block, shop and variant. No IP addresses, customer IDs or anything that links a
  view or a click to a person.
- **Cookies:** only with Quince CMP active and the visitor's consent to the analytics
  category. Then the module remembers the A/B variant a visitor saw (`qb_ab_<id>`) and
  the UTM parameters of the visit (`qb_utm`), for 30 days. Without Quince CMP the module
  sets no cookies, and without consent it neither sets cookies nor counts views and
  clicks.
- **Without Quince CMP:** views and clicks are counted anonymously, with the variant
  drawn again on every view.

The statistics endpoint accepts only signed POST requests for blocks with a running
test, and counts a limited number of events per address in ten minutes. The address is
kept only as a hash, in the Quince Engine table that keeps form limits.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.1 or newer |
| Quince Engine | required |
| Quince CMP  | optional, for consent before cookies and statistics |

Quince Engine hosts the module's settings screens. The module declares it as a
dependency, so PrestaShop refuses to install Quince Block without it.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_block.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_block/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_block` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Block and click **Install**.

**From Quince Engine**

With Quince Engine installed, **Quince project → Quince modules** installs and updates
this module from the Quince release list, without downloading anything by hand.

**With Composer**

A PrestaShop shop ships without a `composer.json` of its own, so Quince packages are
installed from a separate folder next to the shop. The one-time setup of that folder is
described in the [Quince Engine README](https://github.com/quinceproject/quince_engine#installation).
Then, from that folder:

```sh
composer require "quinceproject/block:*"
php ../bin/console prestashop:module install quince_block
```

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_block/releases).

Uninstalling the module removes the blocks, their rules, hooks, history and statistics,
and its settings.

## Configuration

Open **Quince project → Blocks** in the back office menu, or click **Configure** next
to the module in the Module Manager. The side menu has Blocks, Hooks, Statistics,
Tools and Settings.

1. **Hooks.** Create a hook, for example `displayQuinceBlockHomeBanner`.
2. **Blocks.** Click to add a block, write its content for each shop and language, and
   in **Display** choose its hooks, shops, position and schedule.
3. **Rules.** After the first save, add rules and choose whether all include rules or
   any of them must match.
4. **A/B test.** Write the variant B content, switch the test on and, if you want, let
   the module show only the better variant once it is decided.

**Placing a hook in the theme**

A hook created in the panel shows nothing until the theme calls it. Add it to the
Smarty template where the block should appear:

```smarty
{hook h='displayQuinceBlockHomeBanner'}
```

To use product rules and product placeholders in a template that knows the product,
pass it to the hook:

```smarty
{hook h='displayQuinceBlockProductInfo' product=$product}
```

**Placing a block in content**

Type `[quince_block id="12"]` in the content of a CMS page or in a product description.
The block number is shown in the list of blocks.

**Adding placeholders from another module**

A module registered on `actionQuinceBlockRegisterPlaceholders` returns an array of
tokens and values, for example `['{{loyalty_points}}' => '120']`. Token names use lower
case letters, digits and underscores. The hook receives `id_product`,
`id_product_attribute`, `id_lang`, `id_shop`, `id_cart`, `id_customer`, `customer` and
`page`. The module escapes its own values; built-in tokens cannot be overridden.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. If the module is useful
to you, you can support its development by buying me a coffee:

**[buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)**
