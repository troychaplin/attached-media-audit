# Performance Improvement Plan — Attached: Media Audit

Status: **Planning only.** No code changes have been made. This document is the
agreed scope before implementation begins.

## Context

The plugin audits WordPress media attachments. A WP-Cron batch scanner indexes
which posts reference each attachment into a custom table
(`{prefix}media_audit_index`); a React/`@wordpress/dataviews` admin UI reads the
results from `GET /wp-json/attached-media-audit/v1/media`.

The architecture is sound: there is a real index table, server-side pagination
with SQL `LIMIT`/`OFFSET`, SQL-computed counts, and a keyset-cursor scanner. The
scaling problems are not in pagination — they are in per-row read-time lookups,
the total absence of caching, the scanner re-loading all attachment IDs every
tick, and an unbounded "Used In" query. These bite at thousands of media items.

## Ranked risks this plan addresses

| # | Risk | Root cause | Evidence |
|---|------|-----------|----------|
| 1 | N+1 per-row lookups on every list request | `prepare_item()` does ~8-10 uncached meta/image/link calls per row; raw `$wpdb` rows skip cache priming | `includes/rest/class-media-controller.php:65-117` |
| 2 | No caching of any kind | identical filter/page requests re-run full query + N+1 each time | absence across `useMediaAudit.js`, `class-media-controller.php` |
| 3 | First-batch filesize sweep over all attachments in one tick | `cache_attachment_file_sizes()` not itself batched; touches disk | `includes/scanner/class-batch-runner.php:169-197` |
| 4 | `get_all_attachment_ids()` reloaded every batch and every post save | full ID set rebuilt per tick / per `save_post` | `includes/scanner/class-batch-runner.php:61,147` |
| 5 | Unbounded "Used In" query + per-row edit-link N+1 | `get_locations()` has no LIMIT | `includes/db/class-index-table.php:226`, `includes/admin/class-ajax-handler.php:49` |
| 6 | Sort-by-file_size casts an unindexed postmeta join | `ORDER BY CAST(pm_size.meta_value AS UNSIGNED)` over `wp_postmeta` | `includes/db/class-index-table.php:325,385` |
| 7 | Deep OFFSET pagination | `LIMIT/OFFSET` over a grouped join | `includes/db/class-index-table.php:390` |
| 8 | Leading-wildcard `LIKE '%term%'` search | can't use an index, forces scan | `includes/db/class-index-table.php:284-285` |
| 9 | Row-by-row inserts in `replace_for_post()` | no bulk insert | `includes/db/class-index-table.php:63-76` |

---

## Phase 1 — Quick wins (no schema change)

Highest ROI, lowest risk. Target risks #1, #3, #5, plus a filesize double-read.

### 1.1 Prime caches before `prepare_item()`
- **Where:** `includes/rest/class-media-controller.php:56` (before `array_map`).
- **Change:** Collect the page's post IDs, then call
  `_prime_post_caches( $ids, false, true )` and `update_meta_cache( 'post', $ids )`
  once. This collapses the per-row meta/title/thumbnail lookups into a couple of
  batched queries — what `WP_Query` does automatically and the raw-`$wpdb` path
  currently skips.
- **Risk addressed:** #1. **Expected impact:** removes the dominant list-render
  cost at scale.

### 1.2 Stop double-reading filesize (and alt text)
- **Where:** items query `includes/db/class-index-table.php:374-393`; consumer
  `includes/rest/class-media-controller.php:83,101`.
- **Change:** Select `pm_size.meta_value AS file_size` (the join already exists
  for `ORDER BY`) and read it off the row instead of `get_post_meta()`. Add a
  `LEFT JOIN` for `_wp_attachment_image_alt` and select it too.
- **Risk addressed:** part of #1.

### 1.3 Bound the "Used In" query
- **Where:** `includes/db/class-index-table.php:226-243` and
  `includes/admin/class-ajax-handler.php:49-60`.
- **Change:** Add `LIMIT` (e.g. 50) to `get_locations()` with a "+N more"
  indicator; batch the `get_edit_post_link()` generation (prime caches first).
- **Risk addressed:** #5.

### 1.4 Batch the filesize sweep into the cron loop
- **Where:** `includes/scanner/class-batch-runner.php:169-197` and `run_batch()`.
- **Change:** Process file sizes for *this batch's* attachments (or cap per-tick
  work and reschedule) instead of sweeping all attachments on batch 0.
