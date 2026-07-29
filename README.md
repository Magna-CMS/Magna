<p align="center">
  <img src="screenshots/magna-logo.svg" alt="Magna CMS" width="100">
</p>

<h1 align="center">Magna CMS</h1>

<p align="center">
  <strong>The headless CMS built Simple for Laravel developers.</strong><br>
  The Ultimate Operating Sytem for Websites and Applications — with a real plugin ecosystem and a website out of the box when you want one.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/version-v1.3.0--beta-orange?style=for-the-badge" alt="v1.3.0-beta">
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 13">
  <img src="https://img.shields.io/badge/Filament-5-purple?style=for-the-badge" alt="Filament 5">
  <img src="https://img.shields.io/badge/license-MIT-blue?style=for-the-badge" alt="MIT License">
</p>

<p align="center">
  <a href="#-what-is-magna">What is Magna?</a> •
  <a href="#-why-magna-is-different">Why Magna?</a> •
  <a href="#-admin-panel">Admin Panel</a> •
  <a href="#-quick-start">Quick Start</a> •
  <a href="#-plugin-system">Plugins</a> •
  <a href="#-how-magna-compares">Comparison</a> •
  <a href="#-faq">FAQ</a>
</p>

---

## Screenshots

<table>
  <tr>
    <td width="33%">
      <img src="screenshots/01-dashboard.png" alt="Dashboard — real-time activity feed, plugin widgets">
      <p align="center"><sub>Dashboard with live activity feed</sub></p>
    </td>
    <td width="33%">
      <img src="screenshots/02-type-builder.png" alt="Content Type Builder — visual schema designer">
      <p align="center"><sub>Visual Content Type Builder</sub></p>
    </td>
    <td width="33%">
      <img src="screenshots/04-media-library.png" alt="Media Library — smart storage analytics">
      <p align="center"><sub>Media Library with storage analytics</sub></p>
    </td>
  </tr>
  <tr>
    <td width="33%">
      <img src="screenshots/05-plugins.png" alt="Plugins — one-click install and manage">
      <p align="center"><sub>Plugin management — enable, disable, uninstall</sub></p>
    </td>
    <td width="33%">
      <img src="screenshots/08-api-keys.png" alt="API Keys — scoped delivery and management tokens">
      <p align="center"><sub>Scoped API key management</sub></p>
    </td>
    <td width="33%">
      <img src="screenshots/07-roles.png" alt="RBAC Roles — fine-grained permission system">
      <p align="center"><sub>Role-based access control</sub></p>
    </td>
  </tr>
  <tr>
    <td width="33%">
      <img src="screenshots/06-users.png" alt="Users — manage users with per-role 2FA enforcement">
      <p align="center"><sub>User management with 2FA status</sub></p>
    </td>
    <td width="33%">
      <img src="screenshots/09-settings.png" alt="Settings — comprehensive configuration panel">
      <p align="center"><sub>Comprehensive settings panel</sub></p>
    </td>
    <td width="33%">
      <img src="screenshots/10-audit-log.png" alt="Audit Log — append-only immutable action log">
      <p align="center"><sub>Append-only audit log</sub></p>
    </td>
  </tr>
  <tr>
    <td colspan="3">
      <img src="screenshots/11-system-info.png" alt="System Insights — live runtime dashboard showing PHP, Laravel, DB, cache, plugins">
      <p align="center"><sub>System Insights — live runtime dashboard (PHP version, Laravel LTS, DB driver, cache status, active plugins)</sub></p>
    </td>
  </tr>
</table>

---

## 🚧 Status

**Magna is in active development — currently in public beta (v1.3.0-beta).** We are building in public, spec-first: every subsystem is fully specified *before* it is coded, and the specifications live in this repository. Star the repo to follow the road to stable 1.0.

**Working today:**  You Can install & try Magna CMS in your sever using Web Installer or CLI .

## Download Magna

Ready to try Magna CMS? Grab the latest release — download it, run the installer, and you're ready to go.

