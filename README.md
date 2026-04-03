# Enterprise API Importer

Enterprise API Importer is a WordPress plugin for secure, repeatable API-to-WordPress ETL.
It is built for teams that need enterprise-grade reliability without losing flexibility.

If you are non-technical: this plugin lets you connect external APIs and keep WordPress content in sync.
If you are technical: you get a staged, idempotent, template-driven pipeline with cron orchestration and detailed run logs.

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
- External API extraction with optional Bearer token support.
- JSON array path resolution for nested payloads.
- Rule-based pre-stage filtering (AND logic).
- Twig-based mapping templates for complex transformations.
- Twig-based post title templates with safe sanitization.
- Configurable target post type per import job.
- Queue-based batch processor to avoid timeout-heavy monolithic runs.
- Per-import recurrence schedules (`off`, `hourly`, `twicedaily`, `daily`, custom N-minute intervals).
- Health and schedule dashboard with run stats and trigger source visibility.
- Endpoint test and preview tools before production execution.

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

### 3) Secure Media Sideload Helper (Foundation)

A new static helper is available in the processing layer:

- `EAI_Import_Processor::sideload_image( $image_url, $post_id, $is_featured = false )`

Design goals:

- Idempotent media handling using `_eapi_source_url` matching.
- Secure download flow via `download_url()` and WP media APIs.
- Safe error handling with logs in `wp_custom_import_logs`.
- Optional featured image assignment via `set_post_thumbnail()`.

Note: this helper is now implemented and available for integration into your field mapping/media ingestion strategy.

### 4) Enhanced Security Hardening (5-Layer Defense)

#### ✅ Dedicated Template Management Capability

- New capability: `eai_manage_templates` (assigned to Administrator role).
- Permission check: `eai_manage_templates` OR `manage_options` OR `is_super_admin()` on multisite.
- Imported content becomes read-only (no editing, deletion, or quick-edit allowed).
- Template configuration is locked to the new capability across:
  - Form save in wp-admin
  - REST endpoint dry-run/preview
  - Admin post handlers

**Extend capability assignment:**
```php
// Allow custom roles to manage templates
wp_cap_map_meta_cap( 'eai_manage_templates', $user_id );
add_role( 'api_manager', 'API Manager', array( 'eai_manage_templates' => true ) );
```

#### ✅ Twig Template Security Validation

- Pre-save and pre-render validation prevents dangerous Twig features.
- Enforcement rules:
  - Max template size: 50 KB (mappings), 2 KB (titles) — filterable via `eai_template_max_bytes`
  - Max expression count: 250 — filterable via `eai_template_max_expressions`
  - Disallowed tags: `include`, `source`, `import`, `from`, `embed`, `extends`, `use`, `macro` (prevents SSTI/file inclusion)
  - Max nesting depth: 12 levels — filterable via `eai_template_max_nesting_depth`
  - Syntax validation: parsed via Twig tokenizer at save time

