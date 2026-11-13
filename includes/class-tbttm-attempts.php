<?php
/**
 * A student's sitting: saving answers, checking one, scoring, the snapshot.
 *
 * The answer key never leaves the server before an attempt is finished. The
 * only exceptions are the questions a student has already checked in
 * "after each question" mode, whose answer was revealed at that moment.
 *
 * A finished attempt stores `review`: one entry per question, in order, with
 * the question's ID (from 0.3.0), what the student saw and what they chose. Results are always rendered from it, never
 * from the live questions, so editing a test later does not change old
 * results.
 *
 * From 0.5.0 a question is multiple choice or fill in the gaps. An answer is
 * an option index for the first and a list of typed words, one per gap, for
 * the second. Scores count points: one per multiple choice question, one per
 * gap. A gap question's stored body holds its answers, so the student page
 * only ever receives TBTTM_Gaps::public_body() of it.
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
			if ( self::is_gap( $q ) ) {
				// No accepted answers and no answer lengths: every gap is
				// the same empty marker.
				$out[] = array(
					'id'   => (int) $q['id'],
					'type' => 'gap',
					'body' => TBTTM_Gaps::public_body( (string) $q['body'] ),
					'gaps' => count( $q['gaps'] ),
				);
				continue;
			}
			$out[] = array(
				'id'      => (int) $q['id'],
				'type'    => 'choice',
				'body'    => (string) $q['body'],
				'options' => array_values( array_map( 'strval', $q['options'] ) ),
			);
		}
		return $out;
	}

	/**
	 * Whether a question from TBTTM_DB::get_questions() is fill in the gaps.
	 * A question without a type is multiple choice.
	 *
	 * @param array $q Question.
	 * @return bool
	 */
	public static function is_gap( array $q ) {
		return isset( $q['type'] ) && 'gap' === $q['type'] && isset( $q['gaps'] ) && is_array( $q['gaps'] );
	}

	/**
	 * Stored answers: question ID → option index, or for a gap question a
	 * list of typed words.
	 *
	 * @param object $attempt Attempt row.
	 * @return array<int, int|string[]>
	 */
	public static function answers( $attempt ) {
		$raw = json_decode( (string) $attempt->answers, true );
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $qid => $k ) {
				if ( ! is_numeric( $qid ) ) {
					continue;
				}
				if ( is_array( $k ) ) {
					$out[ (int) $qid ] = array_map( array( 'TBTTM_Gaps', 'clean' ), array_values( $k ) );
				} elseif ( is_numeric( $k ) ) {
					$out[ (int) $qid ] = (int) $k;
				}
			}
		}
		return $out;
	}

	/**
	 * How many questions have an answer: a multiple choice question with an
	 * option chosen, a gap question with text in every gap.
	 *
	 * @param array   $answers   Answers, from answers().
	 * @param array[] $questions The test's questions.
	 * @return int
	 */
	public static function answered( array $answers, array $questions ) {
		$n = 0;
		foreach ( $questions as $q ) {
			$qid = (int) $q['id'];
			if ( ! array_key_exists( $qid, $answers ) ) {
				continue;
			}
			if ( self::is_gap( $q ) ) {
				$n += TBTTM_Gaps::complete( $answers[ $qid ], count( $q['gaps'] ) ) ? 1 : 0;
			} elseif ( ! is_array( $answers[ $qid ] ) ) {
				++$n;
			}
		}
		return $n;
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
	 * question is in the test, it is the kind of answer that question takes,
	 * and the question has not been checked yet; everything else is ignored.
	 * A multiple choice answer must name an option that exists. A gap answer
	 * is a list: padded or cut to the number of gaps, every word cleaned as
	 * one line of text and cut to 100 characters.
	 *
	 * @param array   $stored    Stored answers.
	 * @param mixed   $incoming  Raw map from the request.
	 * @param array[] $questions The test's questions.
	 * @param int[]   $checked   Checked question IDs.
	 * @return array<int, int|string[]>
	 */
	public static function merge( array $stored, $incoming, array $questions, array $checked ) {
		if ( ! is_array( $incoming ) ) {
			return $stored;
		}
		$sizes = array();
		$gaps  = array();
		foreach ( $questions as $q ) {
			if ( self::is_gap( $q ) ) {
				$gaps[ (int) $q['id'] ] = count( $q['gaps'] );
			} else {
				$sizes[ (int) $q['id'] ] = count( $q['options'] );
			}
		}
		$locked = array_flip( $checked );

		foreach ( $incoming as $qid => $k ) {
			if ( ! is_numeric( $qid ) ) {
				continue;
			}
			$qid = (int) $qid;
			if ( isset( $locked[ $qid ] ) ) {
				continue;
			}
			if ( isset( $gaps[ $qid ] ) ) {
				if ( is_array( $k ) ) {
					$stored[ $qid ] = TBTTM_Gaps::shape( $k, $gaps[ $qid ] );
				}
				continue;
			}
			if ( ! isset( $sizes[ $qid ] ) || ! is_numeric( $k ) ) {
				continue;
			}
			$k = (int) $k;
			if ( $k < 0 || $k >= $sizes[ $qid ] ) {
				continue;
			}
			$stored[ $qid ] = $k;
		}
		return $stored;
	}

	/**
	 * The state the student page starts from: answers, checked questions, and
	 * what each checked question already revealed: the correct option, or for
	 * a gap question the verdict on each gap and its first accepted answer.
	 * Nothing about an unchecked question is in it.
	 *
	 * @param object  $attempt   Attempt row.
	 * @param array[] $questions The test's questions.
	 * @return array
	 */
	public static function state( $attempt, array $questions ) {
		$checked  = self::checked( $attempt );
		$answers  = self::answers( $attempt );
		$revealed = array();
		foreach ( $questions as $q ) {
			if ( ! in_array( (int) $q['id'], $checked, true ) ) {
				continue;
			}
			if ( self::is_gap( $q ) ) {
				$revealed[ (string) $q['id'] ] = self::reveal( $q, $answers[ (int) $q['id'] ] ?? null );
			} elseif ( null !== $q['correct'] ) {
				$revealed[ (string) $q['id'] ] = (int) $q['correct'];
			}
		}
		return array(
			'id'       => (int) $attempt->id,
			'token'    => (string) $attempt->token,
			'answers'  => (object) $answers,
			'checked'  => $checked,
			'revealed' => (object) $revealed,
		);
	}

	/**
	 * What checking a gap question reveals: the verdict on each gap, and the
	 * first accepted answer of each. Only ever sent for a checked question.
	 *
	 * @param array $q     Gap question.
	 * @param mixed $given The stored answer.
	 * @return array{right:bool[],correct:string[]}
	 */
	public static function reveal( array $q, $given ) {
		return array(
			'right'   => TBTTM_Gaps::mark( $q['gaps'], TBTTM_Gaps::shape( $given, count( $q['gaps'] ) ) ),
			'correct' => TBTTM_Gaps::first( $q['gaps'] ),
		);
	}

	/**
	 * Score an attempt and build its snapshot. Points: one for a right
	 * multiple choice answer, one for each right gap; `total` is every point
	 * in the test.
	 *
	 * A review entry without `type` is a multiple choice entry: that is how
	 * every entry stored before 0.5.0 reads, and none of them is rewritten.
	 *
	 * @param array   $answers   Final answers.
	 * @param array[] $questions The test's questions at finish.
	 * @return array{score:int,total:int,percent:int,review:array}
	 */
	public static function score( array $answers, array $questions ) {
		$score  = 0;
		$total  = 0;
		$review = array();
		foreach ( $questions as $q ) {
			$qid = (int) $q['id'];
			if ( self::is_gap( $q ) ) {
				$gaps   = array_values( $q['gaps'] );
				$given  = TBTTM_Gaps::shape( $answers[ $qid ] ?? null, count( $gaps ) );
				$right  = TBTTM_Gaps::mark( $gaps, $given );
				$score += count( array_filter( $right ) );
				$total += count( $gaps );
				$review[] = array(
					'id'    => $qid,
					'type'  => 'gap',
					'body'  => (string) $q['body'],
					'gaps'  => $gaps,
					'given' => $given,
					'right' => $right,
				);
				continue;
			}
			++$total;
			// An answer of the wrong kind (the question changed type while
			// the attempt was open) is no answer.
			$chosen = array_key_exists( $qid, $answers ) && ! is_array( $answers[ $qid ] ) ? (int) $answers[ $qid ] : null;
			if ( null !== $chosen && null !== $q['correct'] && $chosen === (int) $q['correct'] ) {
				++$score;
			}
			$review[] = array(
				// The question's ID, from 0.3.0: it lets the Results screen
				// follow a question through later edits. Older snapshots
				// have none.
				'id'      => $qid,
				'body'    => (string) $q['body'],
				'options' => array_values( array_map( 'strval', $q['options'] ) ),
				'chosen'  => $chosen,
				'correct' => null === $q['correct'] ? null : (int) $q['correct'],
			);
		}
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
			'name'        => self::name( $attempt ),
			'score'       => (int) $attempt->score,
			'total'       => (int) $attempt->total,
			'percent'     => $percent,
			'pass_mark'   => $pass_mark,
			'passed'      => null === $pass_mark ? null : $percent >= $pass_mark,
			'review'      => is_array( $review ) ? array_values( $review ) : array(),
			'finished_at' => (string) $attempt->finished_at,
			// Whether a certificate can be downloaded for this attempt.
			'certificate' => TBTTM_Certificate::available( $attempt, $test ),
		);
	}

	/**
	 * Whose attempt it is, for the result: the guest's name as typed, or the
	 * user's display name.
	 *
	 * @param object $attempt Attempt row.
	 * @return string
	 */
	public static function name( $attempt ) {
		if ( (int) $attempt->user_id <= 0 ) {
			return (string) $attempt->guest_name;
		}
		$user = get_userdata( (int) $attempt->user_id );
		return $user ? (string) $user->display_name : '';
	}

	/**
	 * Finish an attempt: merge the last answers, score, write the snapshot.
	 * Finishing a finished attempt changes nothing and returns the stored
	 * result, so a retried request after a lost response is harmless.
	 *
	 * The write happens only while the attempt is still in progress, and the
	 * database decides who made it. When two finish requests arrive together
	 * (a double click, or a retry racing the first), exactly one of them
	 * moves the attempt to finished. That one fires `tbttm_attempt_finished`,
	 * once, which is where the result email is queued. The other writes
	 * nothing, fires nothing, and returns the result the first one stored:
	 * never an error.
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

		$won = TBTTM_DB::finish_attempt(
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
		// Whatever happened, the row itself says where the attempt stands.
		$stored = TBTTM_DB::get_attempt( (int) $attempt->id );
		if ( ! $stored || TBTTM_DB::ATTEMPT_FINISHED !== $stored->status ) {
			// The write failed and nobody else finished it: nothing is
			// lost, the page keeps the answers and tries again.
			return new WP_Error( 'tbttm_save_failed', __( 'The result could not be saved. Try again.', 'tbt-test-maker' ), array( 'status' => 500 ) );
		}

		if ( $won ) {
			// This request moved the attempt to finished. Extras hang here
			// (the result email); a failure in one must never undo a finish.
			try {
				do_action( 'tbttm_attempt_finished', $stored, $test );
			} catch ( Throwable $e ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( 'TBT Test Maker: after finishing: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}

		return self::result( $stored, $test );
	}
}
