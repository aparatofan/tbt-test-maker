<?php
/**
 * A student's sitting: saving answers, checking one, scoring, the snapshot.
 *
 * The answer key never leaves the server before an attempt is finished. The
 * only exceptions are the questions a student has already checked in
 * "after each question" mode, whose answer was revealed at that moment.
 *
 * A finished attempt stores `review`: one entry per question, in order, with
 * what the student saw and chose. Results are always rendered from it, never
 * from the live questions, so editing a test later does not change old
 * results.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Attempts {

	/**
	 * Questions as the student page may see them: no answer key.
	 *
	 * @param array[] $questions From TBTTM_DB::get_questions().
	 * @return array[]
	 */
	public static function public_questions( array $questions ) {
		$out = array();
		foreach ( $questions as $q ) {
			$out[] = array(
				'id'      => (int) $q['id'],
				'body'    => (string) $q['body'],
				'options' => array_values( array_map( 'strval', $q['options'] ) ),
			);
		}
		return $out;
	}

	/**
	 * Stored answers: question ID → option index.
	 *
	 * @param object $attempt Attempt row.
	 * @return array<int, int>
	 */
	public static function answers( $attempt ) {
		$raw = json_decode( (string) $attempt->answers, true );
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $qid => $k ) {
				if ( is_numeric( $qid ) && is_numeric( $k ) ) {
					$out[ (int) $qid ] = (int) $k;
				}
			}
		}
		return $out;
	}

	/**
	 * Question IDs already checked ("after each question" mode).
	 *
	 * @param object $attempt Attempt row.
	 * @return int[]
	 */
	public static function checked( $attempt ) {
		$raw = json_decode( (string) $attempt->checked, true );
		return is_array( $raw ) ? array_values( array_unique( array_map( 'intval', array_filter( $raw, 'is_numeric' ) ) ) ) : array();
	}

	/**
	 * Merge incoming answers into stored ones. An answer counts only when its
	 * question is in the test, its option exists, and the question has not
	 * been checked yet; everything else is ignored.
	 *
	 * @param array<int, int> $stored    Stored answers.
	 * @param mixed           $incoming  Raw map from the request.
	 * @param array[]         $questions The test's questions.
	 * @param int[]           $checked   Checked question IDs.
	 * @return array<int, int>
	 */
	public static function merge( array $stored, $incoming, array $questions, array $checked ) {
		if ( ! is_array( $incoming ) ) {
			return $stored;
		}
		$sizes = array();
		foreach ( $questions as $q ) {
			$sizes[ (int) $q['id'] ] = count( $q['options'] );
		}
		$locked = array_flip( $checked );

		foreach ( $incoming as $qid => $k ) {
			if ( ! is_numeric( $qid ) || ! is_numeric( $k ) ) {
				continue;
			}
			$qid = (int) $qid;
			$k   = (int) $k;
			if ( ! isset( $sizes[ $qid ] ) || isset( $locked[ $qid ] ) || $k < 0 || $k >= $sizes[ $qid ] ) {
				continue;
			}
			$stored[ $qid ] = $k;
		}
		return $stored;
	}

	/**
	 * The state the student page starts from: answers, checked questions, and
	 * the correct option of each checked question (already revealed).
	 *
	 * @param object  $attempt   Attempt row.
	 * @param array[] $questions The test's questions.
	 * @return array
	 */
	public static function state( $attempt, array $questions ) {
		$checked  = self::checked( $attempt );
		$revealed = array();
		foreach ( $questions as $q ) {
			if ( in_array( (int) $q['id'], $checked, true ) && null !== $q['correct'] ) {
				$revealed[ (string) $q['id'] ] = (int) $q['correct'];
			}
		}
		return array(
			'id'       => (int) $attempt->id,
			'token'    => (string) $attempt->token,
			'answers'  => (object) self::answers( $attempt ),
			'checked'  => $checked,
			'revealed' => (object) $revealed,
		);
	}

	/**
	 * Score an attempt and build its snapshot.
	 *
	 * @param array<int, int> $answers   Final answers.
	 * @param array[]         $questions The test's questions at finish.
	 * @return array{score:int,total:int,percent:int,review:array}
	 */
	public static function score( array $answers, array $questions ) {
		$score  = 0;
		$review = array();
		foreach ( $questions as $q ) {
			$qid    = (int) $q['id'];
			$chosen = array_key_exists( $qid, $answers ) ? (int) $answers[ $qid ] : null;
			if ( null !== $chosen && null !== $q['correct'] && $chosen === (int) $q['correct'] ) {
				++$score;
			}
			$review[] = array(
				'body'    => (string) $q['body'],
				'options' => array_values( array_map( 'strval', $q['options'] ) ),
				'chosen'  => $chosen,
				'correct' => null === $q['correct'] ? null : (int) $q['correct'],
			);
		}
		$total = count( $questions );
		return array(
			'score'   => $score,
			'total'   => $total,
			'percent' => $total > 0 ? (int) round( $score / $total * 100 ) : 0,
			'review'  => $review,
		);
	}

	/**
	 * The result of a finished attempt, from its stored snapshot.
	 *
	 * The pass mark is the test's current one: the attempts table has no
	 * column for it in schema 1.0.
	 *
	 * @param object      $attempt Finished attempt row.
	 * @param object|null $test    Test row, or null if the test is gone.
	 * @return array
	 */
	public static function result( $attempt, $test ) {
		$review    = json_decode( (string) $attempt->review, true );
		$pass_mark = $test && null !== $test->pass_mark ? (int) $test->pass_mark : null;
		$percent   = (int) $attempt->percent;
		return array(
			'token'       => (string) $attempt->token,
			'score'       => (int) $attempt->score,
			'total'       => (int) $attempt->total,
			'percent'     => $percent,
			'pass_mark'   => $pass_mark,
			'passed'      => null === $pass_mark ? null : $percent >= $pass_mark,
			'review'      => is_array( $review ) ? array_values( $review ) : array(),
			'finished_at' => (string) $attempt->finished_at,
		);
	}

	/**
	 * Finish an attempt: merge the last answers, score, write the snapshot.
	 * Finishing a finished attempt changes nothing and returns the stored
	 * result, so a retried request after a lost response is harmless.
	 *
	 * @param object $attempt  Attempt row.
	 * @param object $test     Test row.
	 * @param mixed  $incoming Raw answers from the request.
	 * @return array|WP_Error Result payload.
	 */
	public static function finish( $attempt, $test, $incoming ) {
		if ( TBTTM_DB::ATTEMPT_FINISHED === $attempt->status ) {
			return self::result( $attempt, $test );
		}

		$questions = TBTTM_DB::get_questions( (int) $test->id );
		$answers   = self::merge( self::answers( $attempt ), $incoming, $questions, self::checked( $attempt ) );
		$scored    = self::score( $answers, $questions );

		$ok = TBTTM_DB::update_attempt(
			(int) $attempt->id,
			array(
				'answers'     => wp_json_encode( (object) $answers ),
				'review'      => wp_json_encode( $scored['review'] ),
				'score'       => $scored['score'],
				'total'       => $scored['total'],
				'percent'     => $scored['percent'],
				'status'      => TBTTM_DB::ATTEMPT_FINISHED,
				'finished_at' => TBTTM_DB::now(),
			)
		);
		if ( ! $ok ) {
			return new WP_Error( 'tbttm_save_failed', __( 'The result could not be saved. Try again.', 'tbt-test-maker' ), array( 'status' => 500 ) );
		}

		return self::result( TBTTM_DB::get_attempt( (int) $attempt->id ), $test );
	}
}
