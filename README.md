# tporret API Data Importer

tporret API Data Importer is a WordPress plugin for secure, repeatable API-to-WordPress ETL.
It is built for teams that need enterprise-grade reliability without losing flexibility.

This README is the technical overview for developers, maintainers, and operators working from the repository.
If you want the WordPress admin-facing plugin summary, installation help, and FAQ copy, use [readme.txt](readme.txt).

At a glance:

- Site builders get a guided import workspace, schedule controls, safety checks, and per-site dashboards.
- Developers get a staged, idempotent, template-driven pipeline with REST tooling, custom tables, and extendable reporters.

## Why This Plugin Exists

Most import tools break down when payloads are nested, schedules are long-running, or data quality is inconsistent.
This plugin is designed for those hard cases.

It gives you:

- Control over what records are imported.
- Control over how records are transformed.
- Control over where records are loaded (selected post type).
- Safety rails for long-running jobs and production operations.

## Core Capabilities

- Multi-import job manager in wp-admin.
- Multisite support with per-site importer dashboards and an optional Network Admin summary dashboard when the plugin is also active on the primary site.
- External API extraction with flexible auth methods (`none`, `bearer`, `api_key_custom`, `basic_auth`).
- JSON array path resolution for nested payloads.
- Rule-based pre-stage filtering (AND logic).
- Twig-based mapping templates for complex transformations.
- Twig-based post title templates with safe sanitization.
- Optional templates for new jobs (save connection first, map later).
- Configurable target post type per import job.
- Parent mapping for hierarchical post types using imported external IDs, WordPress IDs, or slugs.
- Media mappings for featured images, gallery attachment IDs, and attachment-ID post meta.
- Configurable default target post settings per import job (status, author, comment status, ping status).
- React-powered tabbed Import Job Workspace for add/edit flows.
- Queue-based batch processor to avoid timeout-heavy monolithic runs.
- Per-import recurrence schedules (`off`, `hourly`, `twicedaily`, `daily`, custom N-minute intervals).
- Health and schedule dashboard with run stats and trigger source visibility.
- Endpoint test, API data preview, and Twig dry-run tools before production execution.
- Per-import edit-lock toggle to allow or prevent wp-admin edits on imported posts.

## Latest Release (1.3.1)

- Deepened the import architecture into focused modules for validation, lifecycle execution, template rendering, security checks, repositories, media ingestion, cleanup, and edit-lock policy.
- Added parent mapping for hierarchical post types and richer media mapping for featured, gallery, and meta attachment fields.
- Added reporter auto-discovery so dashboard metrics self-register without touching central wiring.
- Centralized post-type default normalization for REST saves and import runtime.
- Centralized legacy admin-post import saves through the same validator used by REST create/update, preventing stale parallel field handling.
- Preserved the existing REST API, admin UI, and import job compatibility while reducing duplicated control flow.

## Multisite Operation

In WordPress multisite, the plugin follows a per-site execution model with an optional network summary view.

- Activate the plugin on each subsite that should actually run imports.
- If you want the Network Admin dashboard, also activate the plugin on the primary site.
- Network Activate is intentionally blocked.
- Site admins continue to use each subsite dashboard; the Network Admin dashboard is a summary layer, not a replacement for local job management.

## Default Security Posture

The plugin ships locked down for production-oriented installs.

- HTTPS is required for remote endpoints unless you explicitly loosen that via code.
- Private and loopback targets are blocked by default unless you opt into internal endpoints.
- The SSRF Hardening reporter shows a warning on new installs until you configure an endpoint allowlist.
- Once Allowed Endpoint Hosts or Allowed Endpoint CIDR Blocks are configured, that reporter moves to a healthy state.

## Repository Audience

Use this repository README when you need technical context such as:

- how the import pipeline is structured
- what the reporting subsystem exposes
- how multisite activation is supposed to work
- what security defaults and extension points exist
- how to reason about packaging or testing the plugin from source

## What Was Recently Added

