<img src="assets/banner-772x250.png" alt="Attached: Media Audit" style="width: 100%; height: auto;">

# Attached: Media Audit

A WordPress plugin that audits your media library — showing which files are used, where they appear, and which are safe to clean up.

## Features

- **Background scanner** indexes every post's media references via WP-Cron, with live progress feedback
- **React admin UI** built with `@wordpress/dataviews` — sortable columns, filters, pagination, and bulk actions
- Detects references in **Gutenberg blocks**, **HTML content** (images, links, shortcodes), **featured images**, and **post meta**
- Flags images with **missing alt text** in content (separate from the Media Library alt field)
- **Used In** popover shows every post referencing an attachment with a direct edit link
- Row actions: **Edit**, **View**, **Download**, **Delete Permanently**
- **Clear Index** resets scan data; **Scan Now** triggers a fresh full scan

## Requirements

- WordPress 6.6+
- PHP 8.0+
- Node.js + pnpm (for development builds)

## Installation

1. Copy the `attached-media-audit` folder into `wp-content/plugins/`.
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
| Type | Image / Video / Audio / Document |
| Used In | Count of referencing posts; click to open a popover list |
| Size | File size in human-readable format — sortable |
| Alt Text | "No alt" badge when an image is embedded without alt text |
| Date | Upload date — sortable |

### Filters

Four primary filter chips appear above the table:

| Filter | Options |
|---|---|
| Location | Block / Featured Image / Content / Post Meta |
| Type | Image / Video / Audio / Document |
| Used In | Used / Unused |
| Without Alt | Missing |

### Scan states

- **Scan required** — the index has not been built yet (or was cleared)
- **Unused** — the index is built and this file has no detected references
- **N posts** — the file is referenced by N posts; click to see them

## Architecture

### Scanner

The scanner runs as a WP-Cron job in three phases: posts → file sizes → summary. Posts are indexed in batches of 50. It detects four reference types:

| Type | Source |
|---|---|
| `block` | Gutenberg block attributes (`core/image`, `core/cover`, `core/gallery`, `core/file`, `core/video`, `core/audio`, `core/media-text`) |
| `classic` | `<img>` tags, `<a href>` links to uploads, and shortcodes (`[gallery]`, `[caption]`) in post content HTML |
| `featured_image` | `_thumbnail_id` post meta |
| `postmeta` | Other meta keys returning attachment IDs (configurable via `attached_media_audit_scanned_meta_keys` filter) |

Alt text detection for block images reads the rendered `<img alt>` in `innerHTML` via `WP_HTML_Tag_Processor`. Anchor links (`<a href>`) are resolved to attachment IDs via `attachment_url_to_postid()`.

### REST API

```
GET /wp-json/attached-media-audit/v1/media
```

| Parameter | Type | Description |
|---|---|---|
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Items per page, max 100 (default: 20) |
| `search` | string | Filter by filename |
| `orderby` | string | `title`, `date`, `usage`, or `file_size` |
| `order` | string | `ASC` or `DESC` |
| `media_type` | string | `Image`, `Video`, `Audio`, or `Document` |
| `reference_type` | string | `block`, `featured_image`, `classic`, or `postmeta` |
| `usage_filter` | string | `used` or `unused` |
| `missing_alt` | boolean | `true` to return only images missing alt text |

Response body:

```json
{
  "items": [...],
  "total": 42,
  "pages": 3
}
```

### Database

Two tables are created on activation.

**`{prefix}attached_media_audit_index`** — one row per attachment-per-post reference:

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` | Auto-increment primary key |
| `attachment_id` | `bigint` | Foreign key to `wp_posts.ID` |
| `source_post_id` | `bigint` | Post where the reference was found |
| `reference_type` | `varchar(32)` | `block`, `classic`, `featured_image`, or `postmeta` |
| `missing_alt` | `tinyint(1)` | 1 when the image is used in content without alt text |
| `last_scanned` | `datetime` | When this row was last written |

**`{prefix}attached_media_audit_summary`** — denormalized one-row-per-attachment projection used by the REST read path (no `GROUP BY` at query time):

| Column | Type | Description |
|---|---|---|
| `attachment_id` | `bigint` | Primary key |
| `mime_type` | `varchar(100)` | Raw MIME type |
| `media_type` | `varchar(16)` | `Image`, `Video`, `Audio`, or `Document` |
| `post_title` | `text` | Attachment title |
| `post_date` | `datetime` | Upload date |
| `file_size` | `bigint` | Cached file size in bytes |
| `alt_text` | `text` | Value of `_wp_attachment_image_alt` |
| `usage_count` | `int` | Number of posts referencing this attachment |
| `missing_alt` | `tinyint(1)` | 1 when used in content without alt text |
| `has_block` | `tinyint(1)` | Referenced via a block |
| `has_featured_image` | `tinyint(1)` | Referenced as a featured image |
| `has_classic` | `tinyint(1)` | Referenced in HTML content |
| `has_postmeta` | `tinyint(1)` | Referenced via post meta |

File sizes are cached in post meta (`_attached_media_audit_filesize`) on first scan to support fast SQL `ORDER BY file_size`.

### DB versioning

The DB version is tracked in the `attached_media_audit_db_version` option. Bumping `ATTACHED_MEDIA_AUDIT_VERSION` in `attached-media-audit.php` triggers `dbDelta()` automatically on the next page load via `Plugin::maybe_upgrade_db()`.

## Development

```bash
pnpm install
pnpm build      # production build
pnpm start      # watch mode
```

**Entry:** `src/attached-media-audit/index.js`  
**Output:** `build/attached-media-audit-admin.{js,css,asset.php}`  
**Webpack:** extends `@wordpress/scripts` defaults via `webpack.config.js`

### Key source files

```
includes/
  db/class-index-table.php          — schema, get_attachments_rest(), replace_for_post()
  rest/class-media-controller.php   — REST endpoint, prepare_item()
  scanner/class-block-parser.php    — Gutenberg block attachment extraction
  scanner/class-classic-parser.php  — HTML content parsing (img, a, shortcodes)
  scanner/class-meta-parser.php     — Post meta scanning
  scanner/class-post-scanner.php    — Orchestrates parsers, writes to index
  scanner/class-batch-runner.php    — WP-Cron batching, progress tracking
  admin/class-ajax-handler.php      — AJAX actions (scan, progress, locations, clear)
  admin/class-admin-menu.php        — Asset enqueue, wpAttachedMediaAudit JS global
  class-plugin.php                  — Bootstrap, DB upgrade check, hooks

src/attached-media-audit/
  App.js                            — DataViews component, field definitions
  hooks/useAttachedMediaAudit.js       — REST fetch with AbortController
  hooks/useScanProgress.js          — AJAX polling, scan state machine
  components/ScanToolbar.js         — Scan Now + Clear Index + progress bar
  components/ThumbnailCell.js       — Image preview or dashicon fallback
  components/TitleCell.js           — Filename + hover row actions
  components/UsedInCell.js          — Toggleable Popover listing source posts
  styles.scss
```

## Hooks

**`attached_media_audit_scanned_meta_keys`** — Filter the list of post meta keys the scanner checks for attachment IDs.

```php
add_filter( 'attached_media_audit_scanned_meta_keys', function( array $keys ): array {
    $keys[] = 'my_custom_image_field';
    return $keys;
} );
```

## License

GPL-2.0-or-later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
