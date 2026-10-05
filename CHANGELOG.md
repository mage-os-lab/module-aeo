# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/). Releases are cut from git tags —
the tag is the source of truth for the version (composer.json carries no
hardcoded version field).

**Origin.** This module was part of
[mage-os/module-seo](https://github.com/mage-os-lab/module-seo) (MageOS_Seo) on and
before 2026-10-02. Its history up to then is kept in that repository.

## [Unreleased]

### Added

- **Split from mage-os/module-seo.** `/llms.txt`, `/llms-full.txt` and `/llms.jsonl`, their feed
  storage, and the AI crawler directives in `robots.txt` come from MageOS_Seo, which this module
  requires. MageOS_Seo keeps the rebuild queue and its `seo:rebuild` command, the FAQ source pool,
  the Organization, the admin tab and ACL parent, and the public-path registry; this module registers
  with each.
  - **Unchanged:** the settings (`mageos_aeo/{llms_txt,feeds,ai_robots}/*`, under Stores →
    Configuration → MageOS SEO → AI Information & Crawlers), the ACL resource `MageOS_Aeo::config`,
    the `env.php` key `mageos_aeo/feed_storage_roots`, the default directory `var/mageos_aeo/`, the
    cache tags, the cron job `mageos_aeo_regenerate_feeds`, the rebuild lock, the feed groups
    `llms` and `jsonl`, the internal URLs `mageos-aeo/…`, and the observer and plugin names.
  - **Breaking: the namespace is `MageOS\Aeo\`**, where it was `MageOS\Seo\`. The class names after
    it are unchanged except one: `Model\Aeo\Config` is now **`Model\Config`**. The others are
    `Api\LlmsTxtSectionProviderInterface`, `Api\JsonlLineProviderInterface`, `Model\Feed\*`,
    `Model\LlmsTxt\*`, `Model\LlmsJsonl\*`, `Model\Router\LlmsTxtRouter`,
    `Model\Cache\CleaningMode`, `Model\Config\Backend\FeedStorageDir`,
    `Model\Config\Source\{AiBots,FaqGroups}`, the three controllers, the observers,
    `Plugin\Robots\AppendAiDirectivesPlugin`, `Plugin\Catalog\Product\Action\InvalidateJsonlOnMassAttributeUpdate`,
    `Cron\RegenerateFeeds`, `Exception\FeedRebuildInProgressException` and
    `Setup\Patch\Data\RemoveHreflangSitemap`. A module that registers an llms.txt section or a
    jsonl line provider updates the type names in its di.xml and its imports.
  - Log lines start `MageOS_Aeo:`, where they started `MageOS_Seo:`, and the comment above the AI
    crawler groups in `robots.txt` reads `# AI crawlers (managed by MageOS_Aeo)`.
  - `RemoveHreflangSitemap` names its MageOS_Seo class as an alias (`getAliases()`), so an
    installation that applied it before the split does not apply it again.
- **AI Discoverability → FAQ Groups** (`mageos_aeo/llms_txt/faq_groups`, per store view, default
  `global`): which FAQ groups `/llms.txt` (the first 5 questions) and `/llms-full.txt` (all)
  include, in order. Selecting none leaves FAQs out. Only a group named `global` was read before.
  The groups offered are those of every source in MageOS_Seo's FAQ source pool. See
  `docs/llms-txt.md#faq-section`.
- **The llms documents register with MageOS_Seo's rebuild queue** as `Model\Feed\LlmsRebuildHandler`
  (groups `llms` and `jsonl`). `bin/magento seo:rebuild [-g llms|jsonl]` rebuilds them in process,
  and every `setup:install` / `setup:upgrade` queues a rebuild of the ones the store views can
  build, so a fresh install no longer answers `503` until the nightly cron.
- **Translations:** `i18n/en_US.csv` (the source), `en_GB.csv` and `nl_NL.csv`. The llms documents'
  own headings and labels are written in each store view's language.
- **`@api` on every interface under `Api/`**, which marks the module's contract, and a unit test
  that fails if an interface there lacks it.
- **A feed that could not be rebuilt, or was written incomplete, is shown in the admin** until a
  rebuild gets through: in the System Messages bar and once in the inbox, through MageOS_Seo's
  rebuild problems (its `docs/rebuild-problems.md`). **Requires mage-os/module-seo `^1.2.1`.**
  - Each group's result is recorded by `FeedRegenerator::regenerate()`, so the nightly cron's
    rebuild counts as well as the queue's and the command's. A group is built on its own for each
    store view: `llms.txt` failing no longer stops `llms.jsonl` being written for that store view.
    The result is still one error message per store view.
  - Incomplete means a stock lookup failed while `/llms.jsonl` was built (that batch is listed as
    out of stock), or the storage directory was refused and the feeds fell back to `var/mageos_aeo`.
  - `LlmsRebuildHandler` labels the groups `llms.txt and llms-full.txt` and `llms.jsonl`, and names
    the nightly job (`Cron\RegenerateFeeds::JOB`) as their retry.
  - The constructors of `FeedRegenerator` (last), `FeedStorage` (last) and `JsonlBuilder` (before
    `lineProviders`) take MageOS_Seo's `Model\Rebuild\ProblemLog`.

### Changed

- **Breaking: the settings and identifiers have their own prefix,** `mageos_aeo`. Nothing is
  migrated: enter the settings again and grant admin roles the new resource.
  - `mageos_seo_general/{llms_txt,feeds,ai_robots}/*` become `mageos_aeo/{llms_txt,feeds,ai_robots}/*`,
    in their own section, **AI Information & Crawlers**, under `MageOS_Aeo::config`;
  - the `env.php` key is `mageos_aeo/feed_storage_roots`, and the default directory is
    `var/mageos_aeo/`. `var/mageos_seo/` is no longer read or cleaned, and can be deleted;
  - the cache tags are `MAGEOS_AEO_LLMS`, `MAGEOS_AEO_LLMS_FULL` and `MAGEOS_AEO_LLMS_JSONL`, the
    cron job is `mageos_aeo_regenerate_feeds`, and the rebuild lock is `mageos_aeo_feed_rebuild`;
  - the internal URLs are `mageos-aeo/…` (they 301 to the documents).
- **Breaking: `Model\LlmsTxt\SectionProviderInterface` is now `Api\LlmsTxtSectionProviderInterface`**
  (`@api`). A section provider must implement the new name; `LlmsTxtBuilder` skips anything else.
- The settings are read by a class of their own, `Model\Config`, not MageOS_Seo's. The getters and
  constants keep their names: `isLlmsTxtEnabled()`, `isLlmsFullTxtEnabled()`, `isLlmsJsonlEnabled()`,
  `getLlmsFaqGroups()`, `getFeedStorageDir()`, `isAiRobotsEnabled()`, `getAiDisallowedBots()`.
- The store view's locale comes from MageOS_Seo's `Model\Config::getLocaleCode()`, and the AI
  contact from `Model\Organization\ContactEmail`, the same sources hreflang, `og:locale` and the
  Organization use.
- Feeds are built by the queue, never by a web request.
  - A change queues a rebuild; the consumer and the nightly cron replace each file atomically
    (temporary file and rename) and purge the group's cache tags once, after every store view is
    written. A request for a feed not yet built answers `503` with `Retry-After` (`/llms.txt`
    answers `404`, see Fixed).
  - Files are written `0640` and directories `0750` whatever the process umask, so the cron or
    consumer user and the web user must share an owner or group.
  - Files of a feed disabled for a store view are removed on the next rebuild.
- Rebuilds are queued only for changes that can affect a feed
  (`Model\Feed\LlmsInvalidationPolicy`), and never for a feed no store view can build (`/llms.jsonl`
  is off by default). Product saves rebuild `/llms.txt` and `/llms-full.txt` when they change a
  category's product count: a new product, or changed category or website assignments.
- What invalidates the feeds, beyond saves:
  - a deleted product rebuilds all three; a deleted category `/llms.txt` and `/llms-full.txt`;
  - a mass attribute update rebuilds `/llms.jsonl`
    (`Plugin\Catalog\Product\Action\InvalidateJsonlOnMassAttributeUpdate`), and a mass website
    assignment change all three;
  - moving a category rebuilds `/llms.txt` and `/llms-full.txt`, which list the category tree.
- `/llms.jsonl` is written one line at a time from a paged collection, so peak memory is one page of
  products, and availability is read in batches through MSI's `AreProductsSalableInterface`.
- All three documents are cacheable for 24 hours (`Cache-Control: public, max-age=86400,
  s-maxage=86400`); they sent `max-age=3600` before.

### Fixed

- **The category tree in `/llms-full.txt` counts what each category page lists.** An anchor category,
  Magento's default, had no count at all, and every other category counted its raw assignments:
  disabled products and a configurable's children included (Luma's Men → Jackets: 176, where its
  page lists 11).
  - A count is now the products the page lists: enabled, in the website, visible in the catalogue,
    and for an anchor, its subcategories' too. They are read from the store view's category product
    index in one query, by the new `Model\ResourceModel\CategoryProductCount`.
  - Stock is not taken into account (the index has none), and the counts are as current as the
    index. See `docs/llms-txt.md`.
  - `LlmsTxtBuilder`'s constructor takes `CategoryProductCount` before `sectionProviders`.
