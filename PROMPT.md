Act as an expert WordPress core and plugin developer. I am running a local development environment via WordPress Studio. 

Build a complete, production-ready WordPress plugin named "Media Usage Scanner" that audits the Media Library to identify where attachments are used across the site.

### Technical & Architecture Requirements

1. **Plugin Structure:** Create a standard single-folder plugin structure. Adhere strictly to WordPress Coding Standards (WPCS) and PHP 8.0+ best practices. Use proper escaping, sanitization, and permission checks (`manage_options`).

2. **Admin UI:** Add a top-level menu or a submenu under 'Media' (`upload.php`). Use a native-feeling WordPress layout. The main view must feature a table displaying:
   - Thumbnail
   - Filename / Title
   - Usage Status (Used vs. Unused)
   - Location Links (Edit links for posts, pages, or custom post types where the asset is embedded).

3. **Filtering & Interactivity:** Provide a filter toggle to easily view "All", "Used", or "Unused" media items.

4. **Performance & Modern UX:** - Media libraries can be massive. Do not run unindexed, heavy SQL `LIKE` queries on every page load.
   - Implement an efficient background scanning or batch-processing mechanism (e.g., via WP-Cron, Action Scheduler, or a robust AJAX/REST API-driven batching UI) to index or check usage without timing out.

### Gutenberg & Block Editor Specifics

The modern WordPress block editor stores media references differently than the classic editor. Ensure your scanning logic handles:
- Classic content strings (standard `<img>` tags and shortcodes).
- Gutenberg blocks (e.g., `wp:image {"id":123}` comment delimiters and block attributes).
- Post Featured Images (`_thumbnail_id` meta).

### Output Requirements

1. **File Generation:** Provide the exact file structure and complete source code. Do not use placeholders like `// TODO: implement logic here`.

2. **Technical Architecture Analysis:** At the end of your response, provide a brief breakdown of:
   - Your exact technical strategy for determining asset usage.
   - The edge cases your approach successfully handles vs. the specific edge cases it might miss (e.g., theme-defined background images, CSS files, widgets, or third-party page builder meta).
   - How your implementation ensures the site remains performant during a scan.