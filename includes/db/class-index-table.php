<?php
namespace WP_Media_Audit\DB;

class Index_Table {

	const TABLE_NAME = 'media_audit_index';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	public static function create(): void {
		global $wpdb;
		$table      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			source_post_id bigint(20) unsigned NOT NULL,
			reference_type varchar(32) NOT NULL DEFAULT 'classic',
			missing_alt tinyint(1) NOT NULL DEFAULT 0,
			last_scanned datetime NOT NULL,
			PRIMARY KEY (id),
			KEY attachment_id (attachment_id),
			KEY source_post_id (source_post_id),
			KEY att_type (attachment_id, reference_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function drop(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	public static function truncate(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Remove all index rows for a given source post, then insert fresh ones.
	 *
	 * @param int   $source_post_id
	 * @param array $rows  Each: [ attachment_id => int, reference_type => string, missing_alt => int ]
	 */
	public static function replace_for_post( int $source_post_id, array $rows ): void {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'source_post_id' => $source_post_id ), array( '%d' ) );

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'attachment_id'  => (int) $row['attachment_id'],
					'source_post_id' => $source_post_id,
					'reference_type' => sanitize_key( $row['reference_type'] ),
					'missing_alt'    => isset( $row['missing_alt'] ) ? (int) $row['missing_alt'] : 0,
					'last_scanned'   => $now,
				),
				array( '%d', '%d', '%s', '%d', '%s' )
			);
		}
	}

	/**
	 * Return all rows for the list table, filtered by usage status.
	 *
	 * @param string $filter  'all' | 'used' | 'unused'
	 * @param string $search  Filename substring to search.
	 * @param int    $per_page
	 * @param int    $paged
	 * @return array { items: array, total: int }
	 */
	public static function get_attachments( string $filter, string $search, int $per_page, int $paged, string $orderby = 'post_date', string $order = 'DESC' ): array {
		global $wpdb;
		$table       = self::table_name();
		$posts_table = $wpdb->posts;
		$offset      = ( $paged - 1 ) * $per_page;

		$search_sql = '';
		$search_arg = array();
		if ( $search ) {
			$search_sql = " AND p.post_title LIKE %s";
			$search_arg = array( '%' . $wpdb->esc_like( $search ) . '%' );
		}

		// Build ORDER BY from a fixed allowlist — never interpolate raw input.
		// 'usage' orders by the aggregate expression, not the usage_count alias,
		// since the SQLite integration does not reliably support alias references.
		$order_map = array(
			'post_title' => 'p.post_title',
			'post_date'  => 'p.post_date',
			'usage'      => 'COUNT(idx.id)',
		);
		$order_col = $order_map[ $orderby ] ?? 'p.post_date';
		$order_dir = ( 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC';

		// Use COUNT(idx.id) directly in HAVING to avoid column-alias references,
		// which the SQLite integration does not reliably support.
		$having = '';
		if ( 'used' === $filter ) {
			$having = 'HAVING COUNT(idx.id) > 0';
		} elseif ( 'unused' === $filter ) {
			$having = 'HAVING COUNT(idx.id) = 0';
		}

		// "used" count is anchored on the posts table (INNER JOIN) so phantom or
		// orphaned index rows can never inflate it past the real attachment total.
		$used_count_sql = "SELECT COUNT(DISTINCT p.ID)
			FROM {$posts_table} p
			INNER JOIN {$table} idx ON idx.attachment_id = p.ID
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'";

		$total_count_sql = "SELECT COUNT(*) FROM {$posts_table} p
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'";

		// Flat count queries — avoid FROM(subquery) AS alias, which breaks on SQLite.
		// Only call prepare() when a placeholder is actually present, otherwise
		// wpdb::prepare emits a _doing_it_wrong notice under WP_DEBUG.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'used' === $filter ) {
			$count = (int) self::count_query( "{$used_count_sql}{$search_sql}", $search_arg );
		} elseif ( 'unused' === $filter ) {
			$total = (int) self::count_query( "{$total_count_sql}{$search_sql}", $search_arg );
			$used  = (int) self::count_query( "{$used_count_sql}{$search_sql}", $search_arg );
			$count = max( 0, $total - $used );
		} else {
			$count = (int) self::count_query( "{$total_count_sql}{$search_sql}", $search_arg );
		}

		$items_args = array_merge( $search_arg, array( $per_page, $offset ) );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.ID,
					p.post_title,
					p.post_mime_type,
					p.post_date,
					COUNT(idx.id) AS usage_count
				FROM {$posts_table} p
				LEFT JOIN {$table} idx ON idx.attachment_id = p.ID
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
				{$search_sql}
				GROUP BY p.ID
				{$having}
				ORDER BY {$order_col} {$order_dir}
				LIMIT %d OFFSET %d",
				...$items_args
			)
		);
		// phpcs:enable

		return array(
			'items' => $items ?: array(),
			'total' => $count,
		);
	}

	/**
	 * Run a COUNT query, calling prepare() only when args are present.
	 *
	 * @param string $sql
	 * @param array  $args
	 * @return int
	 */
	private static function count_query( string $sql, array $args ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $args ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
		}
		return (int) $wpdb->get_var( $sql );
		// phpcs:enable
	}

	/**
	 * Return counts for each filter tab.
	 */
	public static function get_counts(): array {
		global $wpdb;
		$table       = self::table_name();
		$posts_table = $wpdb->posts;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$posts_table}
			WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		// INNER JOIN to wp_posts so orphaned/phantom index rows (a deleted
		// attachment, or an ID that was never a real attachment) cannot inflate
		// the used count. DISTINCT prevents double-counting multi-post usage.
		$used = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT idx.attachment_id)
			FROM {$table} idx
			INNER JOIN {$posts_table} p ON p.ID = idx.attachment_id
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'"
		);
		// phpcs:enable

		$unused = max( 0, $total - $used );

		return compact( 'total', 'used', 'unused' );
	}

	/**
	 * Return source posts for a given attachment ID.
	 * Excludes trashed and auto-draft sources so the locations list reflects
	 * live content only.
	 */
	public static function get_locations( int $attachment_id ): array {
		global $wpdb;
		$table       = self::table_name();
		$posts_table = $wpdb->posts;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, idx.reference_type
				FROM {$table} idx
				INNER JOIN {$posts_table} p ON p.ID = idx.source_post_id
				WHERE idx.attachment_id = %d
				AND p.post_status NOT IN ('trash', 'auto-draft')
				ORDER BY p.post_title ASC",
				$attachment_id
			)
		);
	}

	/**
	 * Return paginated attachments for the REST endpoint.
	 *
	 * Supports MIME type grouping, reference_type filtering via conditional HAVING
	 * (SQLite-compatible — no subquery), and used/unused toggling.
	 *
	 * @param string $search
	 * @param int    $per_page
	 * @param int    $page
	 * @param string $orderby        title|date|usage
	 * @param string $order          ASC|DESC
	 * @param string $media_type     Image|Video|Audio|Document
	 * @param string $reference_type block|featured_image|classic|postmeta
	 * @param string $usage_filter   used|unused|''
	 * @return array{ items: array, total: int }
	 */
	public static function get_attachments_rest(
		string $search = '',
		int    $per_page = 20,
		int    $page = 1,
		string $orderby = 'date',
		string $order = 'DESC',
		string $media_type = '',
		string $reference_type = '',
		string $usage_filter = ''
	): array {
		global $wpdb;
		$table       = self::table_name();
		$posts_table = $wpdb->posts;
		$offset      = ( $page - 1 ) * $per_page;

		// Attachment base condition (always applied).
		$base_where = "p.post_type = 'attachment' AND p.post_status = 'inherit'";

		// Additional WHERE parts built from validated inputs.
		$extra_where_parts = array();
		$extra_where_args  = array();

		if ( $search ) {
			$extra_where_parts[] = 'p.post_title LIKE %s';
			$extra_where_args[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// MIME type grouping — safe to interpolate (hardcoded strings, no user input).
		switch ( $media_type ) {
			case 'Image':
				$extra_where_parts[] = "p.post_mime_type LIKE 'image/%'";
				break;
			case 'Video':
				$extra_where_parts[] = "p.post_mime_type LIKE 'video/%'";
				break;
			case 'Audio':
				$extra_where_parts[] = "p.post_mime_type LIKE 'audio/%'";
				break;
			case 'Document':
				$extra_where_parts[] = "p.post_mime_type NOT LIKE 'image/%' AND p.post_mime_type NOT LIKE 'video/%' AND p.post_mime_type NOT LIKE 'audio/%'";
				break;
		}

		$extra_where_sql = $extra_where_parts ? ' AND ' . implode( ' AND ', $extra_where_parts ) : '';
		$full_where_sql  = $base_where . $extra_where_sql;

		// HAVING clause for items query.
		$having_sql  = '';
		$having_args = array();
		if ( $reference_type ) {
			// Conditional SUM avoids a subquery and is SQLite-compatible.
			$having_sql    = 'HAVING SUM(CASE WHEN idx.reference_type = %s THEN 1 ELSE 0 END) > 0';
			$having_args[] = $reference_type;
		} elseif ( 'unused' === $usage_filter ) {
			$having_sql = 'HAVING COUNT(idx.id) = 0';
		} elseif ( 'used' === $usage_filter ) {
			$having_sql = 'HAVING COUNT(idx.id) > 0';
		}

		// ORDER BY from allowlist — never interpolate raw input.
		$order_map = array(
			'title'     => 'p.post_title',
			'date'      => 'p.post_date',
			'usage'     => 'COUNT(idx.id)',
			'file_size' => 'CAST(pm_size.meta_value AS UNSIGNED)',
		);
		$order_col = $order_map[ $orderby ] ?? 'p.post_date';
		$order_dir = ( 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC';

		// Count: flat queries to avoid FROM(subquery), which breaks on SQLite.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $reference_type ) {
			// Count attachments that have at least one row of this reference_type.
			$count = self::count_query(
				"SELECT COUNT(DISTINCT idx.attachment_id)
				FROM {$table} idx
				INNER JOIN {$posts_table} p ON p.ID = idx.attachment_id
				WHERE idx.reference_type = %s AND {$full_where_sql}",
				array_merge( array( $reference_type ), $extra_where_args )
			);
		} elseif ( 'unused' === $usage_filter ) {
			$total_count = self::count_query(
				"SELECT COUNT(*) FROM {$posts_table} p WHERE {$full_where_sql}",
				$extra_where_args
			);
			$used_count = self::count_query(
				"SELECT COUNT(DISTINCT idx.attachment_id)
				FROM {$table} idx
				INNER JOIN {$posts_table} p ON p.ID = idx.attachment_id
				WHERE {$full_where_sql}",
				$extra_where_args
			);
			$count = max( 0, $total_count - $used_count );
		} elseif ( 'used' === $usage_filter ) {
			$count = self::count_query(
				"SELECT COUNT(DISTINCT idx.attachment_id)
				FROM {$table} idx
				INNER JOIN {$posts_table} p ON p.ID = idx.attachment_id
				WHERE {$full_where_sql}",
				$extra_where_args
			);
		} else {
			$count = self::count_query(
				"SELECT COUNT(*) FROM {$posts_table} p WHERE {$full_where_sql}",
				$extra_where_args
			);
		}

		// Items query.
		$items_args = array_merge( $extra_where_args, $having_args, array( $per_page, $offset ) );

		$postmeta_table = $wpdb->postmeta;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.ID,
					p.post_title,
					p.post_mime_type,
					p.post_date,
					COUNT(idx.id) AS usage_count,
					COALESCE(MAX(idx.missing_alt), 0) AS content_alt_missing
				FROM {$posts_table} p
				LEFT JOIN {$table} idx ON idx.attachment_id = p.ID
				LEFT JOIN {$postmeta_table} pm_size ON pm_size.post_id = p.ID AND pm_size.meta_key = '_wp_media_audit_filesize'
				WHERE {$full_where_sql}
				GROUP BY p.ID
				{$having_sql}
				ORDER BY {$order_col} {$order_dir}
				LIMIT %d OFFSET %d",
				...$items_args
			)
		);
		// phpcs:enable

		return array(
			'items' => $items ?: array(),
			'total' => (int) $count,
		);
	}

	/**
	 * Delete all index rows that originate from a given source post.
	 * Used when a post is trashed or permanently deleted.
	 */
	public static function delete_for_post( int $source_post_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table_name(), array( 'source_post_id' => $source_post_id ), array( '%d' ) );
	}

	/**
	 * Delete all index rows that reference a given attachment.
	 * Used when an attachment is permanently deleted.
	 */
	public static function delete_for_attachment( int $attachment_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table_name(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
	}
}