### 1) Dynamic Post Title Templates (Twig)

You can now define a `Post Title Template` per import.

- Uses Twig syntax (for example, `{{ user.first_name }} {{ user.last_name }}`).
- Rendered with the same context aliases: `record`, `item`, `data`.
- Rendered output is sanitized with `wp_strip_all_tags()`.
- Title is truncated to 255 characters via `mb_substr()` to avoid DB truncation issues.
- If blank (or render result is empty), fallback title remains `Imported Item {ID}`.

### 2) Target Post Type Selector

You can now choose the destination post type per import job.

- Dropdown is dynamically populated from public post types.
- Saved value is sanitized using `sanitize_key()`.
- Runtime fallback is automatic:
  - if empty, fallback to `post`
  - if unregistered on the site, fallback to `post`
- `attachment` is intentionally blocked by default for safety.

### 3) Default Target Post Settings (Status, Author, Comment, Ping)

Each import job can now define default publishing and discussion behavior for imported posts.

- Default Post Status: `draft`, `publish`, or `pending`.
- Post Author: selected from available WordPress users in the import workspace.
- Comment Status: `open` or `closed`.
- Pingback/Trackback Status: `open` or `closed`.

Implementation details:

- Values are validated and persisted with import-job REST create/update sanitization.
- Import load now maps these settings into `wp_insert_post()` payloads.
- Existing imported posts also receive discussion-setting updates through `wp_update_post()` during sync.

### 4) Parent Mapping and Media Mappings

The Mapping & Templating workspace can now configure relationship and media behavior beyond post content templates.

#### Parent Mapping

For hierarchical post types, each import job can map a source field to the imported post's parent.

- Enabled only when the target post type is hierarchical.
- `source_path` points to the parent identifier in each API record.
- Lookup modes:
  - `external_id`: find another item imported by the same job via its external ID.
  - `wp_id`: use an existing WordPress post ID in the selected target post type.
  - `slug`: find an existing post by slug in the selected target post type.
- Missing-parent behavior:
  - `defer`: import now and reconcile the parent after later chunks complete.
  - `root`: import as a root item.
  - `skip`: skip the item until the source data is corrected.

Parent state is tracked with `_tporapdi_parent_external_id` and `_tporapdi_pending_parent_external_id` metadata so out-of-order API payloads can reconcile after chunk processing.

#### Media Mappings

Media mappings are stored per import job and take precedence over the legacy `featured_image_source_path` fallback.

- `featured`: sideloads one image and sets it as the post thumbnail.
- `gallery`: sideloads one or more images and stores attachment IDs in a configured meta key, defaulting to `_tporapdi_gallery_attachment_ids`.
- `meta`: sideloads one or more images and stores attachment IDs in the configured meta key.
- Each mapping can include `source_path`, optional `url_path`, and optional `alt_path`, `title_path`, `caption_path`, and `description_path` metadata paths.
- Media downloads still use the plugin SSRF checks and idempotent `_tporapdi_source_url` deduplication.

Example media mapping JSON:

```json
[
  {
    "role": "featured",
    "source_path": "images.hero",
    "url_path": "url",
    "alt_path": "alt"
  },
  {
    "role": "gallery",
    "source_path": "images.gallery",
    "url_path": "url",
    "meta_key": "_gallery_attachment_ids"
  }
]
```

### 5) Secure Media Sideload Helper

A focused media ingestor is available in the processing layer:

- `Tporapdi_Media_Ingestor::sideload_image( $image_url, $post_id, $is_featured = false )`
- `Tporapdi_Media_Ingestor::apply_media_mappings( $item, $post_id, $import_id, $mappings )`

Design goals:

- Idempotent media handling using `_tporapdi_source_url` matching.
- Secure download flow via `download_url()` and WP media APIs.
- Safe error handling with logs in `wp_custom_import_logs`.
- Optional featured image assignment via `set_post_thumbnail()`.

### 6) Enterprise Reporting Engine & Dashboard (Tableau-Style)

