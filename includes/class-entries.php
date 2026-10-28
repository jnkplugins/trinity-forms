<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required and these are admin-side, uncached by design.

/**
 * Query + mutation layer for entries.
 *
 * Everything that reads or changes an entry row goes through here: the admin list
 * view, the bulk-action handlers, the REST routes and the cron purge. Before this
 * class the list view built its own SQL inline, which is why "search" and "bulk"
 * had nowhere to live — a second caller would have meant a second copy of the
 * WHERE builder.
 *
 * Status vs. view: `status` is the stored column ('completed', 'pending', 'spam',
 * 'trash', 'partial', …). A *view* is what the filter bar offers — most views map
 * to a status, but "unread" and "starred" are flags that cut across statuses, so
 * they are handled separately in build_where().
 */
class Entries {

    /** Views that are flags rather than stored statuses. */
    public const FLAG_VIEWS = [ "unread", "starred" ];

    /** Statuses hidden from the default "all" view — they have their own tabs. */
    public const HIDDEN_FROM_ALL = [ "spam", "trash", "partial" ];

    /**
     * Statuses that count as a real, finished submission: what Results, the forms
     * list and front-end Views report on. An approved entry is a completed one that
     * a reviewer has looked at, so it counts too.
     *
     * @return array<int,string>
     */
    public static function counted_statuses(): array {
        return array_values( array_unique( (array) apply_filters( "trinity_forms_counted_statuses", [ "completed", "approved" ] ) ) );
    }

    /** SQL list of HIDDEN_FROM_ALL, e.g. 'spam','trash','partial'. Constants only, never input. */
    private static function hidden_sql(): string {
        return "'" . implode( "','", self::HIDDEN_FROM_ALL ) . "'";
    }

    /** Human label for a stored payment status ("pending" reads "Pending", "none" reads "No payment"). */
    public static function payment_label( string $status ): string {
        $map = [
            "paid"     => __( "Paid", "trinity-forms" ),
            "pending"  => __( "Pending", "trinity-forms" ),
            "failed"   => __( "Failed", "trinity-forms" ),
            "refunded" => __( "Refunded", "trinity-forms" ),
            "none"     => __( "No payment", "trinity-forms" ),
            ""         => __( "No payment", "trinity-forms" ),
        ];
        return $map[ $status ] ?? ucfirst( str_replace( [ "_", "-" ], " ", $status ) );
    }

    /** Human label for a stored status slug ("pending_review" reads "Pending review"). */
    public static function status_label( string $status ): string {
        $map = [
            "completed"      => __( "Completed", "trinity-forms" ),
            "pending"        => __( "Pending", "trinity-forms" ),
            "spam"           => __( "Spam", "trinity-forms" ),
            "trash"          => __( "Trash", "trinity-forms" ),
            "partial"        => __( "Partial", "trinity-forms" ),
            "failed"         => __( "Failed", "trinity-forms" ),
            "pending_review" => __( "Pending review", "trinity-forms" ),
            "approved"       => __( "Approved", "trinity-forms" ),
            "rejected"       => __( "Rejected", "trinity-forms" ),
        ];
        $label = $map[ $status ] ?? ucfirst( str_replace( [ "_", "-" ], " ", $status ) );
        return (string) apply_filters( "trinity_forms_entry_status_label", $label, $status );
    }

    /**
     * Every status an entry may hold.
     *
     * Filterable so premium features can add their own (moderation contributes
     * pending_review / approved / rejected) without set_status() growing a hard-coded
     * list that the free build would also have to carry.
     *
     * @return array<int,string>
     */
    public static function statuses(): array {
        return (array) apply_filters(
            "trinity_forms_entry_statuses",
            [ "completed", "pending", "spam", "trash", "partial", "failed" ]
        );
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . "trinity_forms_entries";
    }

    public static function meta_table(): string {
        global $wpdb;
        return $wpdb->prefix . "trinity_forms_entry_meta";
    }

