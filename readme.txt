=== Attached: Media Audit ===
Contributors:      areziaal
Tags:              media, media library, cleanup, unused media, accessibility
Requires at least: 6.6
Tested up to:      7.0
Stable tag:        1.0.0
Requires PHP:      8.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Know exactly what's in your media library — what's used, where it appears, and what's safe to delete.

== Description ==

Your media library grows over time. Images get uploaded and forgotten. PDFs get replaced but the old ones stick around. Videos get removed from pages but never actually deleted. Before long, you're sitting on gigabytes of files you're not sure you still need.

**Attached: Media Audit gives you a clear picture — and the confidence to clean it up.**

The plugin scans your content and maps every media file to the posts, pages, and templates that use it. Browse your entire media library in one searchable, sortable, filterable table. See at a glance which files are in use and which are sitting idle. Click any file to see exactly which pages reference it before you decide to keep it or delete it.

= Find what's unused =

Every attachment in your media library gets a usage count. Filter down to **Unused** and you'll see exactly which files have no detected references anywhere on your site — safe candidates for deletion. Select them in bulk and remove them in one action.

= Know where files are used before you delete them =

Not sure if a file is really safe to delete? Click **Used In** on any row to open a popover listing every post, page, and template that references it — with direct links to edit each one. No more guessing.

= Spot accessibility issues =

Images embedded in your content without alt text are flagged directly in the audit table. Filter by **Without Alt: Missing** to pull up every image that needs attention — a fast way to work through an accessibility pass without checking posts one by one.

= Catches more than you'd expect =

The scanner finds media references in Gutenberg blocks, featured images, page builder meta fields, and plain HTML content — including files linked as download links (`<a href>`), not just embedded images. Upload a PDF, link to it in a paragraph, and it will show up as used.

= Filters and sorting built in =

Filter by **Location** (block, featured image, HTML content, or post meta), **Type** (image, video, audio, or document), **Used In** (used or unused), or **Without Alt** (images missing alt text). Sort by name, date, file size, or usage count. Search by filename. The table handles hundreds or thousands of files without slowing down.

= Runs in the background =

Scanning is handled by a background job so it never slows down your site for visitors. A progress bar shows you where the scan is. When it's done, the results are ready — and they stay ready until you trigger a new scan.

= Use cases =

* **Pre-cleanup audit** — See what's genuinely unused before deleting anything, so nothing important accidentally disappears.
* **Storage reduction** — Identify large unused files (filter by type: Video or Document, sort by size) and reclaim disk space.
* **Accessibility review** — Find every image in your content that's missing alt text, across your entire site, in one place.
* **Content audit** — Verify that important files (brand assets, legal documents, product images) are still referenced somewhere and haven't been orphaned.
* **Housekeeping after a redesign** — After swapping out imagery or restructuring content, see what the old version left behind.

= Features =

* Background scanner indexes your media library without impacting site performance
* Searchable, sortable, filterable table built with the WordPress DataViews component
* **Location** filter — Block / Featured Image / Content / Post Meta
* **Type** filter — Image / Video / Audio / Document
* **Used In** filter — Used / Unused
* **Without Alt** filter — images missing alt text in content
* **Used In** popover shows every post referencing a file, with direct edit links
* Detects files linked as anchor tags (`<a href>`), not just embedded images and blocks
* Flags images missing alt text wherever they appear in content
* Bulk delete unused files directly from the audit table
* Row actions: Edit, View, Download, Delete Permanently
* Scan Now and Clear Index controls
* Works with Gutenberg, classic editor content, and popular page builders

= Privacy =

Attached: Media Audit is fully self-contained:

* Does not collect or transmit any data
* Does not use cookies or third-party services
* All scanning happens locally on your server

== Installation ==

1. Install from the WordPress plugin directory, or upload the `attached-media-audit` folder to `/wp-content/plugins/`
2. Activate through the **Plugins** menu in WordPress
3. Navigate to **Media → Media Audit**
4. Click **Scan Now** to index your media library
5. When the scan completes, use the filters and search to explore your results

== Frequently Asked Questions ==

= How does the scanner find media references? =

The scanner reads your post content and checks four places: Gutenberg block attributes (for blocks like Image, Cover, Gallery, File, Video, and Audio), `<img>` tags and `<a href>` links in HTML content, featured image post meta, and page builder meta fields. Any attachment ID or upload URL found in those places is counted as a reference.

= Will it find files I've linked to, not just embedded images? =

Yes. If you've linked to a PDF, CSV, or other file using an anchor tag (`<a href="...">`), the scanner will detect it and mark that file as used, as long as the link points to your uploads directory.

= How long does a scan take? =

It depends on how many posts you have. The scanner runs in the background using WP-Cron and processes posts in batches, so it won't time out or slow down your site. A progress bar keeps you updated. Most sites finish in under a minute; larger sites with thousands of posts may take a few minutes.

= Is it safe to delete files marked as Unused? =

The audit covers the places most sites store media references — blocks, HTML content, featured images, and common page builder meta fields. If you use a custom theme or plugin that stores attachment IDs in unusual places not covered by the scanner, those references won't be detected. When in doubt, check the file's direct URL against your server logs, or keep a backup before bulk-deleting.

= Can I re-scan after publishing new content? =

Yes — click **Scan Now** at any time to rebuild the index from scratch. The previous results stay visible until the new scan completes.

= What does "Used In" show? =

Clicking **Used In** on any row opens a popover listing every post, page, and template that references that file. Each entry links directly to the post editor so you can review or update the reference.

= What counts as a missing alt? =

The "No alt" badge appears when an image is embedded in your content (via a block or classic editor HTML) without an alt attribute, or with an empty alt attribute. It reflects how the image appears in your content — not the alt text field in the Media Library. An image can have alt text set in the Media Library but still be flagged here if it was inserted into a post without alt text.

= Does it work with page builders? =

The scanner checks common page builder meta fields (Elementor and Beaver Builder are supported out of the box). Developers can extend coverage to additional meta keys using the `attached_media_audit_scanned_meta_keys` filter.

= Will a scan affect my site's performance? =

No. The scanner runs via WP-Cron in small batches and is designed to stay off the critical path for visitors. The admin table reads from a pre-built index, so browsing results is fast regardless of how large your media library is.

== Screenshots ==

1. The Media Audit table — browse your entire media library with usage counts, file sizes, and type indicators at a glance.
2. Filter chips let you narrow to Unused files, a specific type, a location, or images missing alt text.
3. The Used In popover lists every post referencing a file, with direct links to edit each one.
4. The scan toolbar shows live progress while the background scanner builds the index.

== Changelog ==

= 1.0.0 =

* Initial release
* Background scanner via WP-Cron — indexes posts in batches with live progress tracking
* Detects media references in Gutenberg blocks, HTML content (images, links, shortcodes), featured images, and post meta
* Anchor link detection — files linked via `<a href>` in post content are counted as used
* Missing alt text detection for images embedded in content
* Sortable, filterable table built with WordPress DataViews
* Location filter: Block / Featured Image / Content / Post Meta
* Type filter: Image / Video / Audio / Document
* Used In filter: Used / Unused
* Without Alt filter: images missing alt text
* Used In popover with direct edit links for every referencing post
* Bulk delete for unused attachments
* Row actions: Edit, View, Download, Delete Permanently
* Clear Index and Scan Now controls