A pluggable, high-performance reporting aggregator with real-time operational metrics and a React-based UI.

#### Architecture Overview

**Three-Tier Design:**

1. **Reporter Base Class** (`TPORAPDI_Reporter_Base`)
   - Abstract base with transient-backed caching (600s TTL default)
   - Formatting helpers: `format_percentage()`, `format_time_ago()`, `get_status_color()`
   - Each reporter implements `calculate_metrics(): array` for domain logic

2. **Singleton Aggregator** (`TPORAPDI_Reporting_Aggregator`)
   - Registry pattern for reporter instances
   - `register_reporter( TPORAPDI_Reporter_Base $reporter )`
   - `get_dashboard_data()` returns nested array grouped by category (Health, Security, Performance)

3. **Nine Metric Modules (Out of the Box)**
   - **Environment Health:**
     - `Cron_Heartbeat`: Checks recurring import schedules vs `wp_next_scheduled()`
     - `Queue_Depth`: Counts unprocessed staging rows (with color-coded thresholds)
     - `Daily_Success_Rate`: Success/error ratio from last 24h logs
   - **Security & Compliance:**
     - `SSRF_Hardening`: Tests if endpoint allowlist is configured
     - `Audit_Integrity`: Counts template change audit entries (7 days)
     - `Protocol_Enforcement`: HTTPS vs HTTP endpoint ratio
   - **Connectivity & Performance:**
     - `API_Latency`: Average processing time from last 100 runs (with sparkline)
     - `Active_Connections`: Distinct endpoint URL count
     - `Throughput`: Total rows processed in last 60 minutes

#### REST API Routes

**Get aggregated metrics:**
```
GET /wp-json/tporret-api-data-importer/v1/dashboard
```
Returns nested structure grouped by category and reporter ID.

```json
{
  "Health": {
    "daily_success_rate": {
      "label": "Daily Success Rate",
      "metrics": { "status": "green", "value": "98.5%", "detail": "..." }
    }
  },
  "Security": { ... },
  "Performance": { ... }
}
```

**Get time-series history (for charts and feeds):**
```
GET /wp-json/tporret-api-data-importer/v1/dashboard/history
```
Returns sparkline data, latency charts, audit entries, and throughput trends (600s cache).

**Force refresh (flush transients):**
```
GET /wp-json/tporret-api-data-importer/v1/dashboard?refresh=1
```

#### React Dashboard UI

**Components:**
- `Dashboard.js` – Main layout router with header, KPIs, 3-column grid, footer
- `StatCard.js` – KPI card with Lucide icon + Recharts sparkline (7-day trend)
- `StatusList.js` – Health checks with icons + progress bars
- `SecurityDonut.js` – Interactive donut chart (Recharts) with hover legends
- `LatencyChart.js` – Area chart for latency timeline
- `AuditMarquee.js` – Scrolling marquee of last 5 audit events

**Tech Stack:**
- React (via `@wordpress/element`)
- Recharts (SVG data viz)
- Lucide React (icons)
- Tailwind CSS (scoped with `eapi-` prefix to avoid wp-admin collisions)
- `@wordpress/api-fetch` for REST communication

**Menu Location:** `EAPI → Dashboard` (requires `read` capability)

#### Multisite Dashboard Behavior

- Subsite dashboards remain site-scoped and continue to show each site's own import health.
- The Network Admin dashboard is populated from multisite snapshot rows stored in a network-level table.
- Unsupported network activation is automatically rejected so the supported model stays explicit.

#### Extending the Reporting System

**Add a custom reporter:**

