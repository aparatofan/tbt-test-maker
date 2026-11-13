<?php
/**
 * Fill in the gaps: the one place that knows what a gap is.
 *
 * In the teacher's text a gap is a pair of square brackets with the answer
 * inside: `I couldn't agree [more].` A `|` separates answers that are all
 * accepted, and the first one is the one shown as "the correct answer":
 * `I [don't|do not] think so.` Brackets do not nest.
 *
 * A gap question's stored body therefore holds its answers. The student page
 * only ever receives public_body(), where every gap is an empty `[]`.
 *
 * Plain PHP only: no WordPress function, so tests/test-gaps.php runs it
 * standalone.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Gaps {

	/** Characters in a gap question's text. */
	const BODY_MAX = 6000;

	/** Gaps in one question. */
	const GAPS_MAX = 60;

	/** Accepted answers in one gap. */
	const ACCEPTED_MAX = 6;

	/** Characters in one answer, accepted or typed. */
	const ANSWER_MAX = 100;

	/** What stands for a gap on a line of plain text. */
	const BLANK = '_____';

	/** One gap: brackets with no bracket inside. */
	const PATTERN = '/\[([^\[\]]*)\]/u';

	/**
	 * The gaps of a text, in order: each a list of accepted answers, trimmed,
	 * empty ones dropped. A gap with nothing in it is an empty list, so the
	 * list always has one entry per `[…]` in the text.
	 *
	 * @param string $body The teacher's text.
	 * @return array<int, string[]>
	 */
	public static function parse( $body ) {
		$gaps = array();
		if ( ! preg_match_all( self::PATTERN, (string) $body, $found ) ) {
			return $gaps;
		}
		foreach ( $found[1] as $inside ) {
			$accepted = array();
			foreach ( explode( '|', $inside ) as $answer ) {
				$answer = self::clean( $answer );
				if ( '' !== $answer && count( $accepted ) < self::ACCEPTED_MAX ) {
					$accepted[] = $answer;
				}
			}
			$gaps[] = $accepted;
		}
		return $gaps;
	}

	/**
	 * The text with every `[…]` replaced by `[]`. This is the only form of a
	 * gap body the student page may receive.
	 *
	 * @param string $body The teacher's text.
	 * @return string
	 */
	public static function public_body( $body ) {
		return (string) preg_replace( self::PATTERN, '[]', (string) $body );
	}

	/**
	 * What is wrong with a gap text, as a code, or '' when it can be
	 * published. In this order: 'empty' (no text), 'none' (no gap yet),
	 * 'hollow' (a gap is empty), 'open' (a bracket is not closed), 'many'
	 * (too many gaps).
	 *
	 * @param string $body The teacher's text.
	 * @return string
	 */
	public static function issue( $body ) {
		$body = (string) $body;
		if ( '' === trim( $body ) ) {
			return 'empty';
		}
		$gaps = self::parse( $body );
		if ( ! $gaps ) {
			// A lone bracket is still "no gap yet": that is what to fix first.
			return 'none';
		}
		foreach ( $gaps as $accepted ) {
			if ( ! $accepted ) {
				return 'hollow';
			}
		}
		if ( preg_match( '/[\[\]]/', (string) preg_replace( self::PATTERN, '', $body ) ) ) {
			return 'open';
		}
		if ( count( $gaps ) > self::GAPS_MAX ) {
			return 'many';
		}
		return '';
	}

	/**
	 * A typed or accepted answer as one line of text: control characters and
	 * line breaks become spaces, runs of white space one space, cut to
	 * ANSWER_MAX characters. Anything that is not text is ''.
	 *
	 * @param mixed $text Raw answer.
	 * @return string
	 */
	public static function clean( $text ) {
		if ( ! is_scalar( $text ) ) {
			return '';
		}
		$text = (string) $text;
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			return '';
		}
		$text = trim( (string) preg_replace( '/[\s\x{00A0}\x00-\x1F\x7F]+/u', ' ', $text ) );
		if ( mb_strlen( $text, 'UTF-8' ) > self::ANSWER_MAX ) {
			$text = rtrim( mb_substr( $text, 0, self::ANSWER_MAX, 'UTF-8' ) );
		}
		return $text;
	}

	/**
	 * The form two answers are compared in: lower case, every kind of
	 * apostrophe turned into ', runs of white space turned into one space,
	 * trimmed.
	 *
	 * @param string $text Answer.
	 * @return string
	 */
	public static function normalise( $text ) {
		$text = mb_strtolower( (string) $text, 'UTF-8' );
		$text = str_replace( array( '’', '‘', '`', '´' ), "'", $text );
		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	/**
	 * Whether a typed answer is one of the accepted ones. An empty answer is
	 * never right.
	 *
	 * @param mixed    $given    What the student typed.
	 * @param string[] $accepted The gap's accepted answers.
	 * @return bool
	 */
	public static function right( $given, $accepted ) {
		$given = is_scalar( $given ) ? self::normalise( (string) $given ) : '';
		if ( '' === $given || ! is_array( $accepted ) ) {
			return false;
		}
		foreach ( $accepted as $answer ) {
			if ( is_scalar( $answer ) && self::normalise( (string) $answer ) === $given ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A typed answer shaped to a question: one string per gap, padded or cut
	 * to the number of gaps, each cleaned.
	 *
	 * @param mixed $given Raw answer; anything but a list counts as empty.
	 * @param int   $count Gaps in the question.
	 * @return string[]
	 */
	public static function shape( $given, $count ) {
		$given = is_array( $given ) ? array_values( $given ) : array();
		$out   = array();
		for ( $g = 0; $g < $count; $g++ ) {
			$out[] = array_key_exists( $g, $given ) ? self::clean( $given[ $g ] ) : '';
		}
		return $out;
	}

	/**
	 * Whether every gap of a shaped answer has text.
	 *
	 * @param mixed $given Answer.
	 * @param int   $count Gaps in the question.
	 * @return bool
	 */
	public static function complete( $given, $count ) {
		if ( ! is_array( $given ) || $count < 1 ) {
			return false;
		}
		foreach ( self::shape( $given, $count ) as $word ) {
			if ( '' === $word ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The verdict on each gap: true where the typed word is accepted.
	 *
	 * @param array<int, string[]> $gaps  Accepted answers per gap.
	 * @param string[]             $given Shaped answer.
	 * @return bool[]
	 */
	public static function mark( array $gaps, array $given ) {
		$out = array();
		foreach ( array_values( $gaps ) as $g => $accepted ) {
			$out[] = self::right( $given[ $g ] ?? '', $accepted );
		}
		return $out;
	}

	/**
	 * The first accepted answer of each gap: the one shown as correct.
	 *
	 * @param array<int, string[]> $gaps Accepted answers per gap.
	 * @return string[]
	 */
	public static function first( array $gaps ) {
		$out = array();
		foreach ( $gaps as $accepted ) {
			$out[] = is_array( $accepted ) && $accepted ? (string) reset( $accepted ) : '';
		}
		return $out;
	}

	/**
	 * The text with each gap replaced by the matching word, as plain text.
	 *
	 * @param string   $body  The teacher's text.
	 * @param string[] $words One word per gap, in order.
	 * @return string
	 */
	public static function filled( $body, array $words ) {
		$words = array_values( $words );
		$g     = 0;
		return (string) preg_replace_callback(
			self::PATTERN,
			static function () use ( $words, &$g ) {
				$word = isset( $words[ $g ] ) && is_scalar( $words[ $g ] ) ? (string) $words[ $g ] : '';
				++$g;
				return $word;
			},
			(string) $body
		);
	}

	/**
	 * The line that stands for a gap question in a list: the first line that
	 * has a gap, or the last line with text when there is none. Brackets and
	 * all; see blank() and keyed().
	 *
	 * @param string $body The teacher's text.
	 * @return string
	 */
	public static function line( $body ) {
		$last = '';
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $body ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$last = trim( $line );
			if ( preg_match( self::PATTERN, $line ) ) {
				return $last;
			}
		}
		return $last;
	}

	/**
	 * A text with `_____` for each gap.
	 *
	 * @param string $text Text with gaps.
	 * @return string
	 */
	public static function blank( $text ) {
		return (string) preg_replace( self::PATTERN, self::BLANK, (string) $text );
	}

	/**
	 * A text with the first accepted answer put into each gap.
	 *
	 * @param string $text Text with gaps.
	 * @return string
	 */
	public static function keyed( $text ) {
		return self::filled( $text, self::first( self::parse( $text ) ) );
	}
}