    /**
     * Build the shared WHERE clause + bound params for a set of list filters.
     *
     * @param array<string,mixed> $args
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function build_where( array $args ): array {
        global $wpdb;

        $where  = [ "1=1" ];
        $params = [];

        $form_id = (int) ( $args["form_id"] ?? 0 );
        if ( $form_id > 0 ) {
            $where[]  = "e.form_id = %d";
            $params[] = $form_id;
        }

        $view = (string) ( $args["view"] ?? "" );
        if ( "unread" === $view ) {
            $where[] = "e.is_read = 0";
            $where[] = "e.status NOT IN (" . self::hidden_sql() . ")";
        } elseif ( "starred" === $view ) {
            $where[] = "e.is_starred = 1";
            $where[] = "e.status NOT IN (" . self::hidden_sql() . ")";
        } elseif ( "counted" === $view ) {
            // Finished submissions only (completed, approved): what a public View shows.
            $counted = self::counted_statuses();
            $where[] = "e.status IN (" . implode( ",", array_fill( 0, count( $counted ), "%s" ) ) . ")";
            array_push( $params, ...$counted );
        } elseif ( "" !== $view && "all" !== $view ) {
            $where[]  = "e.status = %s";
            $params[] = $view;
        } else {
            // "All" deliberately excludes spam and trash: they are destinations, not
            // part of the working set. Without this, emptying a big spam queue makes
            // the default view useless.
            $where[] = "e.status NOT IN (" . self::hidden_sql() . ")";
        }

        $payment = (string) ( $args["payment_status"] ?? "" );
        if ( "" !== $payment ) {
            $where[]  = "e.payment_status = %s";
            $params[] = $payment;
        }

        // Scoping to one submitter. -1 is the deliberate "nobody" value: a front-end
        // View set to "only my entries" and read by a logged-out visitor must return
        // nothing, not everything.
        if ( isset( $args["user_id"] ) ) {
            $where[]  = "e.user_id = %d";
            $params[] = (int) $args["user_id"];
        }

        $from = (string) ( $args["date_from"] ?? "" );
        if ( "" !== $from ) {
            // The owner's days are site days; the column is UTC.
            $where[]  = "e.submitted_at >= %s";
            $params[] = Dates::day_bound( $from );
        }
        $to = (string) ( $args["date_to"] ?? "" );
        if ( "" !== $to ) {
            $where[]  = "e.submitted_at <= %s";
            $params[] = Dates::day_bound( $to, true );
        }

        $search = trim( (string) ( $args["search"] ?? "" ) );
        if ( "" !== $search ) {
            // EXISTS rather than a JOIN: an entry with three matching field values
            // must still be one row, and a JOIN would triple it (and break the count).
            $meta_table = self::meta_table();
            $like       = "%" . $wpdb->esc_like( $search ) . "%";
            $where[]    = "( EXISTS (
                    SELECT 1 FROM {$meta_table} m
                    WHERE m.entry_id = e.id AND m.value LIKE %s
                ) OR e.id = %d )";
            $params[]   = $like;
            $params[]   = ctype_digit( $search ) ? (int) $search : 0;
        }

        // Per-field filters: "answer to field X starts with A", "answer to field Y
        // is exactly Z". Same EXISTS shape as the keyword search, and for the same
        // reason — a filter must narrow the list, not multiply its rows.
        foreach ( (array) ( $args["field_filters"] ?? [] ) as $field_id => $filter ) {
            $field_id = (string) $field_id;
            $filter   = is_array( $filter ) ? $filter : [ "value" => $filter ];
            $value    = (string) ( $filter["value"] ?? "" );
            if ( "" === $field_id || "" === $value ) {
                continue;
            }

            $meta_table = self::meta_table();
            switch ( (string) ( $filter["compare"] ?? "equals" ) ) {
                case "starts_with":
                    $test  = "m.value LIKE %s";
                    $bound = $wpdb->esc_like( $value ) . "%";
                    break;
                case "contains":
                    $test  = "m.value LIKE %s";
                    $bound = "%" . $wpdb->esc_like( $value ) . "%";
                    break;
                default:
                    $test  = "m.value = %s";
                    $bound = $value;
            }

            $where[]  = "EXISTS ( SELECT 1 FROM {$meta_table} m WHERE m.entry_id = e.id AND m.field_id = %s AND {$test} )";
            $params[] = $field_id;
            $params[] = $bound;
        }

        return [ implode( " AND ", $where ), $params ];
    }

    /**
     * Fetch a page of entries plus the total for those same filters.
     *
     * @param array<string,mixed> $args
     * @return array{rows:array<int,object>,total:int}
     */
    public static function query( array $args ): array {
        global $wpdb;

        [ $where, $params ] = self::build_where( $args );
        $table = self::table();

        $per_page = max( 1, min( 500, (int) ( $args["per_page"] ?? 25 ) ) );
        $paged    = max( 1, (int) ( $args["paged"] ?? 1 ) );
        $offset   = ( $paged - 1 ) * $per_page;

        // Whitelist: these go into the SQL string unquoted, so they can never come
        // from the request unfiltered. "field" is the odd one out — it means "sort
        // by an answer", which is resolved into a join below.
        $allowed_orderby = [ "submitted_at", "id", "status", "payment_status", "field" ];
        $orderby = in_array( (string) ( $args["orderby"] ?? "" ), $allowed_orderby, true )
            ? (string) $args["orderby"]
            : "submitted_at";
        $order = strtoupper( (string) ( $args["order"] ?? "DESC" ) ) === "ASC" ? "ASC" : "DESC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count_sql = "SELECT COUNT(*) FROM {$table} e WHERE {$where}";
        $total = (int) ( $params
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : $wpdb->get_var( $count_sql ) );

        // Sorting by an answer rather than by a column on the entries table. The
        // value lives in the EAV meta table, so it is reached with a LEFT JOIN —
        // left, not inner, so entries that never answered that field still appear
        // rather than silently vanishing out of a sorted list.
        $join        = "";
        $order_sql   = "e.{$orderby} {$order}";
        $sort_params = [];

        if ( "field" === $orderby ) {
            $sort_field = (string) ( $args["sort_field"] ?? "" );
            if ( "" === $sort_field ) {
                $order_sql = "e.submitted_at {$order}";
            } else {
                $meta_table  = self::meta_table();
                $join        = " LEFT JOIN {$meta_table} sortm ON sortm.entry_id = e.id AND sortm.field_id = %s";
                // Numeric-aware: on a quantity or price field a plain string sort
                // puts "10" before "9".
                $order_sql   = "( sortm.value + 0 ) {$order}, sortm.value {$order}";
                $sort_params = [ $sort_field ];
            }
        }

        // The join's placeholder appears before the WHERE placeholders in the SQL
        // string, so its value has to come first in the argument list too.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows_sql = "SELECT e.* FROM {$table} e{$join} WHERE {$where} ORDER BY {$order_sql}, e.id {$order} LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results( $wpdb->prepare(
            $rows_sql,
            ...array_merge( $sort_params, $params, [ $per_page, $offset ] )
        ) );

        return [ "rows" => (array) $rows, "total" => $total ];
    }

    /**
     * Every entry id matching a set of filters, unpaginated.
     *
     * For exports, which must cover the whole filtered set rather than the page you
     * happen to be looking at. Ids only — the caller fetches the rows it needs in
     * chunks, so a 50k-entry export never materialises 50k full rows at once.
     *
     * @param array<string,mixed> $args
     * @return array<int,int>
     */
    public static function ids( array $args ): array {
        global $wpdb;

        [ $where, $params ] = self::build_where( $args );
        $table = self::table();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT e.id FROM {$table} e WHERE {$where} ORDER BY e.submitted_at DESC, e.id DESC";
        $ids = $params
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            ? $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) )
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : $wpdb->get_col( $sql );

        return array_map( "absint", (array) $ids );
    }

    /**
     * Counts for every filter-bar tab, for the currently selected form.
     *
     * One query per tab would be six; instead this is two — one grouped by status
     * and one for the two flag views.
     *
     * @return array<string,int>
     */
    public static function counts( int $form_id = 0 ): array {
        global $wpdb;

        $table  = self::table();
        $where  = $form_id > 0 ? "WHERE form_id = %d" : "";
        $params = $form_id > 0 ? [ $form_id ] : [];

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT status, COUNT(*) AS n FROM {$table} {$where} GROUP BY status";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $by_status = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_results( $sql );

        $counts = [ "all" => 0, "unread" => 0, "starred" => 0, "spam" => 0, "trash" => 0 ];
        foreach ( (array) $by_status as $row ) {
            $status = (string) $row->status;
            $n      = (int) $row->n;
            if ( ! in_array( $status, [ "all", "unread", "starred" ], true ) ) {
                $counts[ $status ] = $n;
            }
            if ( ! in_array( $status, self::HIDDEN_FROM_ALL, true ) ) {
                $counts["all"] += $n;
            }
        }

        $flag_where = $form_id > 0 ? "WHERE form_id = %d AND" : "WHERE";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $flag_sql = "SELECT
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread,
                SUM(CASE WHEN is_starred = 1 THEN 1 ELSE 0 END) AS starred
            FROM {$table} {$flag_where} status NOT IN (" . self::hidden_sql() . ")";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $flags = $params ? $wpdb->get_row( $wpdb->prepare( $flag_sql, ...$params ) ) : $wpdb->get_row( $flag_sql );

        $counts["unread"]  = (int) ( $flags->unread  ?? 0 );
        $counts["starred"] = (int) ( $flags->starred ?? 0 );

        return $counts;
    }

    /** @return array<string,mixed>|null */
    public static function get( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * The neighbouring entry ids under the same filters, for prev/next navigation.
     *
     * @param array<string,mixed> $args The same filter args the list view used.
     * @return array{prev:int,next:int}
     */
    public static function neighbours( int $id, array $args ): array {
        global $wpdb;

        [ $where, $params ] = self::build_where( $args );
        $table = self::table();

        $entry = self::get( $id );
        if ( ! $entry ) {
            return [ "prev" => 0, "next" => 0 ];
        }
        $at = (string) $entry["submitted_at"];

        // "Next" means further down the default DESC list, i.e. older.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $next_sql = "SELECT e.id FROM {$table} e WHERE {$where} AND (e.submitted_at < %s OR (e.submitted_at = %s AND e.id < %d)) ORDER BY e.submitted_at DESC, e.id DESC LIMIT 1";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $next = (int) $wpdb->get_var( $wpdb->prepare( $next_sql, ...array_merge( $params, [ $at, $at, $id ] ) ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $prev_sql = "SELECT e.id FROM {$table} e WHERE {$where} AND (e.submitted_at > %s OR (e.submitted_at = %s AND e.id > %d)) ORDER BY e.submitted_at ASC, e.id ASC LIMIT 1";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $prev = (int) $wpdb->get_var( $wpdb->prepare( $prev_sql, ...array_merge( $params, [ $at, $at, $id ] ) ) );

        return [ "prev" => $prev, "next" => $next ];
    }

    /**
     * Set a boolean flag on a set of entries.
     *
     * @param array<int,int> $ids
     */
    private static function set_flag( array $ids, string $column, bool $on ): int {
        global $wpdb;

        $ids = array_values( array_filter( array_map( "absint", $ids ) ) );
        if ( ! $ids || ! in_array( $column, [ "is_read", "is_starred" ], true ) ) {
            return 0;
        }

        $table  = self::table();
        $holds  = implode( ",", array_fill( 0, count( $ids ), "%d" ) );
        $value  = $on ? 1 : 0;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql    = "UPDATE {$table} SET {$column} = %d WHERE id IN ({$holds})";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $n = (int) $wpdb->query( $wpdb->prepare( $sql, ...array_merge( [ $value ], $ids ) ) );
        do_action( "trinity_forms_entry_flag_changed", $ids, $column, $on );
        return $n;
    }

    /** @param array<int,int> $ids */
    public static function set_read( array $ids, bool $read = true ): int {
        return self::set_flag( $ids, "is_read", $read );
    }

    /** @param array<int,int> $ids */
    public static function set_starred( array $ids, bool $starred = true ): int {
        return self::set_flag( $ids, "is_starred", $starred );
    }

    /**
     * Move entries to a status. Trashing stamps trashed_at so the cron can age them
     * out; restoring clears it.
     *
     * @param array<int,int> $ids
     */
    public static function set_status( array $ids, string $status ): int {
        global $wpdb;

        $ids = array_values( array_filter( array_map( "absint", $ids ) ) );
        if ( ! $ids ) {
            return 0;
        }

        if ( ! in_array( $status, self::statuses(), true ) ) {
            return 0;
        }

        $table = self::table();
        $holds = implode( ",", array_fill( 0, count( $ids ), "%d" ) );

        if ( "trash" === $status ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql    = "UPDATE {$table} SET status = %s, trashed_at = %s WHERE id IN ({$holds})";
            $head   = [ $status, Dates::now() ];
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql    = "UPDATE {$table} SET status = %s, trashed_at = NULL WHERE id IN ({$holds})";
            $head   = [ $status ];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $n = (int) $wpdb->query( $wpdb->prepare( $sql, ...array_merge( $head, $ids ) ) );

        foreach ( $ids as $id ) {
            do_action( "trinity_forms_entry_status_changed", $id, $status );
        }
        return $n;
    }

    /**
     * Permanently delete entries and everything hanging off them.
     *
     * The entry_meta FK is ON DELETE CASCADE where the host allowed it, but some
     * shared hosts deny the REFERENCES privilege (see Activator), so the child rows
     * are deleted explicitly rather than assumed.
     *
     * @param array<int,int> $ids
     */
    public static function delete( array $ids ): int {
        global $wpdb;

        $ids = array_values( array_filter( array_map( "absint", $ids ) ) );
        if ( ! $ids ) {
            return 0;
        }

        $prefix  = $wpdb->prefix . "trinity_forms_";
        $holds   = implode( ",", array_fill( 0, count( $ids ), "%d" ) );
        $deleted = 0;

        foreach ( $ids as $id ) {
            // Lets Pro clean up uploaded files and any external record before the
            // rows vanish — after the delete there is nothing left to look up.
            do_action( "trinity_forms_before_entry_delete", $id );
        }

        // NOT the files table: Submission_Pipeline::cleanup_entry_files() listens on
        // trinity_forms_entry_deleted and needs those rows to know which files to
        // unlink from disk — it deletes them itself afterwards. Clearing them here
        // would silently orphan every upload.
        foreach ( [ "entry_meta", "entry_notes" ] as $child ) {
            $child_table = $prefix . $child;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$child_table} WHERE entry_id IN ({$holds})", ...$ids ) );
        }

        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$holds})", ...$ids ) );

        foreach ( $ids as $id ) {
            do_action( "trinity_forms_entry_deleted", $id );
        }
        return $deleted;
    }

    /**
     * Delete trashed entries older than $days. Called from the daily cron alongside
     * the per-form retention sweep.
     */
    public static function purge_trash( int $days ): int {
        global $wpdb;

        if ( $days < 1 ) {
            return 0;
        }

        $table  = self::table();
        // trashed_at is UTC, so the cutoff is too.
        $cutoff = Dates::cutoff( $days * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = "SELECT id FROM {$table} WHERE status = 'trash' AND trashed_at IS NOT NULL AND trashed_at < %s LIMIT 500";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col( $wpdb->prepare( $sql, $cutoff ) );

        return $ids ? self::delete( array_map( "absint", $ids ) ) : 0;
    }

    /**
     * Neutralize a cell that a spreadsheet would otherwise execute (§15.2).
     *
     * A value opening with = + - @ is a formula to Excel/Sheets, which is how a
     * submitted "=cmd|..." becomes code execution on whoever opens the export.
     */
    public static function csv_safe( string $v ): string {
        return "" !== $v && in_array( $v[0], [ "=", "+", "-", "@" ], true ) ? "'" . $v : $v;
    }

    /** UTF-8 byte order mark that starts every CSV the plugin writes. */
    public const CSV_BOM = "\xEF\xBB\xBF";

    /**
     * Build a CSV for a set of entry ids.
     *
     * Lives here rather than in the REST controller because two callers need it —
     * the export endpoint and the entries screen's "export selected" bulk action —
     * and the second one arrived after the first had already grown an N+1 query.
     *
     * @param array<int,int> $ids
     */
    public static function to_csv( array $ids ): string {
        global $wpdb;

        $ids        = array_values( array_filter( array_map( "absint", $ids ) ) );
        $meta_table = self::meta_table();
        $field_ids  = [];
        $col_order  = [];
        $col_forms  = [];

        // Ordered by first appearance (MIN(id)) — the order the pipeline wrote the
        // fields, i.e. the order they appear on the form. A bare SELECT DISTINCT
        // leaves column order to the storage engine, so the same export could come
        // out with its columns shuffled between runs.
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            $holds = implode( ",", array_fill( 0, count( $chunk ), "%d" ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $cols = $wpdb->get_results( $wpdb->prepare(
                "SELECT m.field_id, MIN(m.field_label) AS field_label, MIN(m.id) AS first_seen, GROUP_CONCAT(DISTINCT e.form_id) AS form_ids
                   FROM {$meta_table} m
                   JOIN " . self::table() . " e ON e.id = m.entry_id
                  WHERE m.entry_id IN ({$holds})
               GROUP BY m.field_id
               ORDER BY first_seen ASC",
                ...$chunk
            ), ARRAY_A );
            foreach ( (array) $cols as $c ) {
                $fid = $c["field_id"];
                if ( self::is_internal_field( (string) $fid ) ) {
                    continue;
                }
                foreach ( explode( ",", (string) ( $c["form_ids"] ?? "" ) ) as $tf_form_of_col ) {
                    if ( (int) $tf_form_of_col > 0 ) {
                        $col_forms[ $fid ][ (int) $tf_form_of_col ] = true;
                    }
                }
                if ( ! isset( $field_ids[ $fid ] ) ) {
                    $field_ids[ $fid ] = $c["field_label"];
                    $col_order[ $fid ] = (int) $c["first_seen"];
                } else {
                    $col_order[ $fid ] = min( $col_order[ $fid ], (int) $c["first_seen"] );
                }
            }
        }
        asort( $col_order );
        $ordered_labels = [];
        foreach ( array_keys( $col_order ) as $fid ) {
            $ordered_labels[ $fid ] = $field_ids[ $fid ];
        }
        $ordered_labels = self::unique_headers( $ordered_labels, $col_forms );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream used to assemble the CSV; not a filesystem write (WP_Filesystem has no streaming API).
        $fp = fopen( "php://temp", "w+" );
        fputcsv(
            $fp,
            array_merge( [ "id", "submitted_at", "status" ], array_map( [ self::class, "csv_safe" ], array_values( $ordered_labels ) ) ),
            ",",
            '"',
            ""
        );

        $entries_table = self::table();
        $labels        = [];
        foreach ( array_chunk( $ids, 200 ) as $chunk ) {
            $holds = implode( ",", array_fill( 0, count( $chunk ), "%d" ) );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $entry_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, submitted_at, status FROM {$entries_table} WHERE id IN ({$holds}) ORDER BY submitted_at DESC, id DESC",
                ...$chunk
            ), ARRAY_A );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $meta_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT e.form_id, m.entry_id, m.field_id, m.field_type, m.value FROM {$meta_table} m JOIN {$entries_table} e ON e.id = m.entry_id WHERE m.entry_id IN ({$holds})",
                ...$chunk
            ), ARRAY_A );

            // Export what the visitor chose ("Gold", "Yes"), not what is stored
            // ("gold", "1", ["a"]) — the same reading the entry screen gives.
            $by_entry = [];
            foreach ( (array) $meta_rows as $m ) {
                $form_id = (int) $m["form_id"];
                if ( ! isset( $labels[ $form_id ] ) ) {
                    $labels[ $form_id ] = Form_Manager::choice_labels( $form_id );
                }
                $by_entry[ (int) $m["entry_id"] ][ $m["field_id"] ] = self::display_value(
                    (string) $m["field_type"],
                    $m["value"],
                    $labels[ $form_id ][ $m["field_id"] ] ?? []
                );
            }

            foreach ( (array) $entry_rows as $r ) {
                $values = $by_entry[ (int) $r["id"] ] ?? [];
                // Site time, as before: a spreadsheet built on the export keeps working.
                $row    = [ $r["id"], Dates::to_site( $r["submitted_at"] ), $r["status"] ];
                foreach ( array_keys( $ordered_labels ) as $fid ) {
                    $row[] = self::csv_safe( (string) ( $values[ $fid ] ?? "" ) );
                }
                fputcsv( $fp, $row, ",", '"', "" );
            }
        }

        rewind( $fp );
        // UTF-8 byte order mark first: without it Excel on Windows reads the file
        // as the local code page and shows Korean, Arabic or emoji as mojibake.
        $csv = self::CSV_BOM . (string) stream_get_contents( $fp );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the in-memory php://temp stream opened above.
        fclose( $fp );

        return $csv;
    }

    /**
     * Stream a CSV to the browser as a download and stop.
     *
     * Emitted directly rather than returned through the REST server, whose JSON
     * serializer ignores the text/csv header and would wrap the whole file in
     * quotes — turning it into an unopenable single-cell "CSV".
     */
    public static function send_csv( string $csv, string $filename = "trinity-forms-submissions.csv" ): void {
        if ( ! headers_sent() ) {
            nocache_headers();
            header( "Content-Type: text/csv; charset=UTF-8" );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( "Content-Length: " . strlen( $csv ) );
            header( "X-Content-Type-Options: nosniff" );
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw text/csv download body; cells are neutralized by csv_safe() and HTML-escaping would corrupt the file.
        echo $csv;
        exit;
    }

    /**
     * Field types an admin may not hand-edit.
     *
     * Payment values are the gateway's record of what was actually charged, and a
     * signature is someone's mark — letting either be retyped in a text box would
     * make the entry a worse record than before it was edited. Filterable so
     * premium field types can opt out too.
     *
     * @return array<int,string>
     */
    /**
     * One stored answer as a person should read it.
     *
     * Every reader of entry meta (entry screen, print, CSV, GDPR export, {all_fields},
     * PDF, Views) used to decode it separately, and each got a different subset
     * right: the CSV showed "goldgold" and ["a"], consent read "1", and a file upload
     * printed its JSON including the server's absolute path. One formatter now.
     *
     * @param string               $type  The stored field_type.
     * @param mixed                $raw   The stored value (string, possibly JSON).
     * @param array<string,string> $map   Choice value => label for this field.
     * @param bool                 $html  HTML output (links, formatted rich text) or plain text.
     */
    /** A repeater answer: a non-empty list whose items are themselves arrays. */
    private static function is_row_list( array $value ): bool {
        if ( ! $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
            return false;
        }
        foreach ( $value as $row ) {
            if ( ! is_array( $row ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Make CSV header labels unique. An export across several forms can hold two
     * different fields that are both called "Message"; a spreadsheet cannot tell
     * those columns apart, so a repeated label gets its form's title after it (and
     * the field id when even that is not enough).
     *
     * @param array<string,string>           $labels    field_id => label, in column order.
     * @param array<string,array<int,bool>>  $col_forms field_id => form ids it appears in.
     * @return array<string,string>
     */
    public static function unique_headers( array $labels, array $col_forms = [] ): array {
        $seen = array_count_values( array_map( "strval", $labels ) );
        $out  = [];
        foreach ( $labels as $fid => $label ) {
            $label = (string) $label;
            if ( ( $seen[ $label ] ?? 0 ) > 1 ) {
                $titles = [];
                foreach ( array_keys( $col_forms[ $fid ] ?? [] ) as $form_id ) {
                    $title = get_the_title( (int) $form_id );
                    $titles[] = "" !== $title ? $title : "#" . (int) $form_id;
                }
                if ( $titles ) {
                    $label .= " (" . implode( ", ", $titles ) . ")";
                }
            }
            $out[ $fid ] = $label;
        }
        // Still clashing (same label twice inside one form): fall back to the id.
        $again = array_count_values( $out );
        foreach ( $out as $fid => $label ) {
            if ( $again[ $label ] > 1 ) {
                $out[ $fid ] = $label . " [" . $fid . "]";
            }
        }
        return $out;
    }

    public static function display_value( string $type, $raw, array $map = [], bool $html = false ): string {
        $value = $raw;
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                $value = $decoded;
            }
        }

        if ( "consent" === $type ) {
            $yes = is_array( $value ) ? ! empty( array_filter( $value ) ) : ( "" !== (string) $value && "0" !== (string) $value );
            return $yes ? __( "Yes", "trinity-forms" ) : __( "No", "trinity-forms" );
        }

        if ( "file" === $type ) {
            $items = is_array( $value ) && isset( $value["filename"] ) ? [ $value ] : ( is_array( $value ) ? $value : [] );
            $out   = [];
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) || empty( $item["filename"] ) ) {
                    continue;
                }
                $name = (string) $item["filename"];
                $url  = (string) ( $item["url"] ?? "" );
                // Never the stored "path": that is the server's filesystem, not an answer.
                $out[] = $html && "" !== $url
                    ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $name ) . "</a>"
                    : ( $html ? esc_html( $name ) : $name );
            }
            return implode( $html ? "<br>" : ", ", $out );
        }

        if ( "rich_text" === $type ) {
            $text = is_scalar( $value ) ? (string) $value : "";
            return $html ? wp_kses_post( $text ) : trim( wp_strip_all_tags( $text ) );
        }

        if ( is_array( $value ) && self::is_row_list( $value ) ) {
            $rows = [];
            foreach ( $value as $row ) {
                $cells = [];
                foreach ( (array) $row as $sub => $cell ) {
                    $cell = is_array( $cell ) ? implode( ", ", array_filter( array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : "", $cell ) ) ) : ( is_scalar( $cell ) ? (string) $cell : "" );
                    if ( "" === trim( $cell ) ) {
                        continue;
                    }
                    $cells[] = count( (array) $row ) > 1 ? ucfirst( str_replace( [ "_", "-" ], " ", (string) $sub ) ) . ": " . $cell : $cell;
                }
                if ( $cells ) {
                    $rows[] = implode( ", ", $cells );
                }
            }
            return $html ? implode( "<br>", array_map( "esc_html", $rows ) ) : implode( "; ", $rows );
        }

        if ( is_array( $value ) ) {
            $parts = array_map(
                static fn( $v ) => is_scalar( $v ) ? (string) ( $map[ (string) $v ] ?? $v ) : "",
                $value
            );
            $text = implode( array_is_list( $value ) ? ", " : " ", array_filter( $parts, static fn( $p ) => "" !== $p ) );
        } else {
            $text = (string) ( $map[ (string) $value ] ?? $value );
        }
        return $html ? nl2br( esc_html( $text ) ) : $text;
    }

    /**
     * Whether a stored value and an edited one are the same answer.
     *
     * Storage is all strings ("4"), sanitizers return ints (4), and a field left
     * blank may be missing from storage (null) or posted as "" or 0 (consent). A
     * strict comparison named every one of those as "changed" in the audit note.
     */
    public static function same_value( $old, $new ): bool {
        $norm = static function ( $v ) use ( &$norm ) {
            if ( is_array( $v ) ) {
                $v = array_map( $norm, $v );
                $v = array_filter( $v, static fn( $x ) => "" !== $x && [] !== $x );
                return $v;
            }
            if ( null === $v || false === $v ) {
                return "";
            }
            if ( true === $v ) {
                return "1";
            }
            $s = trim( (string) $v );
            return "0" === $s ? "" : $s;
        };
        return $norm( $old ) == $norm( $new ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- both sides normalised to strings/arrays of strings; loose == compares arrays regardless of key order.
    }

    /**
     * True for rows the plugin writes for itself (quiz score, created-post marker…),
     * which are not answers and must not be listed as if they were form fields.
     */
    public static function is_internal_field( string $field_id ): bool {
        return "" !== $field_id && "_" === $field_id[0];
    }

    public static function uneditable_types(): array {
        return (array) apply_filters(
            "trinity_forms_uneditable_field_types",
            [ "payment", "signature", "file", "calculation", "html", "section", "page_break" ]
        );
    }

    /**
     * The stored value of every field on an entry, decoded the way the field wrote it.
     *
     * @return array<string,mixed>
     */
    public static function values( int $entry_id ): array {
        global $wpdb;

        $meta = self::meta_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_id, value FROM {$meta} WHERE entry_id = %d ORDER BY id ASC",
            absint( $entry_id )
        ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $raw = (string) $r["value"];
            // The pipeline JSON-encodes arrays and stores everything else as a
            // string, so decode symmetrically rather than guessing per type.
            $decoded = json_decode( $raw, true );
            $out[ $r["field_id"] ] = is_array( $decoded ) ? $decoded : $raw;
        }
        return $out;
    }

    /**
     * Write edited values back onto an entry.
     *
     * Mirrors Submission_Pipeline::store()'s meta handling — same JSON encoding,
     * same trinity_forms_entry_meta_pre_save filter — so an edited row is
     * indistinguishable from a submitted one. Fields absent from the form schema
     * are left untouched: they are usually meta from an older version of the form,
     * and silently dropping them would lose data on an unrelated edit.
     *
     * @param array<string,mixed> $sanitized field_id => already-sanitized value
     * @return int Number of fields written.
     */
    public static function update_values( int $entry_id, Form $form, array $sanitized ): int {
        global $wpdb;

        $meta_table = self::meta_table();
        $written    = 0;

        foreach ( $form->fields() as $field ) {
            if ( ! array_key_exists( $field->id, $sanitized ) ) {
                continue;
            }
            $value  = $sanitized[ $field->id ];
            $stored = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;

            $row = apply_filters( "trinity_forms_entry_meta_pre_save", [
                "entry_id"    => $entry_id,
                "field_id"    => $field->id,
                "field_label" => $field->label,
                "field_type"  => $field->type,
                "value"       => $stored,
            ], $field, $sanitized );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$meta_table} WHERE entry_id = %d AND field_id = %s LIMIT 1",
                $entry_id,
                $field->id
            ) );

            if ( $exists ) {
                $wpdb->update( $meta_table, $row, [ "id" => $exists ], [ "%d", "%s", "%s", "%s", "%s" ], [ "%d" ] );
            } else {
                // A field added to the form after this entry was submitted has no
                // row yet; an edit is a legitimate way to fill it in.
                $wpdb->insert( $meta_table, $row, [ "%d", "%s", "%s", "%s", "%s" ] );
            }
            $written++;
        }

        do_action( "trinity_forms_entry_updated", $entry_id, $sanitized );
        return $written;
    }

    /**
     * Field types that can never be a useful list column.
     *
     * Layout and display-only fields hold no answer, and long-form or binary values
     * would blow the row height apart.
     *
     * @return array<int,string>
     */
    private static function non_column_types(): array {
        return [ "html", "section", "page_break", "signature", "file", "repeater" ];
    }

    /**
     * The fields of a form that may be shown as entry-list columns.
     *
     * @return array<string,string> field_id => label
     */
    public static function field_columns( int $form_id ): array {
        $form = Form_Manager::get( $form_id );
        if ( ! $form ) {
            return [];
        }

        $skip = self::non_column_types();
        $out  = [];
        foreach ( $form->fields() as $field ) {
            if ( in_array( $field->type, $skip, true ) ) {
                continue;
            }
            $out[ $field->id ] = $field->label ?: $field->id;
        }
        // Two fields may share a label (a Textarea and a Rich Text both "Message");
        // a column picker listing "Message" twice gives no way to choose between them.
        $counts = array_count_values( $out );
        foreach ( $out as $id => $label ) {
            if ( $counts[ $label ] > 1 && $label !== $id ) {
                $out[ $id ] = $label . " (" . $id . ")";
            }
        }
        return $out;
    }

    /**
     * The column selection for a form, per user.
     *
     * Stored per user rather than per site: which answers matter at a glance is a
     * job-shaped preference, and two people working the same form rarely want the
     * same three columns.
     *
     * @return array<int,string> field ids, empty meaning "none chosen"
     */
    public static function user_columns( int $form_id ): array {
        if ( $form_id < 1 ) {
            return [];
        }
        $stored = get_user_meta( get_current_user_id(), "trinity_forms_entry_columns_" . $form_id, true );
        if ( ! is_array( $stored ) ) {
            return [];
        }
        // A field removed from the form must not keep haunting the table.
        return array_values( array_intersect( $stored, array_keys( self::field_columns( $form_id ) ) ) );
    }

    /** @param array<int,string> $field_ids */
    public static function save_user_columns( int $form_id, array $field_ids ): void {
        if ( $form_id < 1 ) {
            return;
        }
        $valid = array_values( array_intersect( $field_ids, array_keys( self::field_columns( $form_id ) ) ) );
        update_user_meta( get_current_user_id(), "trinity_forms_entry_columns_" . $form_id, $valid );
    }

    /**
     * Values for a set of fields across a page of entries — one query, not one per cell.
     *
     * @param array<int,int>    $entry_ids
     * @param array<int,string> $field_ids
     * @return array<int,array<string,string>> entry_id => field_id => display value
     */
    public static function values_for( array $entry_ids, array $field_ids ): array {
        global $wpdb;

        $entry_ids = array_values( array_filter( array_map( "absint", $entry_ids ) ) );
        $field_ids = array_values( array_filter( $field_ids ) );
        if ( ! $entry_ids || ! $field_ids ) {
            return [];
        }

        $meta         = self::meta_table();
        $entry_holds  = implode( ",", array_fill( 0, count( $entry_ids ), "%d" ) );
        $field_holds  = implode( ",", array_fill( 0, count( $field_ids ), "%s" ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT entry_id, field_id, field_type, value FROM {$meta}
              WHERE entry_id IN ({$entry_holds}) AND field_id IN ({$field_holds})",
            ...array_merge( $entry_ids, $field_ids )
        ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $raw = (string) $r["value"];
            // Types whose stored shape is not an answer: a file's JSON (with its
            // server path), consent's "1", Rich Text markup printed as literal tags.
            if ( in_array( (string) $r["field_type"], [ "file", "consent", "rich_text" ], true ) ) {
                $out[ (int) $r["entry_id"] ][ $r["field_id"] ] = self::display_value( (string) $r["field_type"], $raw );
                continue;
            }
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                // Composite ({first,last}) and multi-choice (list) values both land
                // here; joining is the only sane single-cell rendering of either.
                $raw = implode( array_is_list( $decoded ) ? ", " : " ", array_filter( array_map(
                    static fn( $v ) => is_scalar( $v ) ? (string) $v : "",
                    $decoded
                ) ) );
            }
            $out[ (int) $r["entry_id"] ][ $r["field_id"] ] = $raw;
        }
        return $out;
    }

    /**
     * "Submitted by" for a page of rows — one query for the page, not one per row.
     *
     * @param array<int,int> $entry_ids
     * @return array<int,array{name?:string,email?:string}>
     */
    public static function senders( array $entry_ids ): array {
        global $wpdb;

        $entry_ids = array_values( array_filter( array_map( "absint", $entry_ids ) ) );
        if ( ! $entry_ids ) {
            return [];
        }

        $meta  = self::meta_table();
        $holds = implode( ",", array_fill( 0, count( $entry_ids ), "%d" ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT entry_id, field_type, value FROM {$meta} WHERE entry_id IN ({$holds}) AND field_type IN ('email','name')",
            ...$entry_ids
        ) );

        $out = [];
        foreach ( (array) $rows as $m ) {
            $eid     = (int) $m->entry_id;
            $value   = (string) $m->value;
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                // The name field stores {first,last}.
                $value = trim( implode( " ", array_filter( [ $decoded["first"] ?? "", $decoded["last"] ?? "" ] ) ) );
            }
            if ( "" === $value ) {
                continue;
            }
            if ( "email" === $m->field_type && empty( $out[ $eid ]["email"] ) ) {
                $out[ $eid ]["email"] = $value;
            } elseif ( "name" === $m->field_type && empty( $out[ $eid ]["name"] ) ) {
                $out[ $eid ]["name"] = $value;
            }
        }
        return $out;
    }
}
