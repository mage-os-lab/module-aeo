# AI Discoverability (llms.txt)

The module serves two plain-text documents at well-known URLs so LLM crawlers and AI agents can understand the site without full crawl cycles. This follows the emerging `llms.txt` convention.

---

## The two documents

| URL | Content | Config toggle |
|---|---|---|
| `/llms.txt` | Concise: org name, description, base URL, locale, available schema types, the first 5 FAQs, AI contact email | Stores → Configuration → MageOS SEO → AI Information & Crawlers → AI Discoverability (llms.txt) → Enable /llms.txt |
| `/llms-full.txt` | Extended: everything in the concise version plus social profiles, full category tree with product counts, full template list, every FAQ | Stores → Configuration → MageOS SEO → AI Information & Crawlers → AI Discoverability (llms.txt) → Enable /llms-full.txt |

Both return `404` when their respective config toggle is off.

Both config toggles are per-store-view settings.

A third document, `/llms.jsonl`, carries one compact JSON-LD `Product` node per line for the
store's catalogue (see [Content of /llms.jsonl](#content-of-llmsjsonl)). It is **off by default** —
turn it on per store view with `mageos_aeo/llms_txt/jsonl_enabled`.

---

## Format

Both documents follow the [llms.txt format](https://llmstxt.org), in this order:

1. an H1 with the organisation name;
2. a one-line blockquote summary from the organisation description (omitted when there is none);
3. details: lists and paragraphs with no headings — base URL, locale, search URL template,
   structured data, contact, and prose contributed by section providers (the FAQ);
4. H2 sections that are "file lists": every item is a markdown link, `- [name](url)`, optionally
   followed by `: notes`.

Nothing but link items appears under an H2. That is what the format asks for. Lighthouse's
`llms-txt` audit fails a file with no markdown link at all — which scores worse than serving no
file — and the reference parser (`llms_txt` on PyPI) stops with an error on any non-link line
under an H2.

Square brackets in link labels are replaced with round ones (`Men's [Sale]` becomes
`Men's (Sale)`): the reference parser cannot read a backslash-escaped label. Spaces and round
brackets in URLs are percent-encoded.

---

## Content of /llms.txt

```
# Organisation Name

> Description tagline

- Base URL: https://example.com
- Locale: en_GB
- Search URL template: `https://example.com/catalogsearch/result?q={query}`
- Structured data: schema.org JSON-LD on product pages (Product, Book, SoftwareApplication, VisualArtwork)
- Contact for automated queries: <ai@example.com>

Frequently asked questions:

- **Do you ship worldwide?** Yes, to every country in the EU and the UK.

## Key URLs

- [Home](https://example.com/): Store front page
- [Sitemap](https://example.com/sitemap.xml): XML sitemap of indexable pages

## Categories

- [What's New](https://example.com/what-is-new.html)
- [Women](https://example.com/women.html)
- [Gear](https://example.com/gear.html): 34 products

## Policies

- [Returns policy](https://example.com/returns)
- [Privacy and Cookie Policy](https://example.com/privacy-policy-cookie-restriction-mode): How we use your data
- [About us](https://example.com/about-us)
```

The Sitemap link points at the sitemap configured under Marketing → Site Map, and is left
out when the store view has none.

**Categories** are the storefront menu's top level, in the menu's order, with each one's product
count. `/llms-full.txt` lists the whole tree instead; what is listed and counted is described
[there](#content-of-llms-fulltxt).

**Policies**, in both documents, lists:

1. the **Returns Policy URL** (Stores → Configuration → MageOS SEO → SEO Merchant Policies → Return
   Policy), while **Enable Return Policy Schema** is Yes;
2. the CMS pages chosen under **Pages Listed in llms.txt** (Stores → Configuration → MageOS SEO →
   AI Information & Crawlers → AI Discoverability), per store view and in that order. Each is listed
   by its title, with its meta description as the note. A chosen page that is not active in the
   store view is left out, with a warning in the log naming it; a page that is the returns policy
   page is listed once.

The section is left out when there is nothing to list. Saving or deleting any CMS page queues a
rebuild, so a listed page's new title or description follows shortly.

The locale line is left out when the store view has no locale configured, the FAQ list when the
selected groups have no questions (the first 5 are shown), and the contact line when there is none
(see [Data sources](#data-sources)).

The document's own headings and labels ("Key URLs", "Base URL", "Store front page" and so on) are in the
store view's language (**General → Locale Options → Locale**), the language of everything it lists;
the example above is in English. The rebuild switches to each store view's environment before
writing, so the language follows the store view, not the admin or CLI user. The module ships
`i18n/en_US.csv`, `en_GB.csv` and `nl_NL.csv`; overriding a phrase and adding a locale work as MageOS_Seo's
[Translations](https://github.com/mage-os-lab/module-seo/blob/main/docs/translations.md) describes.

---

## Content of /llms-full.txt

Everything in `/llms.txt`, with the whole category tree in place of Categories, plus:

- Social profile URLs (from Organisation → Social profiles), in the details list
- Every FAQ of the selected groups, not just the first 5
- The schema.org types the pages can carry, and the product template list with labels, in the
  details list
- The category tree with product counts, as an indented list of links:

```
## Category Tree

- [Clothing](https://example.com/clothing.html): 245 products
  - [Women's](https://example.com/clothing/womens.html): 148 products
    - [Dresses](https://example.com/clothing/womens/dresses.html): 62 products
  - [Men's](https://example.com/clothing/mens.html): 97 products
```

The tree is the storefront menu's: active categories with **Include in Menu** set, in the menu's
order. A category left out of the menu is left out with its subcategories. Unlike the menu, every
level is listed: **Maximal Depth** (Catalog → Catalog → Category Top Navigation) limits the menu's
dropdowns, and every level below it is still a page.

A count is the number of products the category's page lists: enabled, in the store view's website
and visible in *Catalog* or *Catalog, Search*. An anchor category's count includes its
subcategories' products, as its page does. A category that lists nothing has no count. The counts
come from Magento's category product index, so:

- **stock is not taken into account.** With **Display Out of Stock Products** at No, the page can
  list fewer;
- **they are as current as the index.** With the indexers on Update by Schedule, they follow once
  the indexer has run. Enabling or disabling a product, or changing its visibility, does not queue
  a rebuild of its own: the nightly rebuild brings the counts up to date.

The section is left out when the store has no visible categories. If they cannot be read, the
store's build fails: FeedRegenerator logs it and keeps serving the previous file.

- Any sections contributed by bridge modules

---

## Content of /llms.jsonl

NDJSON: one JSON object per line, UTF-8, each line ending in a line feed. It is served as
`application/x-ndjson; charset=utf-8` and written to its file a line at a time while it is built
(see [feeds.md](feeds.md)).

Each product line is a schema.org `Product` node. One line from a rebuild of Magento's sample
catalogue, with the host changed to example.com:

```json
{"@context":"https://schema.org","@type":"Product","@id":"https://example.com/joust-duffle-bag.html","name":"Joust Duffle Bag","url":"https://example.com/joust-duffle-bag.html","offers":{"@type":"Offer","price":"34.00","priceCurrency":"GBP","availability":"https://schema.org/InStock","url":"https://example.com/joust-duffle-bag.html"},"sku":"24-MB01","description":"The sporty Joust Duffle Bag can't be beat - not in the gym, not on the luggage carousel, not anywhere. Big enough to haul a basketball or soccer ball and some sneakers with plenty of room to spare, it's ideal for athletes with places to go. Dual top handles. Adjustable shoulder strap. Full-length…","image":"https://example.com/media/catalog/product/m/b/mb01-blue-0.jpg"}
```

| Field | Present | Value |
|---|---|---|
| `@context` | always | `https://schema.org` |
| `@type` | always | `Product` |
| `@id`, `url` | always | the product URL |
| `name` | always | the store view's product name |
| `offers` | always | one `Offer` with `price`, `priceCurrency`, `availability` and `url`; `price` and `priceCurrency` only when the price is known |
| `sku` | when the product has one | |
| `description` | when the product has one | the short description, else the description: plain text on one line, at most 300 characters, cut at a word and ended with "…" |
| `image` | when the product has a base image | its absolute URL under the store's media URL |

The values:

- `price` is the product's final price in the store view's display currency, the price the
  storefront shows. It is written with two decimals, a full stop and no thousands separator
  (`1234.50`) whatever the store's locale, and `priceCurrency` is the current currency code.
  **When the price is not known, both are left out,** rather than written as `0.00`, which reads as
  free. The offer keeps its availability and URL. The price is not known when a composite product
  (configurable, grouped, bundle) is priced 0, which Magento does when no option can price it, or
  when the lookup throws, which is logged with the SKU. MageOS_Seo's `Model\Product\FinalPrice`
  decides this here and in the product pages' structured data alike.
- `availability` is `https://schema.org/InStock` or `https://schema.org/OutOfStock`, by the
  website's stock in MSI's stock index (`is_salable`, what the category listings filter on), read
  for 1,000 products at a time. The index does not subtract reservations, orders placed but not yet
  shipped: a product whose last units are all reserved stays InStock until shipping deducts them.
  When the lookup fails, the products it covered are written as OutOfStock and the failure is
  logged. Unlike the JSON-LD on product pages, a line has no `BackOrder`.

A product gets a line when it is enabled, assigned to the store view's website, visible in
*Catalog* or *Catalog, Search*, and in Magento's price index, which the feed reads its products
with. Products visible in *Search* only, and those not visible individually, are left out.
**Out-of-stock products have no line.** With **Display Out of Stock Products** at No, Magento's
default, that is because Magento leaves them out of the price index. A line
that cannot be encoded as JSON (invalid UTF-8 in a name, for example) is left out and logged as a
notice with the product's URL and SKU.

No JSON Schema file is published: there is no standard for `llms.jsonl`, and each product line is
a schema.org `Product` node.

### Adding lines

A bridge module appends whole nodes of any `@type` — a marketplace's vendors as `LocalBusiness`,
say — by implementing `MageOS\Aeo\Api\JsonlLineProviderInterface`:

```php
class VendorLines implements \MageOS\Aeo\Api\JsonlLineProviderInterface
{
    public function getAdditionalLines(int $storeId): array
    {
        return [
            ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => 'Studio A', 'url' => 'https://…'],
        ];
    }
}
```

```xml
<type name="MageOS\Aeo\Model\LlmsJsonl\JsonlBuilder">
    <arguments>
        <argument name="lineProviders" xsi:type="array">
            <item name="vendors" xsi:type="object">MyModule\Model\LlmsJsonl\VendorLines</item>
        </argument>
    </arguments>
</type>
```

Each node becomes one line, after all the product lines, in the order the providers are
registered. A provider adds lines; it cannot change the product lines.

---

## Clean URLs

No setup is required: a custom router serves `/llms.txt`, `/llms-full.txt` and
`/llms.jsonl` directly. It runs before core's URL-rewrite router, so while a file is
enabled for a store view its path belongs to this module. A URL rewrite or a CMS page
for that path never applies. To serve your own file at one of these paths, disable
that file for the store view, and the path falls through to rewrites and CMS pages.

Don't add URL rewrites for these paths: none is needed. The internal controller URLs
(`/mageos-aeo/...`) 301-redirect to the canonical paths.

---

## Generation & cache

These documents are pre-generated to files and served from there; a web request never builds
one. That machinery is documented once, in
**[feeds.md](feeds.md)**: the queue consumer and the nightly cron, what triggers a rebuild, the
cache policy and the streaming of large files, where the files are stored (including multi-server
deployments and their `app/etc/env.php` entry), file permissions, and the `seo:rebuild` command.

Worth knowing here: a rebuild of `/llms.txt` and `/llms-full.txt` is queued when the Organisation
settings or a FAQ change, when a category changes, and when a product is created, deleted or has
its category or website assignments changed — because the category tree carries product counts.
Product edits that change nothing in these documents do not queue one. A configuration change
queues one when it changes something the documents show: the locale, the Customer Support email,
FAQ Groups, Pages Listed in llms.txt, the return policy, the base URLs (`web/`) or the category URL
suffix (`catalog/seo/`). Switching a document on or off takes its file and cached responses away
at once and queues its rebuild; see feeds.md,
[Switching a document on or off](feeds.md#switching-a-document-on-or-off).

---

## Data sources

Both documents draw data from:

| Data | Source |
|---|---|
| Organisation name, description, URL, social profiles | Organisation record (store-scoped, same fallback as JSON-LD) |
| Locale | The store view's **General → Locale Options → Locale** (`general/locale/code`) |
| Sitemap link | Core's URL of the store view's most recently generated sitemap under **Marketing → Site Map**; left out when there is none. A new sitemap is picked up on the next rebuild (the nightly cron at the latest) |
| Schema template list | `SchemaBuilderPool::getAvailableTemplates()` |
| Category tree | Live `catalog_category_entity` collection, active categories only, level > 1 |
| FAQs | The groups selected under **FAQ Groups** (see [FAQ section](#faq-section)) |
| AI contact email | See [AI contact](#ai-contact) |

### AI contact

The address published for automated queries is the first of:

1. the Organisation's **Contact Email** (Marketing → SEO → Organization), the same address the
   `Organization` JSON-LD publishes as its `contactPoint`;
2. the store's **Customer Support** email (Stores → Configuration → General → Store Email
   Addresses), **unless it is still the value Magento ships**. Every installation starts with
   `support@example.com` there, and publishing that placeholder would tell agents to write to an
   address nobody reads. The shipped value is read from the installed modules' `config.xml`
   defaults, so a distribution that ships a different placeholder is recognised too;
3. none: the contact line is left out.

MageOS_Seo's `MageOS\Seo\Model\Organization\ContactEmail` makes this choice for both documents. To publish a
different address, set the Organisation's Contact Email.

### FAQ section

The FAQs come from the groups selected under **Stores → Configuration → MageOS SEO → AI
Information & Crawlers → AI Discoverability (llms.txt) → FAQ Groups**, per store view:

- the default is `global`, so a group of that name is included without configuring anything;
- the list offers every group identifier in use, plus `global`. That is every group any FAQ source
  has: MageOS_Faq's, under Marketing → SEO → FAQ Manager, and those of any other module that registers a
  `FaqSourceProviderInterface` (its `getIdentifiers()`);
- groups are read in the order the setting stores them — the list's alphabetical order when saved
  from the admin, the given order with `bin/magento config:set` — and each group's questions in their
  own sort order;
- `/llms.txt` carries the first 5 questions across all selected groups, `/llms-full.txt` all of them;
- select none to leave FAQs out; a group with no active questions for the store view adds nothing.

Why not every group: a group is usually placed on one page through the FAQ widget or Page Builder —
a product's sizing questions, a returns page — and llms.txt is a summary of the whole site. Pick the
groups that answer site-wide questions.

To build the section some other way, replace the `faq` section provider in your module's `di.xml`
with your own `LlmsTxtSectionProviderInterface` implementation (see below):

```xml
<type name="MageOS\Aeo\Model\LlmsTxt\LlmsTxtBuilder">
    <arguments>
        <argument name="sectionProviders" xsi:type="array">
            <item name="faq" xsi:type="object">MyModule\Model\LlmsTxt\FaqSectionProvider</item>
        </argument>
    </arguments>
</type>
```

---

## Adding content from a bridge module

Register a `LlmsTxtSectionProviderInterface` implementation in your bridge module's `di.xml`.
Where the output goes depends on its first line:

- output starting with `## ` is an H2 section, appended after the built-in sections. Every item
  under the heading must be a markdown link, `- [name](url)`, optionally followed by `: notes`;
- any other output is details — paragraphs or lists, no headings — placed before the first H2.

```php
// MyModule/Model/LlmsTxt/MySectionProvider.php
class MySectionProvider implements \MageOS\Aeo\Api\LlmsTxtSectionProviderInterface
{
    public function getConciseSection(): string
    {
        // Details: no heading, so it goes before the first H2
        return "This marketplace hosts 42 independent makers.";
    }

    public function getFullSection(): string
    {
        // An H2 file list: link items only, or '' to contribute nothing
        return "## Vendors\n\n" . $this->buildVendorLinks(); // "- [Studio A](https://…): Ceramics"
    }
}
```

```xml
<!-- MyModule/etc/di.xml -->
<type name="MageOS\Aeo\Model\LlmsTxt\LlmsTxtBuilder">
    <arguments>
        <argument name="sectionProviders" xsi:type="array">
            <item name="mySection" xsi:type="object">
                MyModule\Model\LlmsTxt\MySectionProvider
            </item>
        </argument>
    </arguments>
</type>
```

Return an empty string from either method to contribute nothing to that document. Sections are appended in the order they are registered in `di.xml`.