- **Risk addressed:** #3.

**Phase 1 exit criteria:** a page load of the list issues a bounded, roughly
constant number of queries regardless of `per_page`; the first cron tick no
longer risks `max_execution_time`; popover payloads are bounded.

---

## Phase 2 — Caching layer

Target risks #2 and #4.

### 2.1 Object-cache the counts and page query
- **Where:** `get_counts()` and `get_attachments_rest()` in
  `includes/db/class-index-table.php`.
- **Change:** Wrap in `wp_cache_get`/`wp_cache_set` keyed by the full
  filter/sort/page signature. Invalidate on scan completion
  (`INDEX_BUILT_KEY`), `save_post`, and `delete_attachment`. Counts are the best
  candidate — they change only on scan.
- **Risk addressed:** #2.

### 2.2 Load attachment IDs once per scan, not per batch
- **Where:** `includes/scanner/class-batch-runner.php:61` and `reindex_post():147`.
- **Change:** Cache the attachment-ID set in a transient for the scan's
  duration. In `reindex_post()` (single-post save path) avoid loading the full
  set — validate candidate IDs lazily instead.
- **Risk addressed:** #4 (including slow editor saves at scale).

### 2.3 Client-side result cache (optional)
- **Where:** `src/media-audit/hooks/useMediaAudit.js`.
- **Change:** Cache fetch results by argument signature (or migrate toward a
  `@wordpress/core-data`-style store) so returning to a previous view is
  instant. The existing `AbortController` stays for cancellation.
- **Risk addressed:** #2 (client side).

**Phase 2 exit criteria:** repeated identical requests are served from cache; a
full scan and a single post save each load the attachment-ID set at most once.

---

## Phase 3 — Architectural (bigger lifts)

Target risks #6, #7, #9, and the structural root of #1.

### 3.1 Denormalize derived/sortable data
- **Change:** Store `usage_count`, `file_size`, `mime/media_type`, and
  `missing_alt` as real indexed columns (in the index table or an
  attachment-summary table). The list query then becomes a flat indexed scan —
  no `GROUP BY`, no `CAST`, no postmeta join. Add covering indexes per sort
  order.
- **Risks addressed:** #6, and most of #1/#7.
- **Note:** Requires a DB version bump and migration via the existing
  `maybe_upgrade_db()` path in `includes/class-plugin.php:21`.

### 3.2 Keyset pagination on the read path
- **Change:** Sort by an indexed column plus `WHERE col < :last` to retire
  deep-OFFSET cost. The scanner already uses this pattern.
- **Risk addressed:** #7.

### 3.3 Bulk inserts in `replace_for_post()`
- **Where:** `includes/db/class-index-table.php:63-76`.
- **Change:** Replace the per-row `$wpdb->insert()` loop with a single
  multi-row `INSERT ... VALUES (...),(...),...`.
- **Risk addressed:** #9.

### 3.4 Search (document limitation)
- Leading-wildcard `LIKE '%term%'` (#8) cannot use an index. Either accept and
  document the limitation, or evaluate a prefix/full-text strategy if search
  becomes hot.

**Phase 3 exit criteria:** list queries are flat indexed scans; pagination cost
is independent of page depth; full-scan insert round-trips drop by ~50× (per
post).

---

## Already handled well — do not regress

- Server-side pagination with real SQL `LIMIT`/`OFFSET` and SQL-computed counts
  (`class-index-table.php:390,332-367`).
- Keyset cursor in the scanner (`class-batch-runner.php:86-89`).
- Filesize cached in post meta (`class-media-controller.php:82`).
- Adequate index coverage on join/delete/filter columns
  (`class-index-table.php:26-28`).
- Phantom-row protection via `INNER JOIN wp_posts`
  (`class-index-table.php:121-126,205-213`).
- Allowlisted `ORDER BY` + `prepare()`/`esc_like` (injection-safe).
- Nonce + capability checks on every AJAX/REST entry point.
- `AbortController` cancels stale list fetches; popover result cached
  client-side.

---

## Suggested sequencing

1. **Phase 1** first — small, no schema change, removes the dominant cost.
   Single highest-impact item: **1.1 cache priming**.
2. **Phase 2** — caching + scanner ID-loading fix.
3. **Phase 3** — schema/denormalization and keyset reads, gated behind a DB
   version bump and migration.