```php
class My_Custom_Reporter extends TPORAPDI_Reporter_Base {
  protected string $id = 'my_metric';
  protected string $category = 'Performance';
  protected string $label = 'My Custom KPI';
  
  protected function calculate_metrics(): array {
    global $wpdb;
    
    // Your DB query logic here
    $count = $wpdb->get_var( ... );
    
    return array(
      'status' => $this->get_status_color( $count, array( 'green' => 10, 'yellow' => 50 ) ),
      'value'  => number_format_i18n( $count ),
      'detail' => 'Custom metric explanation.',
    );
  }
}

// Register on init
add_action( 'init', function() {
  $aggregator = TPORAPDI_Reporting_Aggregator::get_instance();
  $aggregator->register_reporter( new My_Custom_Reporter() );
}, 20 ); // Run after tporapdi_init_reporting (priority 10)
```

**Customize thresholds:**

All status-to-color logic uses `get_status_color( $value, ['green' => X, 'yellow' => Y] )` for consistent UX.

**Clear reporting cache:**

```php
tporapdi_flush_reporting_transients(); // Clears all 9 reporter transients + history
```

**Monitoring via WP-Cron:** All metrics are calculated on-demand and cached, so no background jobs required. Transient eviction is natural.

### 7) Security Hardening — Credential Encryption & Data Sanitization

Credential fields (`auth_token`, `auth_password`) are now encrypted at rest using AES-256-CBC with a key derived from `wp_salt('auth')`. Encrypted values are prefixed with `tporapdi_enc:` for identification — legacy plaintext values are handled gracefully during the transition.

- **REST GET masking**: Credential values are replaced with empty strings in API responses. Boolean flags (`has_auth_token`, `has_auth_password`) indicate whether a credential is stored.
- **Credential preservation on update**: When the frontend sends blank credential fields (because they were masked), the server preserves the existing encrypted values instead of overwriting with blanks.
- **React UI indicators**: Auth credential fields show "Credential saved. Leave blank to keep existing value." with a `••••••••` placeholder when a credential is already stored.
- **Filter safety**: `apply_filters( 'tporapdi_remote_request_args', $args, $auth_method )` now passes the auth method string instead of the raw token.
- **Post content sanitization**: Twig-rendered content is passed through `wp_kses_post()` before `wp_insert_post()` to prevent stored XSS from untrusted API payloads.
- **Custom meta sanitization**: Twig-compiled meta values are sanitized with `sanitize_text_field()` before `update_post_meta()`.
- **Admin menu capability**: Menu pages now require `manage_options` instead of `read`, preventing subscriber-level access.

### 8) Enhanced Security Hardening (5-Layer Defense)

#### ✅ Dedicated Template Management Capability

- New capability: `tporapdi_manage_templates` (assigned to Administrator role).
- Permission check: `tporapdi_manage_templates` OR `manage_options` OR `is_super_admin()` on multisite.
- Imported content becomes read-only (no editing, deletion, or quick-edit allowed).
- Template configuration is locked to the new capability across:
  - Form save in wp-admin
  - REST endpoint dry-run/preview
  - Admin post handlers

**Extend capability assignment:**
```php
// Allow custom roles to manage templates
wp_cap_map_meta_cap( 'tporapdi_manage_templates', $user_id );
add_role( 'api_manager', 'API Manager', array( 'tporapdi_manage_templates' => true ) );
```

#### ✅ Twig Template Security Validation

- Pre-save and pre-render validation prevents dangerous Twig features.
- Enforcement rules:
  - Max template size: 50 KB (mappings), 2 KB (titles) — filterable via `tporapdi_template_max_bytes`
  - Max expression count: 250 — filterable via `tporapdi_template_max_expressions`
  - Disallowed tags: `include`, `source`, `import`, `from`, `embed`, `extends`, `use`, `macro` (prevents SSTI/file inclusion)
  - Max nesting depth: 12 levels — filterable via `tporapdi_template_max_nesting_depth`
  - Syntax validation: parsed via Twig tokenizer at save time

**Customize limits:**
```php
add_filter( 'tporapdi_template_max_bytes', function() { return 100 * 1024; } ); // 100 KB
add_filter( 'tporapdi_template_max_expressions', function() { return 500; } );
add_filter( 'tporapdi_template_max_nesting_depth', function() { return 15; } );
```

#### ✅ Template Change Audit Logging

