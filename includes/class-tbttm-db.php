<?php
/**
 * Tables, schema upgrades and every query the plugin runs.
 *
 * Three tables:
 *   {prefix}tbttm_tests     — one row per test: owner, slug, title, settings.
 *   {prefix}tbttm_questions — one row per question, with the options and the
 *                             answer key as JSON in `data`.
 *   {prefix}tbttm_attempts  — one row per sitting: the student, their answers
 *                             as they give them, and at finish the score and
 *                             a snapshot of what they saw.
 *
 * Column names are a data contract: add columns, never rename them. Several
 * columns (certificate, email_result, guest_email) belong to later releases
 * and are created now so those releases need no schema change.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_DB {

	const STATUS_DRAFT     = 'draft';
	const STATUS_PUBLISHED = 'published';

	const ATTEMPT_IN_PROGRESS = 'in_progress';
	const ATTEMPT_FINISHED    = 'finished';

	public static function tests_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbttm_tests';
	}

	public static function questions_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbttm_questions';
	}

	public static function attempts_table() {
		global $wpdb;
		return $wpdb->prefix . 'tbttm_attempts';
	}

	/**
	 * Create or upgrade the three tables.
	 */
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$tests     = self::tests_table();
		$questions = self::questions_table();
		$attempts  = self::attempts_table();

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$tests} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(32) NOT NULL,
				owner_id bigint(20) unsigned NOT NULL,
				title varchar(200) NOT NULL DEFAULT '',
				intro text NOT NULL,
				image_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'draft',
				feedback varchar(10) NOT NULL DEFAULT 'end',
				bulls tinyint(1) NOT NULL DEFAULT 1,
				pass_mark tinyint(3) unsigned NULL DEFAULT NULL,
				access varchar(10) NOT NULL DEFAULT 'login',
				class_id bigint(20) unsigned NULL DEFAULT NULL,
				lesson_id bigint(20) unsigned NULL DEFAULT NULL,
				certificate tinyint(1) NOT NULL DEFAULT 0,
				email_result tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				published_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY owner_id (owner_id),
				KEY class_id (class_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$questions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				test_id bigint(20) unsigned NOT NULL,
				position int(11) NOT NULL DEFAULT 0,
				type varchar(20) NOT NULL DEFAULT 'choice',
				body text NOT NULL,
				data longtext NOT NULL,
				PRIMARY KEY  (id),
				KEY test_id (test_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$attempts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				test_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				guest_name varchar(120) NOT NULL DEFAULT '',
				guest_email varchar(190) NOT NULL DEFAULT '',
				token char(32) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'in_progress',
				answers longtext NOT NULL,
				checked longtext NOT NULL,
				review longtext NULL,
				score smallint(5) unsigned NULL DEFAULT NULL,
				total smallint(5) unsigned NULL DEFAULT NULL,
				percent tinyint(3) unsigned NULL DEFAULT NULL,
				started_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				finished_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				KEY test_id (test_id),
				KEY user_id (user_id)
			) {$charset};"
		);

		update_option( 'tbttm_db_version', TBTTM_DB_VERSION );
	}

	/**
	 * Run activate() when the stored schema version is behind. Activation
	 * hooks do not fire on an update of an already active plugin.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'tbttm_db_version' ) !== TBTTM_DB_VERSION ) {
			self::activate();
		}
	}

	/** The current time, UTC, in MySQL format. */
	public static function now() {
		return current_time( 'mysql', true );
	}

	/* ---------------------------------------------------------------------
	 * Tests
	 * ------------------------------------------------------------------- */

	/**
	 * One test by ID.
	 *
	 * @param int $id Test ID.
	 * @return object|null
	 */
	public static function get_test( $id ) {
		global $wpdb;
		$table = self::tests_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * One test by ID, only if this user owns it. The teacher page never reads
	 * a test any other way: the capability alone does not open someone else's,
	 * administrators included.
	 *
	 * @param int $id      Test ID.
	 * @param int $user_id Owner.
	 * @return object|null
	 */
	public static function get_owned_test( $id, $user_id ) {
		global $wpdb;
		$table = self::tests_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND owner_id = %d", (int) $id, (int) $user_id )
		);
	}

	/**
	 * One test by slug.
	 *
	 * @param string $slug Slug.
	 * @return object|null
	 */
	public static function get_test_by_slug( $slug ) {
		global $wpdb;
		$table = self::tests_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", (string) $slug ) );
	}

	/**
	 * Every test a user owns, newest first, with its question count and its
	 * finished-attempt count. Three queries for the whole library, never one
	 * per row.
	 *
	 * @param int $owner_id Owner.
	 * @return object[] Rows with extra `question_count` and `attempt_count`.
	 */
	public static function get_owned_tests( $owner_id ) {
		global $wpdb;
		$tests     = self::tests_table();
		$questions = self::questions_table();
		$attempts  = self::attempts_table();

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tests} WHERE owner_id = %d ORDER BY created_at DESC, id DESC", (int) $owner_id )
		);
		if ( empty( $rows ) ) {
			return array();
		}

		$qcounts = array();
		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.test_id, COUNT(*) AS n FROM {$questions} q INNER JOIN {$tests} t ON t.id = q.test_id
				WHERE t.owner_id = %d GROUP BY q.test_id",
				(int) $owner_id
			)
		) as $r ) {
			$qcounts[ (int) $r->test_id ] = (int) $r->n;
		}

		$acounts = array();
		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.test_id, COUNT(*) AS n FROM {$attempts} a INNER JOIN {$tests} t ON t.id = a.test_id
				WHERE t.owner_id = %d AND a.status = %s GROUP BY a.test_id",
				(int) $owner_id,
				self::ATTEMPT_FINISHED
			)
		) as $r ) {
			$acounts[ (int) $r->test_id ] = (int) $r->n;
		}

		foreach ( $rows as $row ) {
			$row->question_count = $qcounts[ (int) $row->id ] ?? 0;
			$row->attempt_count  = $acounts[ (int) $row->id ] ?? 0;
		}
		return $rows;
	}

	/**
	 * The question text of every test a user owns, grouped by test, for the
	 * library search. One query for the whole library.
	 *
	 * @param int $owner_id Owner.
	 * @return array<int, string[]> Test ID → question bodies.
	 */
	public static function get_owned_question_bodies( $owner_id ) {
		global $wpdb;
		$tests     = self::tests_table();
		$questions = self::questions_table();

		$out = array();
		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.test_id, q.body FROM {$questions} q INNER JOIN {$tests} t ON t.id = q.test_id WHERE t.owner_id = %d",
				(int) $owner_id
			)
		) as $r ) {
			$out[ (int) $r->test_id ][] = (string) $r->body;
		}
		return $out;
	}

	/**
	 * Finished attempts for one test.
	 *
	 * @param int $test_id Test ID.
	 * @return int
	 */
	public static function count_finished_attempts( $test_id ) {
		global $wpdb;
		$table = self::attempts_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE test_id = %d AND status = %s", (int) $test_id, self::ATTEMPT_FINISHED )
		);
	}

	/**
	 * A class's published tests, newest first, with their question counts.
	 * For TBT Notes: scoped by class, not by owner, because the readers are
	 * the class's students.
	 *
	 * @param int $class_id Notes class ID.
	 * @return object[] Rows with id, slug, title, lesson_id, question_count.
	 */
	public static function published_tests_for_class( $class_id ) {
		global $wpdb;
		$tests     = self::tests_table();
		$questions = self::questions_table();
		$class_id  = (int) $class_id;
		if ( $class_id <= 0 ) {
			return array();
		}
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.slug, t.title, t.lesson_id,
					( SELECT COUNT(*) FROM {$questions} q WHERE q.test_id = t.id ) AS question_count
				FROM {$tests} t
				WHERE t.class_id = %d AND t.status = %s
				ORDER BY t.published_at DESC, t.id DESC",
				$class_id,
				self::STATUS_PUBLISHED
			)
		);
	}

	/**
	 * Insert a test with its questions.
	 *
	 * @param array  $clean    Output of TBTTM_Validator::sanitise() or validate().
	 * @param string $status   draft or published.
	 * @param int    $owner_id Owner.
	 * @return int New test ID, or 0 on failure.
	 */
	public static function insert_test( array $clean, $status, $owner_id ) {
		global $wpdb;
		$now  = self::now();
		$data = self::test_columns( $clean, $status );

		$data['slug']       = self::unique_slug();
		$data['owner_id']   = (int) $owner_id;
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		if ( self::STATUS_PUBLISHED === $status ) {
			$data['published_at'] = $now;
		}

		if ( false === $wpdb->insert( self::tests_table(), $data ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		self::save_questions( $id, $clean['questions'] );
		return $id;
	}

	/**
	 * Save a whole test. The questions arrive as an ordered list: one with an
	 * ID belonging to this test is updated in place, one without is inserted,
	 * and stored questions missing from the list are deleted. IDs stay stable,
	 * so an attempt in progress keeps pointing at the right question.
	 *
	 * @param object $row    Current test row.
	 * @param array  $clean  Output of TBTTM_Validator::sanitise() or validate().
	 * @param string $status draft or published.
	 * @return bool
	 */
	public static function update_test( $row, array $clean, $status ) {
		global $wpdb;
		$data               = self::test_columns( $clean, $status );
		$data['updated_at'] = self::now();
		if ( self::STATUS_PUBLISHED === $status && empty( $row->published_at ) ) {
			$data['published_at'] = $data['updated_at'];
		}

		if ( false === $wpdb->update( self::tests_table(), $data, array( 'id' => (int) $row->id ) ) ) {
			return false;
		}
		self::save_questions( (int) $row->id, $clean['questions'] );
		return true;
	}

	/**
	 * The test-table columns a save writes.
	 *
	 * @param array  $clean  Clean test.
	 * @param string $status Status.
	 * @return array
	 */
	private static function test_columns( array $clean, $status ) {
		return array(
			'title'     => (string) $clean['title'],
			'intro'     => (string) $clean['intro'],
			'image_id'  => (int) $clean['image_id'],
			'status'    => self::STATUS_PUBLISHED === $status ? self::STATUS_PUBLISHED : self::STATUS_DRAFT,
			'feedback'  => 'now' === $clean['feedback'] ? 'now' : 'end',
			'bulls'     => $clean['bulls'] ? 1 : 0,
			'pass_mark' => null === $clean['pass_mark'] ? null : (int) $clean['pass_mark'],
			'access'    => isset( $clean['access'] ) && 'open' === $clean['access'] ? 'open' : 'login',
			'class_id'  => empty( $clean['class_id'] ) ? null : (int) $clean['class_id'],
			'lesson_id' => empty( $clean['class_id'] ) || empty( $clean['lesson_id'] ) ? null : (int) $clean['lesson_id'],
		);
	}

	/**
	 * Reconcile a test's questions with an ordered list.
	 *
	 * @param int   $test_id   Test ID.
	 * @param array $questions Clean questions, in order.
	 */
	private static function save_questions( $test_id, array $questions ) {
		global $wpdb;
		$table = self::questions_table();

		$stored = array_map(
			'intval',
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE test_id = %d", (int) $test_id ) )
		);
		$stored = array_flip( $stored );
		$kept   = array();

		foreach ( array_values( $questions ) as $position => $q ) {
			$columns = array(
				'test_id'  => (int) $test_id,
				'position' => (int) $position,
				'type'     => 'choice',
				'body'     => (string) $q['body'],
				'data'     => wp_json_encode(
					array(
						'options' => array_values( array_map( 'strval', $q['options'] ) ),
						'correct' => null === $q['correct'] ? null : (int) $q['correct'],
					)
				),
			);

			$qid = isset( $q['id'] ) ? (int) $q['id'] : 0;
			// An ID that is not one of this test's questions is treated as new:
			// a save can never move or overwrite another test's question.
			if ( $qid > 0 && isset( $stored[ $qid ] ) && ! isset( $kept[ $qid ] ) ) {
				$wpdb->update( $table, $columns, array( 'id' => $qid ) );
				$kept[ $qid ] = true;
			} else {
				$wpdb->insert( $table, $columns );
				$kept[ (int) $wpdb->insert_id ] = true;
			}
		}

		foreach ( array_keys( $stored ) as $old_id ) {
			if ( ! isset( $kept[ $old_id ] ) ) {
				$wpdb->delete( $table, array( 'id' => (int) $old_id ), array( '%d' ) );
			}
		}
	}

	/**
	 * Copy a test and its questions as a new draft: "Title (copy)", a new
	 * slug, no attempts. The questions are copied as stored. The copy keeps
	 * the class and who can take it, but not the lesson.
	 *
	 * @param object $row      Test row to copy.
	 * @param int    $owner_id Owner of the copy.
	 * @return int New test ID, or 0 on failure.
	 */
	public static function duplicate_test( $row, $owner_id ) {
		global $wpdb;
		$now = self::now();
		/* translators: %s: the original test's title. */
		$title = sprintf( __( '%s (copy)', 'tbt-test-maker' ), (string) $row->title );
		if ( mb_strlen( $title, 'UTF-8' ) > TBTTM_Validator::TITLE_MAX ) {
			$title = mb_substr( $title, 0, TBTTM_Validator::TITLE_MAX, 'UTF-8' );
		}

		$ok = $wpdb->insert(
			self::tests_table(),
			array(
				'slug'       => self::unique_slug(),
				'owner_id'   => (int) $owner_id,
				'title'      => $title,
				'intro'      => (string) $row->intro,
				'image_id'   => (int) $row->image_id,
				'status'     => self::STATUS_DRAFT,
				'feedback'   => (string) $row->feedback,
				'bulls'      => (int) $row->bulls,
				'pass_mark'  => null === $row->pass_mark ? null : (int) $row->pass_mark,
				'access'     => 'open' === $row->access ? 'open' : 'login',
				'class_id'   => empty( $row->class_id ) ? null : (int) $row->class_id,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( false === $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		$table = self::questions_table();
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT position, type, body, data FROM {$table} WHERE test_id = %d ORDER BY position ASC, id ASC", (int) $row->id )
		);
		foreach ( $rows as $q ) {
			$wpdb->insert(
				$table,
				array(
					'test_id'  => $id,
					'position' => (int) $q->position,
					'type'     => (string) $q->type,
					'body'     => (string) $q->body,
					'data'     => (string) $q->data,
				)
			);
		}
		return $id;
	}

	/**
	 * Delete a test, its questions and its attempts.
	 *
	 * @param int $id Test ID.
	 */
	public static function delete_test( $id ) {
		global $wpdb;
		$wpdb->delete( self::attempts_table(), array( 'test_id' => (int) $id ), array( '%d' ) );
		$wpdb->delete( self::questions_table(), array( 'test_id' => (int) $id ), array( '%d' ) );
		$wpdb->delete( self::tests_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * A random slug no other test has. Lowercase letters and digits only, so
	 * it survives sanitize_key() on the way back in.
	 *
	 * @return string
	 */
	private static function unique_slug() {
		do {
			$slug = strtolower( wp_generate_password( 10, false, false ) );
		} while ( self::get_test_by_slug( $slug ) );
		return $slug;
	}

	/* ---------------------------------------------------------------------
	 * Questions
	 * ------------------------------------------------------------------- */

	/**
	 * A test's questions in order, decoded.
	 *
	 * @param int $test_id Test ID.
	 * @return array[] Each: id, body, options, correct (int|null).
	 */
	public static function get_questions( $test_id ) {
		global $wpdb;
		$table = self::questions_table();
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE test_id = %d ORDER BY position ASC, id ASC", (int) $test_id )
		);

		$out = array();
		foreach ( $rows as $row ) {
			$data    = json_decode( (string) $row->data, true );
			$options = isset( $data['options'] ) && is_array( $data['options'] ) ? array_values( array_map( 'strval', $data['options'] ) ) : array();
			$correct = isset( $data['correct'] ) && is_numeric( $data['correct'] ) ? (int) $data['correct'] : null;
			$out[]   = array(
				'id'      => (int) $row->id,
				'body'    => (string) $row->body,
				'options' => $options,
				'correct' => $correct,
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Attempts
	 * ------------------------------------------------------------------- */

	/**
	 * One attempt by ID.
	 *
	 * @param int $id Attempt ID.
	 * @return object|null
	 */
	public static function get_attempt( $id ) {
		global $wpdb;
		$table = self::attempts_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * One attempt by its token.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	public static function get_attempt_by_token( $token ) {
		global $wpdb;
		$table = self::attempts_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", (string) $token ) );
	}

	/**
	 * A test's finished attempts, newest first, for the Results screen: who,
	 * when and the score. The snapshot and the token are left out; the list
	 * needs neither.
	 *
	 * @param int $test_id Test ID.
	 * @param int $limit   Most rows to return.
	 * @return object[]
	 */
	public static function get_finished_attempts( $test_id, $limit ) {
		global $wpdb;
		$table = self::attempts_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, guest_name, score, total, percent, started_at, finished_at
				FROM {$table} WHERE test_id = %d AND status = %s
				ORDER BY finished_at DESC, id DESC LIMIT %d",
				(int) $test_id,
				self::ATTEMPT_FINISHED,
				max( 1, (int) $limit )
			)
		);
	}

	/**
	 * Who scored what on every finished attempt of a test: the three columns
	 * the Results stats are counted from, with no limit.
	 *
	 * @param int $test_id Test ID.
	 * @return object[] Rows with user_id, guest_name, percent.
	 */
	public static function get_finished_scores( $test_id ) {
		global $wpdb;
		$table = self::attempts_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, guest_name, percent FROM {$table} WHERE test_id = %d AND status = %s",
				(int) $test_id,
				self::ATTEMPT_FINISHED
			)
		);
	}

	/**
	 * A batch of finished attempts' snapshots, in ID order, for counting the
	 * hardest questions without loading every snapshot at once.
	 *
	 * @param int $test_id  Test ID.
	 * @param int $after_id Return attempts with an ID above this.
	 * @param int $limit    Batch size.
	 * @return object[] Rows with id, review.
	 */
	public static function get_finished_reviews( $test_id, $after_id, $limit ) {
		global $wpdb;
		$table = self::attempts_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, review FROM {$table} WHERE test_id = %d AND status = %s AND id > %d ORDER BY id ASC LIMIT %d",
				(int) $test_id,
				self::ATTEMPT_FINISHED,
				(int) $after_id,
				max( 1, (int) $limit )
			)
		);
	}

	/**
	 * The user's newest attempt on a test with the given status.
	 *
	 * @param int    $test_id Test ID.
	 * @param int    $user_id User ID.
	 * @param string $status  Attempt status.
	 * @return object|null
	 */
	public static function get_user_attempt( $test_id, $user_id, $status ) {
		global $wpdb;
		$table = self::attempts_table();
		$order = self::ATTEMPT_FINISHED === $status ? 'finished_at DESC, id DESC' : 'id DESC';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE test_id = %d AND user_id = %d AND status = %s ORDER BY {$order} LIMIT 1",
				(int) $test_id,
				(int) $user_id,
				(string) $status
			)
		);
	}

	/**
	 * Start a new attempt.
	 *
	 * @param int    $test_id    Test ID.
	 * @param int    $user_id    User ID, or 0 for a guest.
	 * @param string $guest_name The guest's name; '' for a user.
	 * @return object|null The new row.
	 */
	public static function insert_attempt( $test_id, $user_id, $guest_name = '' ) {
		global $wpdb;
		$now = self::now();
		$ok  = $wpdb->insert(
			self::attempts_table(),
			array(
				'test_id'    => (int) $test_id,
				'user_id'    => (int) $user_id,
				'guest_name' => (int) $user_id > 0 ? '' : (string) $guest_name,
				'token'      => bin2hex( random_bytes( 16 ) ),
				'status'     => self::ATTEMPT_IN_PROGRESS,
				'answers'    => '{}',
				'checked'    => '[]',
				'started_at' => $now,
				'updated_at' => $now,
			)
		);
		return false === $ok ? null : self::get_attempt( (int) $wpdb->insert_id );
	}

	/**
	 * Write fields of an attempt. `updated_at` is always refreshed.
	 *
	 * @param int   $id     Attempt ID.
	 * @param array $fields Column → value.
	 * @return bool
	 */
	public static function update_attempt( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = self::now();
		return false !== $wpdb->update( self::attempts_table(), $fields, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete one attempt. Used by "Start again", which discards an attempt in
	 * progress at the student's request.
	 *
	 * @param int $id Attempt ID.
	 */
	public static function delete_attempt( $id ) {
		global $wpdb;
		$wpdb->delete( self::attempts_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}
}
