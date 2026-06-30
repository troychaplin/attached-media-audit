<img src="assets/banner-772x250.png" alt="Attached Media Audit" style="width: 100%; height: auto;">

# Attached: Media Audit

A WordPress plugin that audits your media library — showing which files are used, where they appear, and which are safe to clean up.

## Features

- **Background scanner** indexes every post's media references via WP-Cron, with live progress feedback
- **React admin UI** built with `@wordpress/dataviews` — sortable columns, filters, pagination, and bulk actions
- Detects references in **Gutenberg blocks**, **classic editor** HTML, **featured images**, and **post meta**
- Flags images with **missing alt text** in content (separate from the Media Library alt field)
- **Used In** popover shows every post referencing an attachment with a direct edit link
- Row actions: **Edit**, **View**, **Download**, **Delete Permanently**
- **Clear Index** resets scan data; **Scan Now** triggers a fresh full scan

## Requirements

- WordPress 6.6+
- PHP 8.0+
- Node.js + pnpm (for development builds)

## Installation

1. Copy the `smart-media-audit` folder into `wp-content/plugins/`.
2. Run `pnpm install && pnpm build` from the plugin root.
3. Activate the plugin in **Plugins → Installed Plugins**.
4. Navigate to **Media → Media Audit**.
5. Click **Scan Now** to index your media library.

## Admin UI

Found under **Media → Media Audit**. The table shows all attachments with the following columns:

| Column | Notes |
|---|---|
| Preview | Thumbnail or file-type icon |
| File Name | Links to edit; hover reveals row actions |
| Type | Image / Video / Audio / Document — filterable |
| Location | Block / Classic Editor / Featured Image / Post Meta — filterable |
| Usage | Used / Unused — filterable |
| Used In | Count of referencing posts; click to open a popover list |
| Size | File size in human-readable format — sortable |
| Alt Text | "No alt" badge when an image is embedded without alt text |
| Date | Upload date — sortable |

### Scan states

- **Scan required** — the index has not been built yet (or was cleared)
- **Unused** — the index is built and this file has no detected references
- **N posts** — the file is referenced by N posts; click to see them

## Architecture

### Scanner

The scanner runs as a WP-Cron job in batches of 50 posts. It indexes four reference types:

| Type | Source |
|---|---|
| `block` | Gutenberg block attributes (`core/image`, `core/cover`, `core/gallery`, etc.) |
| `classic` | `<img>` and `<a>` tags in classic editor HTML |
| `featured_image` | `_thumbnail_id` post meta |
| `postmeta` | Other meta keys returning attachment IDs (configurable via `smart_media_audit_scanned_meta_keys` filter) |

Alt text detection for block images reads the rendered `<img alt>` in `innerHTML` via `WP_HTML_Tag_Processor`, not the block's JSON attributes (which don't store alt for `core/image`).

### REST API

```
GET /wp-json/smart-media-audit/v1/media
```

Parameters: `page`, `per_page`, `search`, `orderby` (`title|date|usage|file_size`), `order` (`asc|desc`), `type_filter`, `ref_filter`, `usage_filter` (`used|unused`).

Returns server-paginated results with `X-WP-Total` and `X-WP-TotalPages` headers.

### Database

Table: `{prefix}smart_media_audit_index`

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` | Auto-increment primary key |
| `attachment_id` | `bigint` | Foreign key to `wp_posts.ID` |
| `source_post_id` | `bigint` | Post where the reference was found |
| `reference_type` | `varchar(20)` | `block`, `classic`, `featured_image`, or `postmeta` |
| `missing_alt` | `tinyint(1)` | 1 when the image is used in content without alt text |
| `last_scanned` | `datetime` | When this row was last written |

File sizes are cached in post meta (`_smart_media_audit_filesize`) on the first scan to support fast SQL `ORDER BY`.

### DB versioning

The DB version is tracked in the `smart_media_audit_db_version` option. Bumping `SMART_MEDIA_AUDIT_VERSION` in `smart-media-audit.php` triggers `dbDelta()` automatically on the next page load via `Plugin::maybe_upgrade_db()`.

## Development

```bash
pnpm install
pnpm build      # production build
pnpm start      # watch mode
```

**Entry:** `src/smart-media-audit/index.js`  
**Output:** `build/smart-media-audit-admin.{js,css,asset.php}`  
**Webpack:** extends `@wordpress/scripts` defaults via `webpack.config.js`

### Key source files

```
includes/
  db/class-index-table.php          — schema, get_attachments_rest(), replace_for_post()
  rest/class-media-controller.php   — REST endpoint, prepare_item()
  scanner/class-block-parser.php    — Gutenberg block attachment extraction
  scanner/class-classic-parser.php  — Classic editor HTML parsing
  scanner/class-meta-parser.php     — Post meta scanning
  scanner/class-post-scanner.php    — Orchestrates parsers, writes to index
  scanner/class-batch-runner.php    — WP-Cron batching, progress tracking
  admin/class-ajax-handler.php      — AJAX actions (scan, progress, locations, clear)
  admin/class-admin-menu.php        — Asset enqueue, wpSmartMediaAudit JS global
  class-plugin.php                  — Bootstrap, DB upgrade check, hooks

src/smart-media-audit/
  App.js                            — DataViews component, field definitions
  hooks/useSmartMediaAudit.js            — REST fetch with AbortController
  hooks/useScanProgress.js          — AJAX polling, scan state machine
  components/ScanToolbar.js         — Scan Now + Clear Index + progress bar
  components/ThumbnailCell.js       — Image preview or dashicon fallback
  components/TitleCell.js           — Filename + hover row actions
  components/UsedInCell.js          — Toggleable Popover listing source posts
  styles.scss
```

## Hooks

**`smart_media_audit_scanned_meta_keys`** — Filter the list of post meta keys the scanner checks for attachment IDs.

```php
add_filter( 'smart_media_audit_scanned_meta_keys', function( array $keys ): array {
    $keys[] = 'my_custom_image_field';
    return $keys;
} );
```

## License

GPL-2.0-or-later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)