- All template configuration changes logged to `wp_custom_import_logs`.
- Audit metadata includes:
  - Actor (user login, role, and display name)
  - Before/after SHA256 hashes (change detection without storing full history)
  - Template lengths (payload size tracking)
  - Create vs. update distinction
  - Precise timestamp

**Query audit logs:**
```sql
SELECT * FROM wp_custom_import_logs 
WHERE type = 'template_config_change' 
ORDER BY timestamp DESC LIMIT 10;
```

#### ✅ SSRF Prevention (Endpoint Allowlisting)

- Endpoints are validated against hostname and CIDR allowlists.
- Available in: **Settings → Allowed Endpoint Hosts** and **Allowed Endpoint CIDR Blocks**.
- Validation flow:
  1. HTTPS required (opt-out via `tporapdi_skip_https_check` filter)
  2. Private/internal IPs blocked by default (opt-in via settings checkbox)
  3. Hostname allowlist matching (exact + `*.example.com` wildcards)
  4. CIDR allowlist matching (IPv4 and IPv6 support)

**Programmatic allowlist configuration:**
```php
apply_filters( 'tporapdi_allowed_endpoint_hosts', array( 'api.example.com', '*.internal.local' ) );
apply_filters( 'tporapdi_allowed_endpoint_cidrs', array( '192.168.1.0/24', '10.0.0.0/8' ) );
apply_filters( 'tporapdi_allow_internal_endpoints', false ); // default: block RFC1918/loopback
```

#### ✅ Twig Variable Strictness Is Filterable

- Default Twig rendering is now permissive so missing variables render as empty values instead of failing item imports.
- Teams that prefer fail-fast template validation can re-enable strict mode with `tporapdi_twig_strict_variables`.
- Filterable via `tporapdi_twig_strict_variables`:

```php
add_filter( 'tporapdi_twig_strict_variables', function() { return false; } ); // permissive mode
```

### 9) React Import Job Workspace + REST CRUD

The import add/edit screen has been rebuilt as a React tabbed workspace using `@wordpress/components`.

- Tabs: Source & Auth, Data Rules, Mapping & Templating, Automation.
- Sticky action footer with Save, Run Import Now, and Update Existing Items.
- REST-backed job management for modern UI workflows:
  - `GET /wp-json/tporret-api-data-importer/v1/import-jobs/{id}`
  - `POST /wp-json/tporret-api-data-importer/v1/import-jobs`
  - `PUT /wp-json/tporret-api-data-importer/v1/import-jobs/{id}`
  - `POST /wp-json/tporret-api-data-importer/v1/import-jobs/{id}/run`
  - `POST /wp-json/tporret-api-data-importer/v1/import-jobs/{id}/template-sync`
- API test endpoint (`/wp-json/tporret-api-data-importer/v1/test-api-connection`) now powers in-UI sample preview for mapping.
- Dry-run endpoint (`/wp-json/tporret-api-data-importer/v1/dry-run`) supports template verification before import runs.
- Legacy `admin-post.php` import save submissions are normalized through the same validator used by REST create/update, so newer mapping fields cannot drift from the modern UI path.

### 10) Per-Import Edit-Lock Toggle

You can now control edit behavior per import job from Mapping & Templating.

- New setting: `lock_editing` (stored on the import config).
- When enabled, imported posts from that job are read-only in wp-admin (edit/delete/quick-edit restricted).
- When disabled, imported posts remain fully editable by normal WordPress permissions.
- Applies to all imported post types (not only the `tporapdi_item` CPT).

## Data Flow (ETL)

1. Extract
- API payload is fetched.
- JSON is validated and decoded.
- `array_path` is resolved to the target data collection.

2. Filter
- Configured filter rules are applied before staging.
- Only matching rows move forward.

3. Stage
- Selected rows are written to `wp_custom_import_temp`.
- This decouples remote API latency from transform/load runtime.

