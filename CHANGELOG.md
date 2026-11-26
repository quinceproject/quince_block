# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.4] - 2026-11-26

### Added

- `{{products_count}}`: the number of active products visible in the catalogue that
  match a brand, a category (with its subcategories) and a feature value, with the
  word in the right form for the number (`words="tool|tools"`, or three forms such as
  `narzędzie|narzędzia|narzędzi`). The count is kept for an hour.
- `{{products_url}}`: the address of the category or brand page with the feature value
  selected in the filters, in the format of the PrestaShop filter module.

### Fixed

- The list of placeholders on the block edit screen is translated into Polish.

## [0.1.3] - 2026-11-16

### Changed

- New module logo: the letter Q in the project gradient.

## [0.1.2] - 2026-11-15

### Security

- **Refreshing or lazy loading returns only the block that was on the page, in the
  place where it was.** The signed page context is now issued for one block in one
  hook (or one shortcode). Before, a context taken from any refreshing block could be
  used to fetch any other active block as if it were a shortcode, including blocks
  meant for a different hook. Pages cached before the update stop refreshing their
  blocks until they are loaded again.

## [0.1.1] - 2026-11-15

### Security

- **A/B test results can no longer be tipped from outside.** Each display of a block
  gets its own signed token that carries the variant shown. A view counts once per
  token and visitor, and a click only after such a view, also once. The variant is
  read from the token, not from the request. Before, the token was the same for every
  display and anyone could report views and clicks for either variant, so with
  auto-optimisation on the shop could switch to a "winner" chosen by an outsider.
- Pages served from a full-page cache still count every visitor: the same token is
  counted once per visitor, recognised by a random value in the shop's encrypted
  cookie.

## [0.1.0] - 2026-11-15

First public release.

### Added

- HTML blocks with content per shop and language, optional code for the page head and
  the end of the page, a position and a switch to show or hide the block.
- Hooks named `displayQuinceBlock...` created in the panel, with the module registered
  on each of them, and the `[quince_block id="N"]` short code in CMS pages and product
  descriptions.
- Schedule by dates, days of the week and hours.
- 17 rule types, each include or exclude, with include rules combined by AND or OR.
- Placeholders and condition blocks, with the `actionQuinceBlockRegisterPlaceholders`
  hook for tokens from other modules.
- Refresh after cart changes and lazy loading, also for blocks added to the page later.
- A/B test with variant B, view and click statistics and an optional automatic winner.
- Cookies and statistics only after consent in Quince CMP, when it is installed.
- Private preview link, history of the last 50 versions with restore, block tester,
  JSON import and export.
- Settings: custom CSS per shop, low stock threshold, refresh after cart changes.
