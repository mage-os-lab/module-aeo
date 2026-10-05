# Pre-generated feeds

Three documents are generated in the background and served from files:

| URL | What it is | Documented in |
|---|---|---|
| `/llms.txt` | Concise site summary for LLM crawlers | [llms-txt.md](llms-txt.md) |
| `/llms-full.txt` | The extended version | [llms-txt.md](llms-txt.md) |
| `/llms.jsonl` | One JSON-LD `Product` node per line | [llms-txt.md](llms-txt.md#content-of-llmsjsonl) |

Hreflang alternates for the whole catalogue are in `sitemap.xml`, beside each URL — see
MageOS_Seo's [sitemap.md](https://github.com/mage-os-lab/module-seo/blob/main/docs/sitemap.md). The
dedicated `/hreflang-sitemap.xml` MageOS_Seo used to serve is retired; the path answers 404.

This page covers what they have in common: how they are built, when they are rebuilt, where
they are stored and what they cost. What each document *contains* is in the pages above.

---

## Generation & cache

The documents are **pre-generated to files** (default `var/mageos_aeo/store_<id>/`),
mirroring core `Magento_Sitemap`. Two background processes write them:

- MageOS_Seo's `mageosSeoFeedRegenerate` **queue consumer** rebuilds a feed group whenever it
  is invalidated (started by the default `consumers_runner` cron, or your process
  manager e.g. supervisor). Duplicate invalidations are collapsed: at most one build
  per feed group is queued at a time, and changes arriving during a build queue
  exactly one follow-up rebuild;
- the `mageos_aeo_regenerate_feeds` **cron job** (nightly) does a full rebuild as a
  safety net for changes that carry no invalidation event.

Web requests **never** build the documents. An invalidation only queues a rebuild: the
current files keep being served until the consumer has written their replacements.
Each file is written to a temporary file and renamed into place, so a request never
sees a partially written document. When a file does not exist yet (fresh install, new
store view), the controller queues a rebuild and answers with `Retry-After` until
the consumer has written it: `/llms.txt` answers `404` (with `Cache-Control: no-store`),
`/llms-full.txt` and `/llms.jsonl` answer `503`. `/llms.txt` differs because
Lighthouse's llms-txt audit scores a server error as a failure but treats a `404` as
"not applicable". When a file is disabled in the configuration, its path is not
claimed at all, so another module or a static file can serve it. Requests with query strings — and the internal
`/mageos-aeo/...` controller URLs — are 301-redirected to the canonical path so they
cannot be used to force cache misses.

A queued rebuild that the consumer has not picked up within one hour is queued again,
and a warning is logged (`the "<group>" feed rebuild … was never picked up`). If you
see that warning, the consumer is not running: check your cron or process manager.

Every rebuild's result is also shown in the admin, whichever process ran it: a store view's
feed that could not be rebuilt, or was written incomplete, is listed in the System Messages
bar and once in the inbox, with the time the nightly cron retries it, until a rebuild gets
through. Two things make a feed incomplete rather than failed: a stock lookup that fails while
`llms.jsonl` is built (that batch is listed as out of stock), and a storage directory that is
refused (see [below](#where-the-files-are-stored)). See MageOS_Seo's
[rebuild-problems.md](https://github.com/mage-os-lab/module-seo/blob/main/docs/rebuild-problems.md).

### Only one rebuild runs at a time

Three things write the same files — the consumer, the nightly cron and
`bin/magento seo:rebuild` — and none of them is guaranteed to be alone:
`consumers_runner` can be configured to run several processes of a consumer, and on a
multi-server install the cron runs on every node. A shared lock
(`Magento\Framework\Lock\LockManagerInterface`, so it spans processes and hosts) lets one
rebuild through at a time:

- the **consumer** puts its message back on the queue, so the invalidation is not lost;
- the **cron** skips and logs at info level — the process holding the lock is doing the same
  work, and the cron comes round again;
- the **CLI** reports that a rebuild is already running and exits non-zero, so a deployment
  script cannot mistake it for a completed build.

Nothing needs configuring for this. The lock uses whichever lock provider the installation
already has (database by default; Zookeeper, Redis or the filesystem if configured).

### When a rebuild runs

The queue is MageOS_Seo's, and so are its rules; see its
[sitemap.md, "When a rebuild runs"](https://github.com/mage-os-lab/module-seo/blob/main/docs/sitemap.md#when-a-rebuild-runs).
In short:
- a change is queued once the save that made it commits, so AMQP consumers never build from the
  data before it;
- each build starts from current data (the Organization, stock, configuration), however long the
  consumer has run;
- a consumer waits for a rebuild another process is running rather than spinning;
- with indexers on Update by Schedule, a rebuild can still run before the indexers catch up, and
  `/llms.jsonl` then shows the price or stock from before. The next change, or the nightly
  rebuild, catches up.

### Queue transport

The rebuild queue is MageOS_Seo's, shared with the sitemaps. Its `etc/queue_consumer.xml`,
`etc/queue_publisher.xml` and `etc/queue_topology.xml` name **no connection and no
`maxMessages`**, exactly as core's own queue configuration does. The topic therefore travels over
whatever transport the installation runs — the database queue by default, AMQP where that is
configured — and honours the install's `queue/consumers_max_messages`. There is nothing to
override in `env.php` to move it onto RabbitMQ.

No session is started for these requests. A session cookie stops shared caches storing a
response at all, and makes PHP emit `Pragma: no-cache` over the policy below; none of these
endpoints read session state.

Responses are served with `Cache-Control: public, max-age=300, s-maxage=86400`, so Varnish,
CDNs and the built-in full page cache keep them for **24 hours** and browsers for **5 minutes**.
They are tagged `MAGEOS_AEO_LLMS` (`/llms.txt`), `MAGEOS_AEO_LLMS_FULL` (`/llms-full.txt`) and
`MAGEOS_AEO_LLMS_JSONL` (`/llms.jsonl`). After rebuilding a feed group, the consumer and the
cron purge that group's tags, so cached copies are replaced as soon as the new files exist;
switching a document on or off purges its tag at once (see
[below](#switching-a-document-on-or-off)). A browser's copy cannot be purged, which is why it is
kept for minutes rather than a day.
(The retired `/hreflang-sitemap.xml` was tagged `MAGEOS_SEO_HREFLANG_SITEMAP`; the upgrade that
retires it purges that tag once, along with its files.)

> **With the built-in full page cache**, Magento replaces the client-facing headers on every
> cacheable page with `Pragma: no-cache` and `Cache-Control: max-age=0, must-revalidate`,
> keeping the real policy in `X-Magento-Cache-Control`. That is core behaviour, not a setting
> of this module: browsers will re-request the feeds on each visit. Varnish and CDNs receive
> the policy as written.

### Large feeds are streamed

A stored feed of up to 0.5 MiB is answered from memory, so the built-in full page cache can store
it. A larger one — `/llms.jsonl` of a large catalogue — is sent straight from its file, 4 KiB at a
time: serving it takes the same memory whatever its size, and a feed larger than PHP's
`memory_limit` is served rather than ending the request with a fatal error. The response carries
`Content-Length`, and a `HEAD` request gets the headers only.

The built-in full page cache does not store a streamed feed. It keeps a response as one string,
which is the memory streaming saves, so with it a large feed is read from disk for every request
that reaches Magento. It also leaves the response's headers alone, so browsers keep a streamed
feed for 5 minutes under either cache. Varnish and CDNs cache it by its headers, like the smaller
ones.

Measured on the test installation with PHP's `memory_limit` lowered to 64M: a 200 MiB
`/llms.jsonl` was served whole, three requests at once included, with each request peaking at
16–26 MB, under both cache settings.

---

## When a rebuild is queued

| Change | `/llms.txt`, `/llms-full.txt` | `/llms.jsonl` |
|---|---|---|
| Product saved | new product, or its categories or websites changed (product counts) | always |
| Category saved | always | — |
| Category moved | always | — |
| Product deleted | always | always |
| Category deleted | always | — |
| Mass attribute update | — | always |
| Mass website assignment change | always | always |
| Organisation settings saved | always | — |
| FAQ saved or deleted | always | — |
| Configuration: locale, Customer Support email, FAQ Groups, Pages Listed in llms.txt, return policy | when the value changes | — |
| Configuration: `web/` (base URLs), `catalog/seo/` (URL suffixes, category paths) | when the value changes | when the value changes |
| Configuration: `currency/`, price scope, Display Out of Stock Products | — | when the value changes |
| A document switched on or off | see [below](#switching-a-document-on-or-off) | see [below](#switching-a-document-on-or-off) |

Configuration counts when it is saved from the admin or with `bin/magento config:set`, or
removed with "Use Default", at any scope, and only once the save is committed. Values locked
into `app/etc/env.php` never reach the database, and so are not seen.

Mass actions — the admin grid's "Update attributes", mass enable/disable and mass website
assignment, and anything else going through `Magento\Catalog\Model\Product\Action` — write
straight to the catalogue tables without saving the products, so no save event reports them.
They are covered separately (a plugin for attributes, the `catalog_product_to_website_change`
event for websites).

Deleting a store view also removes that store's feed directory. Deleting a **store group or a
website** takes its store views with it in the database, without dispatching a `store_delete`
event for any of them; their feed directories are removed by the next full rebuild (the nightly
cron, or `seo:rebuild` with no `-g`), which sweeps directories whose store view
no longer exists. Until then nothing serves them: a request resolves feeds for the current store
view, and theirs is gone.

A feed that no store view can build is never queued: `/llms.jsonl` while it is disabled in
every store view (the default), `/llms.txt` + `/llms-full.txt` when both are disabled
everywhere. The one exception is switching a document on or off, below. The logic lives in
`MageOS\Aeo\Model\Feed\LlmsInvalidationPolicy`, and for configuration in
`MageOS\Aeo\Model\Feed\FeedConfigDependencies`.

A FAQ or the Organisation queues the llms documents through its model's own save and delete
events (`mageos_faq_*`, `mageos_seo_organization_*`), so a save from the admin, the REST API, an
import or a data patch counts alike.

Changes that no event reports — native CSV imports, direct database writes, configuration
locked into `app/etc/env.php`, imported currency rates — are picked up by the nightly rebuild.

The XML sitemaps configured under Marketing → Site Map are rebuilt on change through the same
queue, one kind of page at a time; what queues them is in MageOS_Seo's
[sitemap.md](https://github.com/mage-os-lab/module-seo/blob/main/docs/sitemap.md#keeping-sitemaps-current).

### Switching a document on or off

Saving **Enable /llms.txt**, **Enable /llms-full.txt** or **Enable /llms.jsonl** with a new value,
at any scope, or removing a store view's or website's own value, takes that document out of
service as soon as the save is committed:

- its file is removed for every store view under the scope saved — all of them for the default
  scope, the website's for a website;
- its cached responses are purged (its own tag only: switching `/llms.jsonl` leaves the cached
  `/llms.txt` alone);
- its group's rebuild is queued, even when no store view has the document enabled any more.

Switched off, nothing can serve the old document, from a file or from a cache. Switched on, the
file from before — which may list products removed in the meantime — is not served as current;
the rebuild writes a new one. Until it has run, `/llms.txt` answers `404` and the other two
`503`, with `Retry-After`. A store view whose own value overrides the change loses its file too,
until the same rebuild.

Every rebuild also removes a disabled document's file from each store view's directory, which
covers a switch nothing reported.

---

## Build cost

The large feed is **streamed to its file** rather than assembled in memory: `/llms.jsonl` is
built one product per line from a paged collection. Peak memory is that of one page of
products, not of the whole document — at 100k SKUs the document runs to tens of megabytes.

Availability is read for a page of 1,000 products in one query from MSI's stock index. Each
product's price is its own `PriceInfo`, the price a visitor is shown: its tier prices and catalog
rules, and a configurable's children, are read per product. Measured on Luma's sample catalogue
(180 lines, one store view): 816 queries and 1.7 seconds, where a per-product stock check made it
2,960 queries and 7.0 seconds. The cost grows with the catalogue; it is paid in the queue consumer
and the nightly cron, never in a web request.

MageOS_Seo streams the XML sitemaps the same way, a page of the catalogue at a time; see its
[sitemap.md](https://github.com/mage-os-lab/module-seo/blob/main/docs/sitemap.md).

---

## Where the files are stored

`mageos_aeo/feeds/storage_dir` is empty by default, which means `var/mageos_aeo`.
Because it is an absolute path set from the admin panel, what it may point at is restricted:

- **inside the installation, `var/` only.** The root itself and every other standard
  directory — `app/`, `bin/`, `dev/`, `generated/`, `lib/`, `pub/`, `setup/`, `update/`,
  `vendor/` — are refused, so no configuration value can reach the codebase;
- **no hidden directories** anywhere, in the path as typed or as resolved, so `.git`, `.ssh` and
  friends are unreachable even under `var/`, and a link with a visible name cannot stand for one;
- no `..`, and the path is resolved before it is judged, so a symlink inside `var/`
  cannot stand for a target outside it. The feeds are then stored in the resolved directory, so
  a link changed after the check does not move them;
- the directory must already exist and be writable, but **not by every user** (see
  [Permissions](#permissions)).

The rules are applied twice: when the value is saved, with the reason shown in the admin, and
again when it is read — a row can reach `core_config_data` from a data patch, a deployment tool
or straight from the database, and a directory these rules refuse is never written to however it
arrived. A refused value is logged and the feeds fall back to `var/mageos_aeo` rather than
failing. Each rebuild that falls back is shown in the admin as incomplete until the setting is
fixed: on a multi-server install, the web servers may not see this host's `var/`.

### Symbolic links are not followed

No feed storage operation follows a symbolic link. The storage directory, each `store_<id>/`
directory and each feed file must be the real thing:

- **a feed file that is a link** is not served: the request answers as for a missing file and
  queues a rebuild, which replaces the link with the file;
- **a store directory or the storage directory that is a link** is neither read nor written:
  requests answer as for a missing file, and the rebuild fails and is shown in the admin;
- **deleting** removes a link itself, never what it points to. A store directory is emptied file
  by file, never recursively; one holding a directory, which this module never creates, is left
  in place and logged.

This includes **`var/mageos_aeo` itself**: to keep the feeds on another disk, set the storage
directory (below) rather than linking it. `var/` may be a link, since it is resolved first.

PHP cannot open or delete relative to a directory it has already checked (it has no `openat()`),
so a process able to rename entries in a storage directory could still race an operation, between
the check and the act. Keeping the storage directories writable only by the users that run
Magento closes that.

### Permissions

Feed files are written with mode `0640` and the feed directories (`var/mageos_aeo/` and
each `store_<id>/`) with `0750`, whatever the process umask. The user running cron and
the consumer and the web server's PHP user must therefore be the same user or share a
group — Magento's standard file-ownership model. With a custom `storage_dir`, the root
directory you configure keeps its own permissions; only the `store_<id>/` directories
below it are set to `0750`.

A storage directory **every user can write to is refused**, since anyone could then put a link
or a file of their own in it: a configured directory both when the setting is saved and each time
it is used, and `var/mageos_aeo/` or a `store_<id>/` directory that cannot be set to `0750` — one
another user owns — when a feed is read or written. Group-writable directories are fine.

### Storing the feeds outside var/ (multi-server)

`var/` is host-local, so a deployment with more than one web server needs a mount all of them
share with the host running cron and the queue consumer — which is, by definition, outside
`var/`. Permit that root in `app/etc/env.php`, which is deployment configuration the admin
panel cannot edit:

```php
<?php
return [
    'db' => [ /* ... */ ],

    // Roots the SEO feeds may be stored under, in addition to var/.
    'mageos_aeo' => [
        'feed_storage_roots' => [
            '/mnt/shared/mageos-aeo',
        ],
    ],

    'MAGE_MODE' => 'production',
];
```

Then set **Stores → Configuration → MageOS SEO → AI Information & Crawlers → Feed Storage →
Storage Directory** to that path, or
anything below it — `/mnt/shared/mageos-aeo/site-a` is accepted by the same entry.

Setting it up:

1. **Every node needs the `env.php` entry** — each web server, the cron host, and whatever runs
   the queue consumer. `env.php` is per-machine, and the check runs wherever the code runs.
2. **The root must exist on the node doing the checking.** It is resolved before it is compared,
   and a root that cannot be resolved is dropped from the permitted list. If the mount is missing
   on the admin node, saving the field is refused there even though cron would have been happy.
3. **The mount must be writable by the feed writers and readable by PHP-FPM**, per the
   permissions note above, and not writable by every user — a share mounted `0777` is refused.
4. **`bin/magento setup:config:set` will not write this key** — it only handles the options it
   knows about. Add it by editing `env.php`, or through whatever templating your deployment uses.
5. A single string is accepted as well as a list (`'feed_storage_roots' => '/mnt/shared/feeds'`),
   though the list form is the one to prefer.

Without an entry, nothing outside `var/` is accepted: the key is absent, the permitted list is
empty, and the admin field explains what is wrong when you save it.

**A declared root extends where feeds may go; it does not open up the codebase.** The
installation rule is applied first, so a root pointing at `pub/`, `app/`, `vendor/` or any other
part of the installation is ignored rather than obeyed — an entry copied between environments,
or left behind by a template, cannot make the web root writable by this module. Declaring a
directory under `var/` is harmless but pointless: `var/` is allowed anyway.

---

## Rebuilding by hand

Every `setup:install` / `setup:upgrade` queues a rebuild of the feeds the store views can
build, so a fresh install or a deployment that cleared `var/` does not wait for the nightly
cron.

To rebuild immediately — in a deployment script, or after changing feed configuration —
run:

```bash
bin/magento seo:rebuild            # every feed, every active store view
bin/magento seo:rebuild -g llms    # one group: llms | jsonl
```

The command is MageOS_Seo's. It also rebuilds a kind of page in the XML sitemaps,
`-g sitemap-products` and so on; see MageOS_Seo's
[sitemap.md](https://github.com/mage-os-lab/module-seo/blob/main/docs/sitemap.md#changes-that-are-not-seen).
With no `-g` it rebuilds the feeds only.

It builds in the running process (no queue consumer needed), replaces each file in place,
purges the rebuilt groups' cache tags, and exits non-zero if any store view failed. To
process rebuilds that are already queued instead, run `bin/magento queue:consumers:start
mageosSeoFeedRegenerate --max-messages=10`; otherwise wait for the consumer or the nightly
cron.
