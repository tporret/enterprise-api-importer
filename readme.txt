=== Enterprise API Importer ===
Contributors: tporret
Tags: api, import, etl, json, cron, twig
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise ETL importer for WordPress that connects APIs, filters JSON payloads, and maps nested data using Twig and scheduled batches.

== Description ==
Enterprise API Importer is a powerful, enterprise-grade ETL pipeline for WordPress teams that need reliability, control, and visibility when importing external API data at scale.

With Enterprise API Importer, you can orchestrate clean, repeatable import workflows without sacrificing flexibility:

- Multi-Connection Job Manager
- Advanced JSON array traversing and data filtering to discard noise before database insertion
- Twig Templating Engine for complex logic, loops, and nested object mapping without clunky drag-and-drop builders
- Time-Aware Batch Processing via WP-Cron to help prevent server timeouts and memory exhaustion
- Single-pane-of-glass Health and Schedules Dashboard for operational visibility

Whether you are importing product catalogs, records, listings, or custom business data, Enterprise API Importer gives technical teams a scalable framework for structured API-to-WordPress ETL.

== Installation ==
1. Upload the plugin folder to the /wp-content/plugins/ directory, or install it through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to the Enterprise API Importer admin pages to configure your API connections, filtering rules, and mapping templates.

== Frequently Asked Questions ==
= Does this require WP-CLI? =
No. It uses native WP-Cron scheduling, but supports CLI triggers.

= Can I parse nested JSON arrays? =
Yes. Nested objects and arrays can be parsed and transformed through Twig templating.

= Does it handle API authentication? =
Yes. It supports Bearer Tokens and custom headers.

== Screenshots ==
1. The Twig Mapping Interface for transforming JSON data.
2. The Schedules and Logs Health Dashboard.
3. API Connection and Data Filtering rules.

== Changelog ==
= 1.0.0 =
* Initial Release.