**Customize limits:**
```php
add_filter( 'eai_template_max_bytes', function() { return 100 * 1024; } ); // 100 KB
add_filter( 'eai_template_max_expressions', function() { return 500; } );
add_filter( 'eai_template_max_nesting_depth', function() { return 15; } );
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
  1. HTTPS required (opt-out via `eai_skip_https_check` filter)
  2. Private/internal IPs blocked by default (opt-in via settings checkbox)
  3. Hostname allowlist matching (exact + `*.example.com` wildcards)
  4. CIDR allowlist matching (IPv4 and IPv6 support)

**Programmatic allowlist configuration:**
```php
apply_filters( 'eai_allowed_endpoint_hosts', array( 'api.example.com', '*.internal.local' ) );
apply_filters( 'eai_allowed_endpoint_cidrs', array( '192.168.1.0/24', '10.0.0.0/8' ) );
apply_filters( 'eai_allow_internal_endpoints', false ); // default: block RFC1918/loopback
```

#### ✅ Twig Strict Variables by Default

- Strict variables mode enabled on Twig environment to prevent undefined variable silently rendering as empty.
- Missing variable access now raises `Twig\Error\RuntimeError` (caught and logged).
- Filterable via `eai_twig_strict_variables`:

```php
add_filter( 'eai_twig_strict_variables', function() { return false; } ); // permissive mode
```

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

5. Load
- Upsert logic identifies records by `_my_custom_api_id` + `_eai_import_id`.
- Records are inserted/updated in the selected post type.
- Sync timestamp and ownership metadata are maintained.

6. Finalize
- Staging rows are marked processed.
- Orphan handling and run logging complete the cycle.

## Admin Workflow (Step-by-Step)

### 1. Create an Import Job

Go to `EAPI -> Manage Imports` and click `Add New`.

Configure:

- Name
- Endpoint URL
- Bearer Token (optional)
- JSON Array Path (optional)
- Unique ID Path (optional, defaults to `id`)
- Recurrence and custom interval (if needed)
- Target Post Type
- Data Filters
- Post Title Template (optional Twig)
- Mapping Template (Twig)

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

### Capability-Based Access Control
- **Template management**: Restricted to `eai_manage_templates` capability (or `manage_options` / multisite super-admin).
- **Import operations**: Guarded by permission checks on admin pages and REST endpoints.
- **Imported content**: Read-only (no editing, deletion, or quick-edit) via `map_meta_cap` filter.

### Template & Twig Security
- **Validation layers**: Pre-save, pre-render, and REST dry-run all validate template safety.
- **Disallowed features**: `include`, `source`, `import`, `from`, `embed`, `extends`, `use`, `macro` tags blocked.
- **Resource limits**: Size, expression count, and nesting depth throttled to prevent DoS.
- **Strict variables**: Undefined variable access caught and logged vs. silently rendering as empty.
- **Audit trail**: All template changes logged with before/after hashes and actor metadata.

### Network & SSRF Defense
- **HTTPS enforcement**: All endpoints must use HTTPS (filterable via `eai_skip_https_check`).
- **Private IP blocking**: RFC1918 and loopback addresses blocked by default (opt-in via settings).
- **Hostname allowlisting**: Exact matching and wildcard subdomain patterns (e.g., `*.internal.local`).
- **CIDR allowlisting**: IPv4 and IPv6 network ranges supported and validated via `inet_pton()`.

### Data Handling & Nonces
- Input sanitization before database persistence.
- Output escaping in admin views and REST responses.
- Nonce checks on admin-post actions and REST endpoints.
- Queue-first architecture isolates long-running workloads.
- Staging table isolation supports resumable processing with audit trails.

## Database Tables

- `wp_eapi_imports`
  - import definitions and processing settings
  - includes fields such as:
    - endpoint/auth/paths
    - recurrence settings
    - `target_post_type`
    - `title_template`
    - `mapping_template`

- `wp_custom_import_temp`
  - staged payload fragments per import

- `wp_custom_import_logs`
  - run outcomes, row counts, and error details

## Scheduling Model

- Recurring trigger hook: `eai_recurring_import_trigger`
- Immediate trigger hook: `ncsu_api_importer_batch_hook`
- Queue worker hook: `eai_process_import_queue`

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

- WordPress 6.x
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

- `enterprise-api-importer.php` bootstrap
- `includes/core.php` activation and schema migrations
- `includes/content.php` CPT registration
- `includes/import.php` ETL engine, queue, cron orchestration, media helper
- `includes/admin.php` admin UI and admin-post handlers
- `includes/db.php` database access and log writes
- `includes/class-eapi-imports-list-table.php` admin list rendering

## Developer Quick Checks

```bash
php -l enterprise-api-importer.php
php -l includes/core.php
php -l includes/content.php
php -l includes/db.php
php -l includes/import.php
php -l includes/admin.php
php -l includes/class-eapi-imports-list-table.php
```

## License

GPL-2.0-or-later
