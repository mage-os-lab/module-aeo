# MageOS_Aeo

AI discoverability for Magento Open Source and Mage-OS: **`/llms.txt`**, **`/llms-full.txt`** and
**`/llms.jsonl`**, pre-generated per store view, and **AI crawler directives** in `robots.txt`.

This module was part of [mage-os/module-seo](https://github.com/mage-os-lab/module-seo)
(MageOS_Seo) on and before 2026-10-02. Its history up to then is kept in that repository.

---

## What it does

| URL | Content | Default |
| --- | --- | --- |
| `/llms.txt` | Concise: organization name, description, base URL, locale, schema types, the first 5 FAQs of the selected groups, AI contact | On |
| `/llms-full.txt` | Extended: the above plus social profiles, full category tree, every FAQ of the selected groups | On |
| `/llms.jsonl` | NDJSON, one compact JSON-LD `Product` node per line | Off |
| `robots.txt` additions | `Disallow: /` groups for the AI crawlers you choose, out of 14 known ones | Off |

- **Pre-generated, never built by a web request.** The documents are written to files (by default
  `var/mageos_aeo/`) and served from there. A change that affects them queues a rebuild on
  MageOS_Seo's rebuild queue; the nightly cron is a full-rebuild safety net. See
  [docs/feeds.md](docs/feeds.md).
- **Written in each store view's language**, from the same Organization, locale and FAQ sources the
  structured data uses. See [docs/llms-txt.md](docs/llms-txt.md).
- **Cacheable** for 24 hours, and served without a session, so shared caches can store them.

`/llms.txt` takes the organization name and description from MageOS_Seo's Organization record —
**configure it first** under **Marketing → SEO → Organization**, or the documents will be
incomplete.

**Multi-server deployments** must point the web servers and the cron/consumer host at a shared
mount, which takes one entry in each machine's `app/etc/env.php` as well as the admin setting — see
[Storing the feeds outside var/](docs/feeds.md#storing-the-feeds-outside-var-multi-server).

---

## Requirements

- PHP 8.3 – 8.5
- Magento Open Source / Mage-OS **2.4.7 or newer** (`magento/framework ^103.0.7`)
- **MageOS_Seo** (`mage-os/module-seo`).
  - Its rebuild queue, consumer and `seo:rebuild` command build the documents; this module
    registers its feed groups there.
  - Its Organization, locale and FAQ source pool are what the documents describe.
  - Its admin tab and SEO ACL resource hold this module's settings.
  - Its public-path registry keeps sessions off the documents' URLs.
- Magento MSI (`Inventory*`) modules: `/llms.jsonl` reads availability through the MSI service
  contracts.

---

## Installation

```bash
composer require mage-os/module-aeo
bin/magento module:enable MageOS_Aeo
bin/magento setup:upgrade
bin/magento cache:flush
```

`setup:upgrade` queues a first build of the documents the store views can build. The queue
consumer (`mageosSeoFeedRegenerate`, MageOS_Seo's) must run for them to appear; to build them
straight away, run `bin/magento seo:rebuild -g llms`.

---

## Admin

**Stores → Configuration → MageOS SEO → AI Information & Crawlers** (`mageos_aeo`):

| Group | Key settings | Default |
| --- | --- | --- |
| AI Discoverability | `/llms.txt`, `/llms-full.txt`, `/llms.jsonl`, FAQ groups in the llms documents | Yes / Yes / **No** / `global` |
| Feed Storage | Where the pre-generated feeds are written | *(empty — `var/mageos_aeo`)* |
| AI Crawler robots.txt | Append directives, disallow list | **No** / CCBot,Bytespider |

The section is guarded by the ACL resource `MageOS_Aeo::config`, under MageOS_Seo's
`MageOS_Seo::seo`.

Identifiers this module owns:
- **Feed groups** on MageOS_Seo's rebuild queue: `llms` (`/llms.txt` and `/llms-full.txt`) and
  `jsonl`.
- **Cache tags:** `MAGEOS_AEO_LLMS`, `MAGEOS_AEO_LLMS_FULL`, `MAGEOS_AEO_LLMS_JSONL`.
- **Cron job:** `mageos_aeo_regenerate_feeds` (nightly full rebuild).
- **Lock:** `mageos_aeo_feed_rebuild`.
- **`env.php` key:** `mageos_aeo/feed_storage_roots`.
- **Internal URLs:** `mageos-aeo/…`, which 301 to the documents.

---

## Extending the module

Everything under `Api/` is marked `@api`: it is the module's contract, and Magento's
backward-compatibility promise covers it and nothing else.

| Extension point | Interface | Resolution |
| --- | --- | --- |
| llms.txt section providers | `LlmsTxtSectionProviderInterface` | collect-all |
| llms.jsonl line providers | `JsonlLineProviderInterface` | collect-all |

See [Adding content from a bridge module](docs/llms-txt.md#adding-content-from-a-bridge-module) and
[Adding lines](docs/llms-txt.md#adding-lines).

The FAQ groups come from MageOS_Seo's FAQ source pool (`FaqSourceProviderInterface`), not from this
module: register a source there, and its groups can be chosen under **FAQ Groups**.
[MageOS_Faq](https://github.com/mage-os-lab/module-faq) registers its FAQ table that way.

---

## Development

```bash
composer install

# Run all quality gates
composer test

# Or individually
vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
vendor/bin/phpcs --standard=phpcs.xml.dist
XDEBUG_MODE=coverage vendor/bin/infection --threads=4  # gate: minMsi in infection.json5
```

Integration tests live under `Test/Integration/` and run in CI against a live Magento install via
[`graycoreio/github-actions-magento2`](https://github.com/graycoreio/github-actions-magento2).
They cannot be run locally without a full Magento installation.