4. Transform
- Data is normalized.
- Unique external key is resolved via `unique_id_path`.
- Twig template renders post content.
- Optional Twig title template renders post title.
- Parent and media mapping configuration is resolved from validated job JSON.

5. Load
- Upsert logic identifies records by `_my_custom_api_id` + `_tporapdi_import_id`.
- Records are inserted/updated in the selected post type.
- Parent relationships are assigned or deferred for later reconciliation.
- Media mappings sideload/dedupe attachments and apply featured image, gallery meta, or attachment-ID meta updates.
- Sync timestamp and ownership metadata are maintained.

6. Finalize
- Staging rows are marked processed.
- Orphan handling and run logging complete the cycle.

## Admin Workflow (Step-by-Step)

### 1. Create an Import Job

Go to `EAPI -> Manage Imports` and click `Add New`.

Use the tabbed workspace to configure the job in phases.

Configure:

- Name
- Endpoint URL
- Auth Method (`none`, `bearer`, `api_key_custom`, `basic_auth`)
- JSON Array Path (optional)
- Unique ID Path (optional, defaults to `id`)
- Recurrence and custom interval (if needed)
- Target Post Type
- Lock editing of imported posts (optional)
- Data Filters
- Post Title Template (optional Twig)
- Mapping Template (optional Twig for initial creation)
- Parent Mapping (hierarchical post types only)
- Media Mappings (featured, gallery, or attachment-ID meta)

### 2. Test Before Running

Use `Endpoint Test & Preview` from the import edit screen.

This validates connectivity and lets you inspect:

- payload shape
- sample keys
- normalized preview
- mapped output preview

### 3. Run and Monitor

Run manually from the import edit screen or from `EAPI -> Schedules`.
Track status, run counts, and errors from the dashboard.

### 4. Configure Endpoint Security Settings

Go to `EAPI -> Settings` to control endpoint allowlisting behavior used by endpoint tests, dry runs, and import runs.

Available settings:

- Allowed Endpoint Hosts: exact hosts and wildcard subdomains (for example, `api.example.com`, `*.internal.example.com`).
- Allowed Endpoint CIDR Blocks: IPv4/IPv6 ranges to constrain resolved endpoint IPs.
- Allow Internal Endpoints: optional override to permit private/internal and loopback targets.

Recommended baseline:

- Keep `Allow Internal Endpoints` disabled in production.
- Restrict `Allowed Endpoint Hosts` to trusted domains you control.
- Use `Allowed Endpoint CIDR Blocks` only when you need explicit network-level constraints.

## Security and Reliability Model

### Credential Storage & Handling
- **AES-256-CBC encryption at rest**: `auth_token` and `auth_password` are encrypted before database storage using a key derived from `wp_salt('auth')`.
- **Transparent decryption**: Credentials are decrypted transparently at the DB read layer for import execution.
- **REST API masking**: GET responses never expose credentials — fields are replaced with empty strings and `has_auth_token` / `has_auth_password` boolean flags.
- **Credential preservation**: Blank credential fields on update are treated as "unchanged" — existing encrypted values are preserved.
- **Filter safety**: `apply_filters( 'tporapdi_remote_request_args' )` no longer passes raw credentials to third-party hooks.

### Capability-Based Access Control
- **Admin menu access**: Restricted to `manage_options` capability (administrators only).
- **Template management**: Restricted to `tporapdi_manage_templates` capability (or `manage_options` / multisite super-admin).
- **Import operations**: Guarded by permission checks on admin pages and REST endpoints.
- **Imported content**: Read-only behavior is configurable per import job (`lock_editing`) via `map_meta_cap` filter.

### Template & Twig Security
- **Validation layers**: Pre-save, pre-render, and REST dry-run all validate template safety.
- **Disallowed features**: `include`, `source`, `import`, `from`, `embed`, `extends`, `use`, `macro` tags blocked.
- **Resource limits**: Size, expression count, and nesting depth throttled to prevent DoS.
- **Strict variables**: Permissive by default for import resilience, with an opt-in filter to enforce fail-fast validation.
- **Audit trail**: All template changes logged with before/after hashes and actor metadata.

