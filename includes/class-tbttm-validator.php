<?php
/**
 * What a test is allowed to contain.
 *
 * Follows Drag & Drop's split:
 *   sanitise() cleans a payload without enforcing completeness. Draft saves
 *              take this path, so a teacher never loses work by saving early.
 *   validate() runs sanitise() and then the publishing rules, returning the
 *              clean test or a WP_Error that names the problem and the
 *              question numbers.
 *
 * A clean test:
 *   title, intro      strings, trimmed and cut to their limits
 *   image_id          int, 0 for none
 *   feedback          'end' or 'now'
 *   bulls             bool
 *   pass_mark         int 1–100, or null
 *   access            'login' or 'open'
 *   class_id          int > 0, or null
 *   lesson_id         int > 0, or null; always null without a class
 *   certificate       bool: true only for a real true
 *   email_result      bool: true only for a real true
 *   questions         list of { id (0 = new), type 'choice', body, options[], correct (int|null) }
 *                     or      { id (0 = new), type 'gap', body, gaps[][] }
 *
 * A gap question's body holds its gaps in square brackets; `gaps` is read
 * from it by TBTTM_Gaps on every save.
 *
 * The class uses only a handful of WordPress functions, so
 * tests/test-validator.php can run it without WordPress.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Validator {

	const TITLE_MAX     = 200;
	const INTRO_MAX     = 1000;
	const QUESTIONS_MAX = 200;
	const BODY_MAX      = 2000;
	const OPTIONS_MIN   = 2;
	const OPTIONS_MAX   = 6;
	const OPTION_MAX    = 300;

	/** How many question numbers an error message lists before "…". */
	const LIST_MAX = 8;

	/**
	 * Clean a payload without enforcing completeness.
	 *
	 * @param array $raw Raw test data.
	 * @return array
	 */
	public function sanitise( array $raw ) {
		$title = self::text( $raw['title'] ?? '' );
		$intro = self::multiline( $raw['intro'] ?? '' );

		$feedback = isset( $raw['feedback'] ) && 'now' === $raw['feedback'] ? 'now' : 'end';
		$bulls    = array_key_exists( 'bulls', $raw ) ? self::truthy( $raw['bulls'] ) : true;

		$access    = isset( $raw['access'] ) && 'open' === $raw['access'] ? 'open' : 'login';
		$class_id  = self::positive_id( $raw['class_id'] ?? null );
		$lesson_id = null === $class_id ? null : self::positive_id( $raw['lesson_id'] ?? null );

		$questions = array();
		$raw_qs    = isset( $raw['questions'] ) && is_array( $raw['questions'] ) ? array_values( $raw['questions'] ) : array();
		foreach ( array_slice( $raw_qs, 0, self::QUESTIONS_MAX ) as $q ) {
			if ( is_array( $q ) ) {
				$questions[] = $this->sanitise_question( $q );
			}
		}

		return array(
			'title'     => self::cut( $title, self::TITLE_MAX ),
			'intro'     => self::cut( $intro, self::INTRO_MAX ),
			'image_id'  => isset( $raw['image_id'] ) && is_numeric( $raw['image_id'] ) ? max( 0, (int) $raw['image_id'] ) : 0,
			'feedback'  => $feedback,
			'bulls'     => $bulls,
			'pass_mark' => self::pass_mark( $raw['pass_mark'] ?? null ),
			'access'    => $access,
			'class_id'  => $class_id,
			'lesson_id' => $lesson_id,
			// Both off unless the builder sends a real boolean true.
			'certificate'  => isset( $raw['certificate'] ) && true === $raw['certificate'],
			'email_result' => isset( $raw['email_result'] ) && true === $raw['email_result'],
			'questions' => $questions,
		);
	}

	/**
	 * Clean a payload and enforce the publishing rules.
	 *
	 * @param array $raw Raw test data.
	 * @return array|WP_Error
	 */
	public function validate( array $raw ) {
		$clean = $this->sanitise( $raw );

		if ( '' === $clean['title'] ) {
			return new WP_Error( 'tbttm_no_title', __( 'Give the test a title.', 'tbt-test-maker' ) );
		}

		$raw_pass = $raw['pass_mark'] ?? null;
		if ( null === $clean['pass_mark'] && null !== $raw_pass && '' !== trim( (string) ( is_scalar( $raw_pass ) ? $raw_pass : 'x' ) ) ) {
			return new WP_Error( 'tbttm_bad_pass_mark', __( 'The pass mark must be a whole number from 1 to 100, or empty.', 'tbt-test-maker' ) );
		}

		$raw_count = isset( $raw['questions'] ) && is_array( $raw['questions'] ) ? count( $raw['questions'] ) : 0;
		if ( $raw_count > self::QUESTIONS_MAX ) {
			return new WP_Error(
				'tbttm_too_many_questions',
				/* translators: %d: the question limit. */
				sprintf( __( 'A test can hold at most %d questions.', 'tbt-test-maker' ), self::QUESTIONS_MAX )
			);
		}

		if ( empty( $clean['questions'] ) ) {
			return new WP_Error( 'tbttm_no_questions', __( 'Add at least one question before publishing.', 'tbt-test-maker' ) );
		}

		$problems = array();
		foreach ( $clean['questions'] as $i => $q ) {
			$problem = self::problem( $q );
			if ( '' !== $problem ) {
				$problems[ $i + 1 ] = $problem;
			}
		}

		if ( ! empty( $problems ) ) {
			return new WP_Error(
				'tbttm_unfinished_questions',
				self::unfinished_message( $problems ),
				array(
					'status'    => 400,
					'questions' => array_keys( $problems ),
				)
			);
		}

		// Published questions carry only real answers: empty option fields
		// are dropped and the answer key follows its option.
		foreach ( $clean['questions'] as $i => $q ) {
			if ( 'gap' === $q['type'] ) {
				continue;
			}
			$options = array();
			$correct = null;
			foreach ( $q['options'] as $k => $text ) {
				if ( '' === $text ) {
					continue;
				}
				if ( $k === $q['correct'] ) {
					$correct = count( $options );
				}
				$options[] = $text;
			}
			$clean['questions'][ $i ]['options'] = $options;
			$clean['questions'][ $i ]['correct'] = $correct;
		}

		return $clean;
	}

	/**
	 * The first thing that keeps a question from being published, or ''.
	 * Worded to follow "Question 3: …" in a message.
	 *
	 * @param array $q Clean question.
	 * @return string
	 */
	public static function problem( array $q ) {
		if ( 'gap' === ( $q['type'] ?? 'choice' ) ) {
			switch ( TBTTM_Gaps::issue( $q['body'] ) ) {
				case 'empty':
					return __( 'no text', 'tbt-test-maker' );
				case 'none':
					return __( 'no gap yet', 'tbt-test-maker' );
				case 'hollow':
					return __( 'a gap is empty', 'tbt-test-maker' );
				case 'open':
					return __( 'a bracket is not closed', 'tbt-test-maker' );
				case 'many':
					return __( 'too many gaps', 'tbt-test-maker' );
			}
			return '';
		}
		if ( '' === trim( $q['body'] ) ) {
			return __( 'no question text', 'tbt-test-maker' );
		}
		$filled = array_filter( $q['options'], static function ( $o ) {
			return '' !== $o;
		} );
		if ( count( $filled ) < self::OPTIONS_MIN ) {
			return __( 'needs two answers', 'tbt-test-maker' );
		}
		if ( null === $q['correct'] || ! isset( $q['options'][ $q['correct'] ] ) || '' === $q['options'][ $q['correct'] ] ) {
			return __( 'no correct answer', 'tbt-test-maker' );
		}
		return '';
	}

	/**
	 * "Questions 3, 7 are not finished. Question 3: no question text. …"
	 *
	 * @param array<int, string> $problems 1-based number → problem.
	 * @return string
	 */
	private static function unfinished_message( array $problems ) {
		$numbers = array_keys( $problems );
		$shown   = array_slice( $numbers, 0, self::LIST_MAX );
		$list    = implode( ', ', $shown ) . ( count( $numbers ) > self::LIST_MAX ? '…' : '' );

		$head = 1 === count( $numbers )
			/* translators: %s: a question number. */
			? sprintf( __( 'Question %s is not finished:', 'tbt-test-maker' ), $list )
			/* translators: %s: comma-separated question numbers. */
			: sprintf( __( 'Questions %s are not finished.', 'tbt-test-maker' ), $list );

		if ( 1 === count( $numbers ) ) {
			return $head . ' ' . reset( $problems ) . '.';
		}

		$details = array();
		foreach ( array_slice( $problems, 0, self::LIST_MAX, true ) as $n => $problem ) {
			/* translators: 1: question number, 2: the problem. */
			$details[] = sprintf( __( 'Question %1$d: %2$s.', 'tbt-test-maker' ), $n, $problem );
		}
		return $head . ' ' . implode( ' ', $details );
	}

	/**
	 * Clean one question.
	 *
	 * @param array $q Raw question.
	 * @return array
	 */
	private function sanitise_question( array $q ) {
		$id = isset( $q['id'] ) && is_numeric( $q['id'] ) ? max( 0, (int) $q['id'] ) : 0;

		// The type decides, never the content: square brackets in a multiple
		// choice question stay ordinary text.
		if ( isset( $q['type'] ) && 'gap' === $q['type'] ) {
			$body = self::cut( self::multiline( $q['body'] ?? '' ), TBTTM_Gaps::BODY_MAX );
			return array(
				'id'   => $id,
				'type' => 'gap',
				'body' => $body,
				'gaps' => TBTTM_Gaps::parse( $body ),
			);
		}

		$options = array();
		$raw_opt = isset( $q['options'] ) && is_array( $q['options'] ) ? array_values( $q['options'] ) : array();
		foreach ( array_slice( $raw_opt, 0, self::OPTIONS_MAX ) as $o ) {
			$options[] = self::cut( self::text( $o ), self::OPTION_MAX );
		}

		$correct = null;
		if ( isset( $q['correct'] ) && is_numeric( $q['correct'] ) ) {
			$k = (int) $q['correct'];
			if ( $k >= 0 && $k < count( $options ) && (string) $k === (string) $q['correct'] ) {
				$correct = $k;
			}
		}

		return array(
			'id'      => $id,
			'type'    => 'choice',
			'body'    => self::cut( self::multiline( $q['body'] ?? '' ), self::BODY_MAX ),
			'options' => $options,
			'correct' => $correct,
		);
	}

	/**
	 * A pass mark: a whole number 1–100, or null for anything else.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function pass_mark( $value ) {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( ! preg_match( '/^\d{1,3}$/', $value ) ) {
			return null;
		}
		$n = (int) $value;
		return $n >= 1 && $n <= 100 ? $n : null;
	}

	/**
	 * A row ID: a positive whole number, or null for anything else. Whether
	 * the class is one the teacher may use is the REST callback's question.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	private static function positive_id( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^\d{1,18}$/', trim( $value ) ) ) {
			return null;
		}
		$n = (int) trim( $value );
		return $n > 0 ? $n : null;
	}

	/**
	 * One line of plain text.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function text( $value ) {
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	/**
	 * Plain text that keeps its line breaks.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function multiline( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
		// Trailing spaces on each line go; the lines themselves stay.
		$value = sanitize_textarea_field( $value );
		$value = preg_replace( '/[ \t]+\n/', "\n", $value );
		return trim( (string) $value );
	}

	/**
	 * Cut a string to a number of characters.
	 *
	 * @param string $value String.
	 * @param int    $max   Characters.
	 * @return string
	 */
	private static function cut( $value, $max ) {
		return mb_strlen( $value, 'UTF-8' ) > $max ? rtrim( mb_substr( $value, 0, $max, 'UTF-8' ) ) : $value;
	}

	/**
	 * Read a boolean the way JSON and form posts send one.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( trim( (string) ( is_scalar( $value ) ? $value : '' ) ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
