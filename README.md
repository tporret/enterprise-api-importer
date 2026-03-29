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

## Security and Reliability Model

- Capability-gated admin operations (`manage_options`).
- Nonce checks on admin-post actions.
- Input sanitization before persistence.
- Output escaping in admin views.
- Queue-first architecture for long-running workloads.
- Staging table isolation to support resumable processing.
- Defensive fallbacks for invalid config states.

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
