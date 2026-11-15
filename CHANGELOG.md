# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
