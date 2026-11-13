<?php
/**
 * The teacher's Results screen: who took a test, how they scored, and which
 * questions were hardest.
 *
 * Everything here reads finished attempts only, and reads what a student
 * answered from the attempt's stored snapshot (`review`), never from the live
 * questions. Results are for the test's owner; the REST callbacks check that
 * before any of this runs.
 *
 * The calculations (hardest(), last_line(), seconds()) use no WordPress
 * function, so tests/test-results.php can run them without WordPress.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Results {

	/** Attempts the list shows; the stats cover all of them. */
	const LIST_LIMIT = 500;

	/** Questions the "Hardest questions" card lists. */
	const HARDEST = 3;

	/** Fewer finished attempts than this and there is no "Hardest questions". */
	const MIN_ATTEMPTS = 3;

	/** Snapshots read per query while counting, so memory stays flat. */
	const BATCH = 200;

	/**
	 * The last non-empty line of a question's text: what the screen shows
	 * for a question. Plain text; the caller escapes it.
	 *
	 * @param string $body Question text.
	 * @return string
	 */
	public static function last_line( $body ) {
		$last = '';
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $body ) as $line ) {
			if ( '' !== trim( $line ) ) {
				$last = trim( $line );
			}
		}
		return $last;
	}

	/**
	 * How long an attempt took: finished − started, in seconds. Null when
	 * either time is missing or they are the wrong way round.
	 *
	 * @param string $started  MySQL datetime, UTC.
	 * @param string $finished MySQL datetime, UTC.
	 * @return int|null
	 */
	public static function seconds( $started, $finished ) {
		$from = strtotime( (string) $started . ' UTC' );
		$to   = strtotime( (string) $finished . ' UTC' );
		if ( false === $from || false === $to || $to < $from ) {
			return null;
		}
		return $to - $from;
	}

	/**
	 * The questions students got wrong most often.
	 *
	 * For each question in the test as it is now, take every finished attempt
	 * whose snapshot holds it: matched by `id`, or for a snapshot entry with
	 * no `id` (attempts finished before 0.3.0) by an identical `body`. The
	 * rate is the share of those attempts where the answer given is the
	 * correct one; no answer counts as wrong. Whether an answer was right is
	 * the snapshot's own verdict, so editing a question later does not
	 * re-mark old attempts.
	 *
	 * The lowest rates come first, ties in question order. A question no
	 * attempt holds is left out. With fewer than MIN_ATTEMPTS attempts there
	 * is nothing to say and the list is empty.
	 *
	 * @param array[]  $questions The test's questions now: id, body, options, correct.
	 * @param iterable $reviews   One snapshot (list of entries) per finished attempt.
	 * @return array[] Up to HARDEST of { number, line, percent, correct_text }.
	 */
	public static function hardest( array $questions, $reviews ) {
		$questions = array_values( $questions );
		$by_id     = array();
		$by_body   = array();
		foreach ( $questions as $i => $q ) {
			$by_id[ (int) $q['id'] ]             = $i;
			$by_body[ (string) $q['body'] ][] = $i;
		}

		$held     = array_fill( 0, count( $questions ), 0 );
		$right    = $held;
		$attempts = 0;

		foreach ( $reviews as $review ) {
			++$attempts;
			if ( ! is_array( $review ) ) {
				continue;
			}
			// One attempt holds a question once, however its snapshot reads.
			$seen = array();
			foreach ( $review as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$i = null;
				if ( isset( $entry['id'] ) && is_numeric( $entry['id'] ) && (int) $entry['id'] > 0 ) {
					$i = $by_id[ (int) $entry['id'] ] ?? null;
				} elseif ( isset( $entry['body'] ) ) {
					// Two questions with the same text: the first not taken yet.
					foreach ( $by_body[ (string) $entry['body'] ] ?? array() as $candidate ) {
						if ( ! isset( $seen[ $candidate ] ) ) {
							$i = $candidate;
							break;
						}
					}
				}
				if ( null === $i || isset( $seen[ $i ] ) ) {
					continue;
				}
				$seen[ $i ] = true;
				++$held[ $i ];
				$chosen  = $entry['chosen'] ?? null;
				$correct = $entry['correct'] ?? null;
				if ( null !== $chosen && null !== $correct && (int) $chosen === (int) $correct ) {
					++$right[ $i ];
				}
			}
		}

		if ( $attempts < self::MIN_ATTEMPTS ) {
			return array();
		}

		$rated = array();
		foreach ( $questions as $i => $q ) {
			if ( $held[ $i ] > 0 ) {
				$rated[] = array(
					'i'       => $i,
					'percent' => (int) round( $right[ $i ] / $held[ $i ] * 100 ),
				);
			}
		}
		usort(
			$rated,
			static function ( $a, $b ) {
				return $a['percent'] === $b['percent'] ? $a['i'] - $b['i'] : $a['percent'] - $b['percent'];
			}
		);

		$out = array();
		foreach ( array_slice( $rated, 0, self::HARDEST ) as $r ) {
			$q       = $questions[ $r['i'] ];
			$correct = $q['correct'] ?? null;
			$out[]   = array(
				'number'       => $r['i'] + 1,
				'line'         => self::last_line( $q['body'] ),
				'percent'      => $r['percent'],
				'correct_text' => null !== $correct && isset( $q['options'][ $correct ] ) ? (string) $q['options'][ $correct ] : '',
			);
		}
		return $out;
	}

	/**
	 * Who an attempt belongs to, as one key: a user ID, or for a guest their
	 * name with case, accents and outer spaces ignored.
	 *
	 * @param object $attempt Attempt row.
	 * @return string
	 */
	public static function person( $attempt ) {
		if ( (int) $attempt->user_id > 0 ) {
			return 'u' . (int) $attempt->user_id;
		}
		return 'g' . TBTTM_Rest::fold( trim( (string) $attempt->guest_name ) );
	}

	/**
	 * The numbers above the table, over every finished attempt.
	 *
	 * @param int      $test_id   Test ID.
	 * @param int|null $pass_mark The test's pass mark, or null.
	 * @return array { attempts, people, average, best, passed (int|null) }
	 */
	public static function stats( $test_id, $pass_mark ) {
		$rows   = TBTTM_DB::get_finished_scores( $test_id );
		$people = array();
		$sum    = 0;
		$best   = 0;
		$passed = 0;
		foreach ( $rows as $row ) {
			$percent                           = (int) $row->percent;
			$people[ self::person( $row ) ] = true;
			$sum                              += $percent;
			$best                              = max( $best, $percent );
			if ( null !== $pass_mark && $percent >= $pass_mark ) {
				++$passed;
			}
		}
		$n = count( $rows );
		return array(
			'attempts' => $n,
			'people'   => count( $people ),
			'average'  => $n > 0 ? (int) round( $sum / $n ) : 0,
			'best'     => $best,
			'passed'   => null === $pass_mark ? null : $passed,
		);
	}

	/**
	 * The hardest questions of a test, reading every finished attempt's
	 * snapshot a batch at a time.
	 *
	 * @param int $test_id Test ID.
	 * @return array[]
	 */
	public static function hardest_for( $test_id ) {
		return self::hardest( TBTTM_DB::get_questions( $test_id ), self::reviews( $test_id ) );
	}

	/**
	 * Every finished attempt's snapshot, decoded, one at a time.
	 *
	 * @param int $test_id Test ID.
	 * @return Generator
	 */
	private static function reviews( $test_id ) {
		$after = 0;
		do {
			$rows = TBTTM_DB::get_finished_reviews( $test_id, $after, self::BATCH );
			foreach ( $rows as $row ) {
				$after = (int) $row->id;
				yield json_decode( (string) $row->review, true );
			}
		} while ( count( $rows ) === self::BATCH );
	}

	/**
	 * Everything the Results screen opens with: the test's facts, the stats,
	 * the newest attempts and the hardest questions. No snapshot and no token.
	 *
	 * @param object $test Test row, already checked to be the caller's own.
	 * @return array
	 */
	public static function summary( $test ) {
		$test_id   = (int) $test->id;
		$pass_mark = null === $test->pass_mark ? null : (int) $test->pass_mark;
		$stats     = self::stats( $test_id, $pass_mark );
		$rows      = TBTTM_DB::get_finished_attempts( $test_id, self::LIST_LIMIT );
		$names     = self::names( $rows );

		$attempts = array();
		foreach ( $rows as $row ) {
			$guest      = (int) $row->user_id <= 0;
			$percent    = (int) $row->percent;
			$attempts[] = array(
				'id'          => (int) $row->id,
				'name'        => $guest
					? (string) $row->guest_name
					: ( $names[ (int) $row->user_id ] ?? __( 'Deleted user', 'tbt-test-maker' ) ),
				'guest'       => $guest,
				'person'      => self::person( $row ),
				'finished_at' => (string) $row->finished_at,
				'finished'    => (string) mysql2date( 'j M Y, H:i', get_date_from_gmt( (string) $row->finished_at ) ),
				'score'       => (int) $row->score,
				'total'       => (int) $row->total,
				'percent'     => $percent,
				'seconds'     => self::seconds( $row->started_at, $row->finished_at ),
				'passed'      => null === $pass_mark ? null : $percent >= $pass_mark,
			);
		}

		return array(
			'test'      => array(
				'id'          => $test_id,
				'title'       => (string) $test->title,
				'questions'   => count( TBTTM_DB::get_questions( $test_id ) ),
				'class_title' => TBTTM_Rest::class_title( (int) $test->class_id ),
				'access'      => 'open' === $test->access ? 'open' : 'login',
				'pass_mark'   => $pass_mark,
			),
			'stats'     => $stats,
			'attempts'  => $attempts,
			'hardest'   => self::hardest_for( $test_id ),
			'truncated' => $stats['attempts'] > count( $attempts ),
		);
	}

	/**
	 * Display names for the users behind these attempts, in one query. A user
	 * who no longer exists is simply missing from the map.
	 *
	 * @param object[] $rows Attempt rows.
	 * @return array<int, string> User ID → display name.
	 */
	private static function names( array $rows ) {
		$ids = array();
		foreach ( $rows as $row ) {
			if ( (int) $row->user_id > 0 ) {
				$ids[ (int) $row->user_id ] = true;
			}
		}
		if ( ! $ids ) {
			return array();
		}
		$names = array();
		$users = get_users(
			array(
				'include' => array_keys( $ids ),
				'fields'  => array( 'ID', 'display_name' ),
			)
		);
		foreach ( $users as $user ) {
			$names[ (int) $user->ID ] = (string) $user->display_name;
		}
		return $names;
	}
}