### Network & SSRF Defense
- **HTTPS enforcement**: All endpoints must use HTTPS (filterable via `tporapdi_skip_https_check`).
- **Private IP blocking**: RFC1918 and loopback addresses blocked by default (opt-in via settings).
- **Hostname allowlisting**: Exact matching and wildcard subdomain patterns (e.g., `*.internal.local`).
- **CIDR allowlisting**: IPv4 and IPv6 network ranges supported and validated via `inet_pton()`.

### Import Pipeline Sanitization
- **Post content**: Twig-rendered content is passed through `wp_kses_post()` before `wp_insert_post()` to prevent stored XSS from malicious API data.
- **Custom meta values**: Twig-compiled meta values are sanitized with `sanitize_text_field()` before `update_post_meta()`.
- **Post titles**: Rendered titles are stripped of all HTML via `wp_strip_all_tags()` and truncated to 255 characters.

### Data Handling & Nonces
- Input sanitization before database persistence.
- Output escaping in admin views and REST responses.
- Nonce checks on admin-post actions and REST endpoints.
- Queue-first architecture isolates long-running workloads.
- Staging table isolation supports resumable processing with audit trails.

## Database Tables

- `wp_tporapdi_imports`
  - import definitions and processing settings
  - includes fields such as:
    - endpoint/auth/paths
    - recurrence settings
    - `target_post_type`
    - `lock_editing`
    - `title_template`
    - `mapping_template`
    - `parent_mapping`
    - `media_mappings`

- `wp_custom_import_temp`
  - staged payload fragments per import

- `wp_custom_import_logs`
  - run outcomes, row counts, and error details

## Scheduling Model

- Recurring trigger hook: `tporapdi_recurring_import_trigger`
- Immediate trigger hook: `tporapdi_immediate_import_trigger`
- Queue worker hook: `tporapdi_process_import_queue`

Each run tracks trigger context (`manual`, `run_now`, `recurring`) for operational traceability.

## Twig Examples

Basic value:

```twig
{{ record.title }}
```

Nested path:

```twig
{{ record.course.name }}
```

Loop:

```twig
<ul>
{% for module in record.modules %}
  <li>{{ module.name }}</li>
{% endfor %}
</ul>
```

Conditional:

```twig
{% if record.isActive %}
  <p>Active</p>
{% endif %}
```

Title template example:

```twig
{{ record.last_name }}, {{ record.first_name }}
```

Built-in Twig extensions:

- test: `numeric`
- filter: `format_us_currency`
- filter: `format_date_mdy`

## Requirements

- WordPress 6.3 through 7.0.0
- PHP 8.1+
- Composer dependency: `twig/twig`

Install dependency in plugin root:

```bash
composer require twig/twig
```

## Operational Recommendations

- Use real server cron for production reliability.
- Validate endpoint previews before enabling recurrence.
- Keep mapping/title templates under source control (copy into your repo/docs).
- Start with smaller intervals and observe logs before scaling throughput.

## File Map

- `tporret-api-data-importer.php` bootstrap
- `includes/core.php` activation and schema migrations
- `includes/content.php` CPT registration
- `includes/import.php` backwards-compatible ETL, queue, and cron orchestration functions
- `includes/modules/` focused import, validation, security, repository, media, cleanup, and lock-policy modules
- `includes/reporting/` dashboard REST endpoints, aggregator, and reporter modules
- `includes/admin.php` admin UI and admin-post handlers
- `includes/db.php` database compatibility layer and helper functions
- `includes/class-tporapdi-imports-list-table.php` admin list rendering
- `src/` React admin asset source for dashboard and import-job workspace
- `build/` generated WordPress admin assets

## Developer Quick Checks

```bash
npm run check:metadata
npm run check:php
npm run build
```

Before tagging a release, run the full local gate:

```bash
npm run release:check
```

## License

GPL-2.0-or-later