- **The category tree in `/llms-full.txt` follows the storefront menu.** A category left out of the
  menu (Include in Menu at No) was listed; it is now left out with its subcategories. Siblings came
  in category ID order (Luma's top level read Men, Women, Gear, Sale, What's New…); they now come in
  the menu's order (What's New, Women, Men, Gear…). Every level is still listed, whatever the
  menu's Maximal Depth.
- **A `/llms.jsonl` line whose price is not known leaves the price out, instead of `0.00`,** which
  reads as free.
  - The offer keeps its availability and URL, without `price` and `priceCurrency`.
  - Unknown means a composite product (configurable, grouped, bundle) priced 0, which Magento does
    when no option can price it, or a price lookup that throws. The lookup used to be swallowed; it
    is now logged with the SKU.
  - MageOS_Seo's `Model\Product\FinalPrice` decides it, as it does for the product pages' structured
    data. **Requires mage-os/module-seo `^1.2.1`.** `ProductLineBuilder`'s constructor takes
    `FinalPrice` after `CurrencyService`.
- `docs/llms-txt.md` said out-of-stock products get a line, as OutOfStock. They have none. With
  Display Out of Stock Products at No, Magento's default, Magento leaves them out of the price index
  the feed reads its products with.
- **llms.txt follows the format** (checked against the llms.txt spec v2 of 10 August 2026, the
  reference parser `llms_txt` on PyPI and Lighthouse's llms-txt audit).
  - Items under an H2 are `- [name](url)` links. Base URL, locale, search template, structured data
    and contact are in the details list before the first H2. The summary is one line, and with no
    description there is no blockquote. FAQ entries are one line of plain text in the details.
    Square brackets in link labels become round ones.
  - The structured data line names schema.org types instead of template codes.
  - The sitemap link is core's URL of the sitemap configured under Marketing → Site Map, not a
    hardcoded `/sitemap.xml`, and there is none when the store view has none.
  - **`/llms.txt` answers `404`, not `503`, before its first build**, with `Retry-After` and
    `Cache-Control: no-store`: Lighthouse scores a 5xx as a failure and a 4xx as not applicable.
    `/llms-full.txt` and `/llms.jsonl` keep `503`.
  - The `/llms.jsonl` description is one line, cut at a word boundary with `…`.
- **The router runs before core's URL-rewrite router** (sortOrder 15, was 20). The two shared a
  sortOrder, so a URL rewrite for an enabled document's path could take it. A disabled document's
  path is not claimed, so a static file or another module can serve it.
- `/llms.jsonl` prices were converted to the display currency twice, and written in the locale's
  number format. They are used as PriceInfo gives them, with two decimals and a full stop.
- The `> Locale:` line was empty: the locale was read from a method that does not exist. It is
  **General → Locale Options → Locale** for the store view, and the line is left out when there is
  none.
- The AI contact was the store's Customer Support email, which Magento ships as
  `support@example.com`. It is now the Organization's Contact Email; else the Customer Support
  email unless it is still the shipped value; else none, and the section is left out.
- A configuration change to something `/llms.txt` and `/llms-full.txt` show — the locale, the
  Customer Support email, their own settings, the base URLs, the category URL suffix — reached them
  only with the nightly rebuild. It now queues one, when the value changed.
- Saving or deleting a FAQ or the Organization anywhere but the admin form — the REST API, an
  import, a data patch — left `/llms.txt` and `/llms-full.txt` stale until the nightly rebuild. Their
  model events (`mageos_faq_*`, `mageos_seo_organization_*`) now queue it, however the save was
  made.
- **`feeds/storage_dir` is restricted and validated.** It is an absolute path set in the admin, so an
  administrator could point feed writes at any directory PHP can reach.
  - Inside the installation only `var/` is accepted. The root and every other standard directory
    (`app/`, `bin/`, `dev/`, `generated/`, `lib/`, `pub/`, `setup/`, `update/`, `vendor/`) are refused,
    as are hidden directories and `..`, and the path is resolved first so a symlink inside `var/`
    cannot stand for a target outside it.
  - A shared mount outside the installation is declared in `app/etc/env.php` under
    `mageos_aeo/feed_storage_roots`, which the admin cannot edit. The installation rule is applied
    first, so a declared root pointing at `pub/`, `app/` or `vendor/` is ignored.
  - The rules run on save, with the reason shown, and again when the value is read. A refused value
    is logged and the feeds fall back to `var/mageos_aeo`.
- The documents no longer start a session. A session sets a cookie, which stops shared caches
  storing the response, and makes PHP send `Pragma: no-cache` over the controller's caching policy.
- A catalog, CMS page or store save no longer takes the documents offline. Every save used to delete
  the served files across all store views and purge the page cache before the rebuild was queued,
  so the documents answered `503` until the consumer ran.
- The AI crawler directives in `robots.txt` emit `Disallow: /` groups for disallowed bots only.
  Allowed bots used to get `Allow: /` groups of their own, which exempted them from every
  `User-agent: *` rule. The comment under **Disallow These AI Crawlers**, which still said they
  did, now says they get no group and follow the `User-agent: *` rules.
- Deleting a store view removes its feed directory once the delete commits
  (`Observer\RemoveFeedFilesOnStoreDelete`), so a delete that rolls back keeps its files. Deleting a
  website or store group removes its store views without a `store_delete` event, so a full rebuild
  now also sweeps directories whose store view no longer exists.
- Feed file listings no longer come back stale. `Magento\Framework\Filesystem\Glob` memoises every
  result for the life of the process, so a rebuild could act on a listing from before it wrote
  anything — in the queue consumer with `--max-messages`, or the command.