[![Download Magna CMS](https://img.shields.io/badge/Download-Magna%20CMS%20v1.3.0--beta-7c3aed?style=for-the-badge&logo=github&logoColor=white)](https://github.com/Magna-CMS/Magna/releases)

**[⬇ Download Magna CMS v1.3.0-beta](https://github.com/Magna-CMS/Magna/releases)**


---

## 💡 What is Magna?

Magna is an open-source, **API-first headless CMS built on Laravel**. You model content in a visual type builder, editors manage it in a beautiful Filament 5 admin panel, and any frontend — Next.js, Nuxt, Flutter, a native app, an AI agent — consumes it through a fast, scoped REST API.

Magna follows a **Small Core Architecture** with exactly two things in core:

1. **The Kernel** — authentication, RBAC, plugin system, events, settings, audit log, API infrastructure.
2. **The Content Engine** — content types, fields, entries, drafts, revisions, localization, relationships, media, scheduled publishing, schema-as-code.

Everything else is a plugin — blog, SEO, forms, e-commerce, documentation, AI. And a plugin is just a **Composer package** with a manifest file.

> Keep the core small — but the core of a CMS is content. Everything that is not kernel or content is a plugin.

---

## 🚀 Why Magna is Different

The headless CMS space is crowded. Here is exactly what Magna does that others don't — every claim is specified in this repo and enforced in CI:

### 🐘 1. Laravel-Native, PHP-Hosting-Friendly

Every major open-source headless CMS today runs on Node. Magna brings a modern headless platform to the **millions of Laravel/PHP developers** and the vast world of ordinary PHP hosting — Eloquent-native extensibility, Composer-based plugins, deployable anywhere PHP runs. Statamic is Laravel but flat-file and theme-oriented. Twill is an admin package. The **Laravel-native, database-backed, headless-first** slot is empty. Magna fills it.

### 🔀 2. Hybrid Mode — Headless First, Website Optional

Headless purity has a famous cost: install it and you see an API. Magna's answer is **Magna Pages**, an optional official plugin that turns the *same install* into a rendered website — block editor, themes, live preview — with zero second deployment. Pages renders the exact JSON the API serves, so any Pages site can go fully headless later without migration. Don't want it? Don't install it; the core stays purely headless.



### 🔐 3. Security as a Process with Proof

Target: **OWASP ASVS Level 2**, verified by a third-party audit before 1.0, results published. Security is on by default — not documented as "recommended hardening":

- Argon2id password hashing
- Per-role **enforceable** TOTP 2FA with recovery codes
- Scoped, expiring, hashed-at-rest API tokens (shown once)
- Exponential-backoff login lockout
- Default-deny CORS on management APIs
- Uploads content-sniffed and re-encoded to strip payloads
- Append-only audit log with SIEM export
- Registration disabled by default
- Self-locking installer (returns 404 forever after setup)
- `composer audit` + taint analysis in CI

And a genuine first: **field-level encryption as a schema attribute** — mark any content field `"encrypted": true` and it encrypts at rest. No other mainstream open-source CMS offers that as a first-class primitive.

### 📐 4. Schema as Code

Content types are versionable files. Build your model in the visual type builder, export it, commit it, and `magna:schema:sync` replays it on staging and production — with a diff preview and destructive-change guards. Content modeling finally works like migrations: reviewable, repeatable, in git. Under the hood: **no EAV** — each content type gets a real table with real columns and real indexes.

### 🧩 5. Plugins Without the Malware Economy

Plugins are **Composer packages** — versioning, dependency resolution, and distribution come from infrastructure the PHP world already trusts, not uploaded ZIP files (the WordPress model that made theme/plugin malware an industry). Every plugin declares its capabilities in a manifest shown at install time, like phone app permissions. Extension points are **typed PHP interfaces, semver-guaranteed from 1.0**.

### 🛡️ 6. Fault-Tolerant Plugin Runtime

Every plugin hook runs through a guarded dispatcher: a crashing plugin costs its own widget, not your page. A **circuit breaker** auto-disables repeat offenders. A shutdown handler attributes even fatal errors to the responsible plugin. Heavy hooks run on queue workers. `MAGNA_SAFE_MODE=1` boots with all plugins off as a rescue hatch.

### ✍️ 7. Block Editor That Respects Your Time

Content is composed from **portable JSON blocks** — rendered as Blade views by Pages, or as your own React/Vue components headlessly. Structured block list with live preview via signed draft URLs. Draft preview for Next.js/Nuxt is first-class. We deliberately did not clone Gutenberg — a great structured editor is 80 % of the value at 5 % of the cost.

---

## 🖥️ Admin Panel

The Magna admin panel is built on **Filament 5** — the most powerful admin framework in the PHP ecosystem. It ships with a beautiful, fully-responsive dark UI with global search, real-time notifications, and a keyboard-first workflow.

### Content Type Builder

<img src="screenshots/02-type-builder.png" alt="Content Type Builder">

Design your content schema visually. Add fields (text, rich text, number, date, boolean, relation, media, JSON, and more), toggle localization per field, enable draft/publish workflow per type. Changes export to a schema file you can commit, review, and sync across environments.

### Media Library

<img src="screenshots/04-media-library.png" alt="Media Library">

A full digital asset management system — not a file picker. Storage analytics show total space used, file type distribution (images, PDFs, video, other), and available capacity at a glance. Upload, organize into folders, search by name or title, view as grid or list, recycle bin with recovery.

### Plugin Management

<img src="screenshots/05-plugins.png" alt="Plugin Management">

Install, enable, disable, update, and uninstall plugins from the admin panel. Each plugin shows its version, active status, author, and description. Plugins auto-run their migrations on activation — no manual `php artisan migrate` needed.

### Scoped API Keys

<img src="screenshots/08-api-keys.png" alt="API Keys">

Generate API keys scoped to **Delivery** (read-only public content) or **Management** (full write access). Keys expire on a configurable schedule, are hashed at rest and shown only once — exactly like GitHub personal access tokens.

### Role-Based Access Control

<img src="screenshots/07-roles.png" alt="Roles">

Fine-grained RBAC with custom roles. Assign granular permissions per role — each role defines exactly which resources and actions it can access. Super Admin bypasses all permission checks. Default roles: Administrator, Editor, Viewer — fully customizable.

### User Management with 2FA

<img src="screenshots/06-users.png" alt="Users">

Manage users with status tracking and 2FA enforcement. Per-role, admins can **require** TOTP 2FA — any user in that role who hasn't set up 2FA is blocked from the admin panel until they do.

### Settings

<img src="screenshots/09-settings.png" alt="Settings">

A comprehensive settings panel organized into sections: General, Localization, Content, Media, Email, Storage, URLs & Frontend, Security. Each section is deep — for example, Security controls session lifetime, password policy, login rate limits, IP allow-lists, CORS origins.

### Append-Only Audit Log

<img src="screenshots/10-audit-log.png" alt="Audit Log">

Every admin action — create, update, delete, permission change, login, API key generation — is written to an append-only log. Designed for compliance: rows can never be updated or deleted, only queried and exported. Supports SIEM export via webhook.

### System Insights

<img src="screenshots/11-system-info.png" alt="System Insights">

A live runtime dashboard showing everything about your installation at a glance:

- **Engine PHP** — exact PHP version with production-readiness status
- **Database Driver** — active driver (MySQL, PostgreSQL, SQLite) and client engine version
- **Environment Target** — `local` / `staging` / `production` with debug mode status
- **Cache Service** — active cache backend (Redis, database, file) and connection status
- **Framework Runtime Versions** — Magna CMS edition, Laravel version (LTS tagged), PHP CLI version
- **Database & Services Node Configuration** — DB driver, version, connection details
- **Active Plugin Modules** — installed / enabled / disabled plugin counts at a glance
- **Environment Flag & Debug** — toggle `APP_DEBUG` from the UI without editing `.env`; session lifetime and domain host visible inline

One page that tells you exactly what's running, what's healthy, and what needs attention — no SSH required.

---

## 🔌 Plugin System

A Magna plugin is a **Composer package** with a `magna.json` manifest:

```json
{
  "id": "magna/docs",
  "name": "Magna Docs",
  "version": "1.0.0",
  "description": "Hierarchical documentation with a delivery API.",
  "author": "Magna Team",
  "requires": {
    "magna/magna": "^1.0"
  },
  "permissions": [
    "content:read",
    "content:write",
    "media:read"
  ]
}
```

**How it works:**

1. Drop the plugin into `/plugins-dev/` or install via Composer.
2. Open **Admin → Plugins → Add New Plugin**.
3. Enable it — Magna auto-runs its migrations, registers its routes, and adds its admin sections.
4. Disable anytime with a single click. The circuit breaker auto-disables crashing plugins before they take your site down.

**Building a plugin** — full guide in [plugin-development-guide.md](docs/plugin-development-guide.md):

```bash
# Scaffold a new plugin
php artisan magna:plugin:make my-plugin

# Plugin structure
plugins-dev/vendor/my-plugin/
├── magna.json            # manifest
├── composer.json
├── src/
│   ├── MyPluginServiceProvider.php
│   └── Models/           # Eloquent models
├── database/
│   └── migrations/
└── resources/
    └── views/
```

### Official Plugins

| Plugin | Description | Status |
|---|---|---|
| **[Magna Docs](https://github.com/Magna-CMS/Magna-Docs)** | Hierarchical documentation with Markdown editor, collections, multi-language, REST API | `v1.0.0` ✅ |
| **Magna Pages** | Block editor, themes, live preview — turns Magna into a full website | Coming soon |
| **Magna Blog** | Posts, categories, tags, RSS, SEO | Coming soon |
| **Magna Forms** | Form builder, submission storage, email notifications | Coming soon |
| **Magna SEO** | Meta tags, sitemaps, OpenGraph, structured data | Coming soon |
| **Magna Commerce** | Product catalog, inventory, orders | Coming soon |

---

## ⚡ Quick Start

### Requirements

- PHP 8.3+
- Composer
- MySQL 8+ / PostgreSQL 14+ / SQLite (development)
- Node.js 20+ (for asset compilation)

### Step 1 — Get the code running

```bash
git clone https://github.com/Magna-CMS/Magna.git my-cms
cd my-cms
composer install
npm install && npm run build
php artisan serve
```

Open `http://localhost:8000` — every request lands on the **browser installer** until setup is complete.

---

## 🖱️ Browser Installer

No config files to edit, no CLI setup commands. Open the site and the installer guides you through four steps — then permanently disables itself.

<table>
  <tr>
    <td width="50%">
      <img src="screenshots/install-01-requirements.png" alt="Step 1 — Requirements check">
      <p align="center"><sub><strong>Step 1 — Requirements</strong></sub></p>
    </td>
    <td width="50%">
      <img src="screenshots/install-02-site.png" alt="Step 2 — Site configuration">
      <p align="center"><sub><strong>Step 2 — Site</strong></sub></p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <img src="screenshots/install-03-database.png" alt="Step 3 — Database connection">
      <p align="center"><sub><strong>Step 3 — Database</strong></sub></p>
    </td>
    <td width="50%">
      <img src="screenshots/install-04-account.png" alt="Step 4 — Admin account">
      <p align="center"><sub><strong>Step 4 — Admin account</strong></sub></p>
    </td>
  </tr>
</table>

### Step 1 — Requirements check

The installer opens with a live **server requirements scan**. Every required item — PHP 8.3+, PDO, mbstring, OpenSSL, ctype, fileinfo, cURL, XML/DOM, tokenizer, writable `storage/` and `bootstrap/cache/` directories, writable `.env` — is probed and shown as `PASS` or `FAIL` with a plain-language explanation of how to fix it. Required failures block progress; recommended items (Argon2id, intl, GD, Redis) warn without blocking. You cannot proceed until everything required is green.

### Step 2 — Site configuration

Set your **site name** and **site URL** (http/https only — the installer rejects other schemes). Toggle **"This is a production site"** to enable production mode, which sets `APP_ENV=production` and `APP_DEBUG=false`, hiding detailed error pages from visitors. All values are written directly to `.env` — no manual file editing required.

### Step 3 — Database connection

Choose your database driver from four **visual driver cards**:

| Driver | Notes |
|---|---|
| **PostgreSQL** *(recommended)* | Best performance and strictest SQL mode |
| **MySQL 8.0+** | Widely available on shared hosting |
| **MariaDB 10.6+** | MySQL-compatible alternative |
| **SQLite** *(zero config)* | Great for development — just a file path |

Fill in host, port, database name, username, and password (SQLite shows a file path field instead). Click **"Test connection & continue"** — Magna probes the connection *before* writing anything. If the connection fails, you get a plain-language error (wrong credentials vs. missing database vs. unreachable host), not a raw PDO exception. On success, Magna writes the database config to `.env`, runs migrations, and seeds the default roles — all automatically.

### Step 4 — Admin account

Create your **super-admin account**: name, email, and a password of at least 12 characters. This account gets the `super-admin` role which bypasses every permission check. You can add regular team members with scoped roles from the admin panel after setup. Click **"Create account & finish installation"**.

### Installation complete

Magna writes a lock file (`storage/app/magna-installed.json`) and every installer route returns **404 permanently** — the installer cannot be re-run or accessed by anyone, including the server owner. Your admin panel is at `http://your-site.com/` and your delivery API is at `http://your-site.com/api/v1/`.

---

### Your First Content Type

```bash
# Create a content type via CLI
php artisan magna:type:make article

# Sync the schema to your database (creates a real `articles` table)
php artisan magna:schema:sync

# Your content is now available at:
# GET /api/v1/content/article
# GET /api/v1/content/article/{id}
```

Or use the visual **Content Type Builder** in the admin panel — no CLI required.

---

## 🌐 REST API

The Magna delivery API is fast, scoped, and consistent.

### Authentication

```bash
# All endpoints require a scoped API key
Authorization: Bearer mag_del_your_delivery_key_here
```

Generate keys in **Admin → API → API Keys** — choose `Delivery` scope for read-only public access.

### Endpoints

```
GET    /api/v1/content/{type}           # list entries (paginated, filterable)
GET    /api/v1/content/{type}/{id}      # single entry
GET    /api/v1/content/{type}/{slug}    # single entry by slug
GET    /api/v1/media                    # list media assets
GET    /api/v1/media/{id}              # single media asset
```

### Query Parameters

```
?page=1&per_page=20          # pagination (cursor-based option available)
?filter[status]=published    # filter by field value
?sort=created_at&dir=desc    # sort
?fields=title,slug,body      # sparse fieldsets
?locale=fr                   # localized content
?include=author,category     # eager-load relations
```

### Example Response

```json
{
  "data": [
    {
      "id": "01j...",
      "type": "article",
      "slug": "hello-world",
      "status": "published",
      "title": "Hello World",
      "body": "...",
      "published_at": "2026-07-12T10:00:00Z"
    }
  ],
  "meta": {
    "total": 142,
    "per_page": 20,
    "current_page": 1
  },
  "links": {
    "next": "/api/v1/content/article?page=2"
  }
}
```

---

## 📊 How Magna Compares

| Feature | **Magna** | Strapi | Directus | Payload | WordPress | Statamic |
|---|---|---|---|---|---|---|
| Runtime | **PHP / Laravel** | Node | Node | Node | PHP | PHP / Laravel |
| License | **MIT** | MIT + EE | BSL + EE | MIT | GPL | Paid |
| Headless-first API | ✅ | ✅ | ✅ | ✅ | ⚠️ bolt-on | ⚠️ add-on |
| Visual content type builder | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ |
| Real database tables (no EAV) | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ flat-file |
| Schema as code + env sync | ✅ | ⚠️ partial | ⚠️ partial | ✅ | ❌ | ✅ |
| Website out of the box | ✅ optional | ❌ | ❌ | ⚠️ template | ✅ | ✅ |
| Plugin distribution | **Composer** | npm | npm | npm | ZIP upload | Composer |
| Logic-free safe themes | ✅ enforced | — | — | — | ❌ full PHP | ⚠️ |
| Published CI latency budgets | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Surrogate-key edge cache (core) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Field-level encryption (schema) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Per-role enforceable 2FA | ✅ | ❌ | ✅ | ❌ | ❌ | ⚠️ add-on |
| Scoped expiring API tokens | ✅ | ⚠️ | ✅ | ✅ | ❌ | ⚠️ |
| Plugin circuit breaker + safe mode | ✅ | ❌ | ❌ | ❌ | ⚠️ recovery | ❌ |
| Browser installer (unzip & go) | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ |
| Ordinary PHP hosting | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ |
| OWASP ASVS L2 target (with audit) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

*(Corrections welcome — open an issue. Comparisons reflect core capabilities at the time of writing.)*

---

## Magna CMS vs Strapi

**Looking for a Strapi alternative in PHP / Laravel?** Magna is the answer.

Strapi is a popular Node-based headless CMS — but if your team runs PHP or Laravel, deploying and maintaining a Node service is friction you don't need. Magna gives you everything Strapi offers — visual content type builder, REST API, role-based access, plugin system — on the PHP stack your team already knows, deployable on any server that runs Laravel.

| | **Magna** | Strapi |
|---|---|---|
| Runtime | PHP 8.3 / Laravel 13 | Node.js |
| Hosting | Any PHP host, Forge, Ploi, shared hosting | VPS, Docker, Heroku, Railway |
| License | MIT (forever) | MIT + paid Enterprise Edition |
| Plugin ecosystem | Composer packages | npm packages |
| Performance contract | CI-enforced budgets (published) | No published benchmarks |
| Field-level encryption | ✅ core | ❌ |
| PHP/Laravel extensibility | ✅ native Eloquent | ❌ |

If you know Laravel, you don't need to learn a new ecosystem. Magna is the **Strapi for Laravel**.

---

## Magna CMS vs Directus

**Looking for a Directus alternative for PHP / self-hosted?** Magna was built for you.

Directus is a powerful data platform that wraps any SQL database with an API. But it runs on Node, has a BSL licence for some features, and is intentionally database-agnostic rather than opinionated. Magna makes different trade-offs: Laravel-idiomatic, PHP-native, with a stronger plugin safety model and a performance contract Directus doesn't publish.

| | **Magna** | Directus |
|---|---|---|
| Runtime | PHP / Laravel | Node.js |
| License | MIT | BSL (some features) |
| Schema approach | Schema-as-code, real tables | Wraps existing DB |
| Performance guarantees | Published CI benchmarks | None published |
| Plugin safety | Circuit breaker + safe mode | None |
| PHP ecosystem | ✅ native | ❌ |

If your project lives in PHP and you want the safety and familiarity of the Laravel ecosystem, Magna is the **Directus for PHP**.

---

## Magna CMS vs Payload CMS

**Looking for a Payload CMS alternative that runs on PHP?** Magna is it.

Payload is an excellent TypeScript/Next.js headless CMS — great if your entire stack is JS. But if you're a PHP or Laravel team, adopting Payload means adopting Node, TypeScript, and a completely different deployment story. Magna gives you the same developer-first experience (schema as code, typed APIs, RBAC, scoped tokens, draft/publish) on the PHP stack you already run.

| | **Magna** | Payload CMS |
|---|---|---|
| Runtime | PHP / Laravel | Node.js / Next.js |
| Schema definition | Visual builder + code export | TypeScript config |
| Deployment | Any PHP server | Node / Vercel / Railway |
| Database | MySQL, PostgreSQL, SQLite | MongoDB, PostgreSQL |
| Admin UI | Filament 5 (dark, responsive) | Custom React admin |
| Plugin system | Composer | npm |

---

## Magna CMS vs WordPress

**Building a headless site and tired of WordPress?** Magna was designed from day one for the API-first world.

WordPress is the world's most popular CMS — but it was built for server-rendered pages in 2003. Its headless story is a bolt-on REST API and WPGraphQL. The plugin ecosystem (ZIP upload, PHP anywhere) is the source of most WordPress security incidents. Magna is headless by design, secure by default, and runs on PHP hosting without WordPress's legacy baggage.

| | **Magna** | WordPress |
|---|---|---|
| Designed for headless | ✅ API-first | ❌ bolt-on WP REST API |
| Plugin security model | Composer + manifest + review | ZIP upload, PHP anywhere |
| Content schema | Real tables, typed fields | Custom post types + ACF meta |
| Performance | CI-enforced budgets, edge cache built in | Plugin-dependent |
| Security defaults | Argon2id, 2FA, scoped tokens, CORS | MD5 passwords (historically) |
| Admin panel | Modern Filament 5 dark UI | Classic WP admin |

---

## Magna CMS vs Contentful / Sanity

**Looking for an open-source Contentful alternative or Sanity alternative you can self-host?** Magna is your answer.

Contentful and Sanity are excellent SaaS CMSs — but you pay per record, per API call, per seat. You can never fully own your data or your infrastructure. Magna is MIT-licensed, self-hosted, with no per-record fees, no seat limits, and no vendor lock-in. You run it on your own server and keep your data forever.

| | **Magna** | Contentful | Sanity |
|---|---|---|---|
| Pricing | **Free, self-hosted** | From $300/mo | From $99/mo |
| Data ownership | **Full** | Vendor-hosted | Vendor-hosted |
| Open source | **MIT** | Closed | Closed (Studio only) |
| Self-host | ✅ | ❌ | ❌ |
| Vendor lock-in | **None** | High | Medium |

---

## Magna CMS vs Statamic / Twill

**Already a Laravel developer choosing between Magna, Statamic, and Twill?**

Statamic is a great Laravel CMS — but it's flat-file by default, paid for commercial use, and website-oriented rather than headless-first. Twill is an excellent admin package from AREA 17 — but it's a UI layer, not a full CMS. Neither fills the "Laravel-native, database-backed, headless-first, plugin ecosystem, free and open-source" slot. Magna does.

| | **Magna** | Statamic | Twill |
|---|---|---|---|
| License | **MIT (free)** | Paid (commercial) | MIT |
| Content storage | **Real DB tables** | Flat-file (git) | Real DB tables |
| Headless-first | ✅ | ⚠️ add-on | ⚠️ DIY |
| Plugin ecosystem | ✅ Composer | ✅ Composer | ❌ admin only |
| Built-in REST API | ✅ | ✅ | ❌ |
| Content type builder (visual) | ✅ | ✅ | ⚠️ blueprint |

---

## 🏗️ Architecture

```
                      ┌────────────────────────────────────────┐
  Next.js / Nuxt ───▶ │  REST Delivery API                     │
  Flutter / native ─▶ │  ETags · surrogate keys · pagination   │
  AI agents ───────▶  │  preview · localization                │
                      ├────────────────────────────────────────┤
  Filament Admin ──▶  │  CONTENT ENGINE                        │
                      │  types · entries · drafts · revisions  │
                      │  media · localization · blocks         │
                      ├────────────────────────────────────────┤
                      │  KERNEL                                │
                      │  auth · RBAC · plugins · events        │
                      │  settings · audit · webhooks           │
                      └───────────────┬────────────────────────┘
                                      │ typed PHP interfaces
                      ┌───────────────┴────────────────────────┐
                      │  PLUGINS  (Composer packages)          │
                      │  docs · blog · seo · forms · pages     │
                      └────────────────────────────────────────┘
```

**Stack:**
- **PHP 8.3+** — tested on 8.3 and 8.4 in CI
- **Laravel 13** — routing, Eloquent ORM, queues, events, broadcasting
- **Filament 5** — admin panel (the most powerful PHP admin framework)
- **MySQL 8+ / PostgreSQL 14+** — real tables per content type, no EAV
- **SQLite** — supported for development and testing
- **Redis** — caching, queues, sessions, surrogate key invalidation
- **FrankenPHP / Octane** — reference high-performance deployment

---

## 🔐 Security

**Implemented today:**

- Argon2id hashing (bcrypt fallback for legacy imports)
- Per-role **enforceable** TOTP 2FA with recovery codes — users without 2FA in a 2FA-required role are blocked
- Scoped, expiring API tokens — hashed at rest, shown once, separate scopes for delivery and management
- Exponential-backoff login lockout with admin notification
- Append-only audit log — rows never update or delete; designed for compliance export
- Strict security headers including admin-side CSP
- Default-deny CORS on management API routes
- Self-locking installer — rate-limited, returns 404 forever post-setup
- Registration disabled by default (admin creates users)
- `composer audit` + PHPStan level 9 + taint analysis in CI

**Committed before 1.0:**

- Third-party penetration test + OWASP ASVS Level 2 assessment (results published)
- Field-level encryption as a content schema attribute
- Upload re-encoding pipeline (content-sniff + strip embedded payloads)
- Signed releases with per-release SBOMs

Found a vulnerability? **Do not open a public issue.** See [SECURITY.md](SECURITY.md) for coordinated disclosure.

---

## ❓ FAQ

**Q: Is Magna production-ready?**  
A: Magna is in public beta (v1.3.0-beta). The kernel, auth, RBAC, and plugin system are solid. The content engine and delivery API are in active development. We recommend waiting for stable 1.0 for production, or running beta on low-risk projects and contributing feedback.

**Q: What database does Magna use?**  
A: MySQL 8+ and PostgreSQL 14+ are the primary targets. SQLite is fully supported for development and testing. Each content type gets a real table with real columns — no EAV, so your queries stay fast even at scale.

**Q: Can I use Magna with Next.js / Nuxt / SvelteKit?**  
A: Yes. The delivery API is a standard REST API with JSON responses. Any frontend that can make HTTP requests works — Next.js, Nuxt, SvelteKit, Astro, Flutter, React Native, plain JavaScript. Official SDK packages are on the roadmap.

**Q: Can I deploy Magna on shared PHP hosting?**  
A: Yes. Magna runs on any PHP 8.3+ host — shared hosting, VPS, Laravel Forge, Ploi, Vapor, or your own server. This is a major differentiator from Node-based headless CMSs like Strapi, Directus, and Payload.

**Q: How is Magna different from WordPress headless?**  
A: WordPress was designed for rendered pages in 2003. Its REST API is a bolt-on that doesn't cover the full data model without plugins. Magna is API-first from the ground up — the delivery API *is* the product, not an afterthought. And Magna's security defaults, plugin model, and performance contract are fundamentally different.

**Q: How is Magna different from Statamic?**  
A: Statamic is flat-file by default and paid for commercial use. Magna is database-backed (real tables, real indexes, real queries) and MIT-licensed forever. Magna is also headless-first; Statamic is website-first with headless as an add-on.

**Q: Can I build plugins for Magna?**  
A: Yes. Plugins are Composer packages with a `magna.json` manifest — full guide at [plugin-development-guide.md](docs/plugin-development-guide.md). The plugin API (typed PHP interfaces) is semver-guaranteed from 1.0; we will not break plugin authors inside a major version.

**Q: Does Magna support multiple languages / localization?**  
A: Yes. Content localization is a core primitive — not a plugin. Mark any field as localizable in the content type builder and entries will have per-locale versions. The delivery API accepts `?locale=fr` to return the correct version. The Magna Docs plugin also ships with full multi-language support.

**Q: Is the admin panel mobile-friendly?**  
A: Yes. The Filament 5 admin panel is fully responsive and works on tablets. Optimized for desktop editing workflows but usable on mobile for review and quick edits.

**Q: What is Magna's business model?**  
A: The core CMS is MIT-licensed forever. Revenue comes from the official plugin/theme store (Magna Store — planned) and Magna Cloud (managed hosting). Third-party developers can publish paid plugins and themes in the store. See [store-plan.md](docs/store-plan.md).

---

## 🗺️ Roadmap

| Phase | Status | What ships |
|---|---|---|
| **Phase 1 — Core** | 🔄 In progress | Kernel · content engine · media · REST API · Filament admin · plugin system · block editor |
| **Phase 2 — Ecosystem** | Planned | Plugin SDK & docs · full localization · schema sync · Official Store · 3rd-party security audit · **stable 1.0** |
| **Phase 3 — Depth** | Planned | Magna Pages + themes · GraphQL · semantic search (pgvector) · AI plugin · realtime WebSockets |
| **Phase 4 — Commercial** | Planned | Magna Cloud · store open to third-party publishers |
| **Phase 5 — Enterprise** | Planned | Multi-tenancy · SSO/SAML · compliance pack |

See the full [build plan](docs/build-plan.md) for current progress stage-by-stage.

---

## 📚 Documentation

All specs are written before the code is built. The specs live here:

| Document | What it covers |
|---|---|
| [Project overview](docs/Magna-v2.md) | Vision, architecture, market position, roadmap |
| [Build plan](docs/build-plan.md) | Stage-by-stage plan and current progress |
| [Plugin development guide](docs/plugin-development-guide.md) | Build a plugin: manifest, contracts, content types, testing |
| [Theme development guide](docs/theme-development-guide.md) | Build a theme: tokens, block views, pairing |
| [Default theme spec](docs/default-theme-spec.md) | The reference theme + standard block library |
| [Performance spec](docs/performance-spec.md) | CI budgets, benchmark harness, caching model |
| [Security spec](docs/security-spec.md) | OWASP ASVS target, defaults, supply chain, plugin trust |
| [Plugin isolation](docs/plugin-isolation.md) | Fault-tolerant runtime: circuit breaker, safe mode, remote apps |
| [Installer](docs/installer.md) | Browser installer: flow, security, roadmap |
| [Store plan](docs/store-plan.md) | Official plugin/theme store, staged rollout |

---

## 🤝 Contributing

The most valuable contribution right now is **review of the specs** — changing a spec today is free; changing shipped behavior is not. Open an issue or discussion on any spec document.

For code contributions: all PRs run the full gate — Pint, PHPStan level 9, Pest, and the performance suite. See `CONTRIBUTING.md` (coming with the first stable alpha).

Ways to contribute:
- ⭐ Star the repo to show support and help others find it
- 🐛 Open an issue for bugs, spec corrections, or feature requests
- 📖 Review the specs and challenge the design decisions
- 🔌 Build a plugin and share it
- 🌍 Help with translations

---

## 📄 License

Magna CMS is open-source software licensed under the [MIT license](LICENSE). The core will always be free and open-source.

The "Magna" name and logo are trademarks. The [store plan](docs/store-plan.md) describes the commercial layer (official store, Magna Cloud) that funds ongoing core development.

---

<p align="center">
  <strong>Built in the open on Laravel 13 · PHP 8.3 · Filament 5</strong><br>
  <sub>Star the repo to follow the road to 1.0 ⭐</sub>
</p>

<p align="center">
  <a href="https://github.com/Magna-CMS/Magna">GitHub</a> •
  <a href="https://github.com/Magna-CMS/Magna-Docs">Magna Docs Plugin</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="docs/build-plan.md">Build Plan</a>
</p>
