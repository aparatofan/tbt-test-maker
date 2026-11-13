<?php
/**
 * The result email: a student's score, sent once when their attempt moves to
 * finished, on a test with "Email the result" on.
 *
 * It is an extra. It is queued by the one request that finished the attempt
 * (the `tbttm_attempt_finished` action) and sent after that request's
 * response has gone, so a slow mail server never makes a student wait. Every
 * failure is caught here; the student sees nothing and the finish stands.
 *
 * Follows the approved email in docs/prototypes/certificate.html.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Mail {

	/*
	 * The colours. An HTML email cannot use the stylesheet or its tokens, so
	 * the ones the email needs are written here once, each copying a token in
	 * tbt-tokens.css. The first three are the certificate's.
	 */

	/** --tbt-blue: the score and the button. */
	const BLUE = TBTTM_Certificate::BLUE;

	/** --tbt-ink: the text. */
	const INK = TBTTM_Certificate::INK;

	/** --tbt-muted: the footer and the "Not passed yet" label. */
	const MUTED = TBTTM_Certificate::MUTED;

	/** --tbt-success: the "Passed" label. */
	const SUCCESS = '#16803C';

	/** --tbt-success-bg: behind the "Passed" label. */
	const SUCCESS_BG = '#ECFDF3';

	/** --tbt-border: the card's outline and the line above the footer. */
	const BORDER = '#D8E3F0';

	/** --tbt-surface: the card, and the button's text. */
	const WHITE = '#FFFFFF';

	/** Result emails one address may get for one test in an hour. */
	const HOURLY_LIMIT = 3;

	/** Emails waiting for the end of this request. */
	private static $queue = array();

	/** The plain-text body of the email being sent, for the mailer hook. */
	private static $alt_body = '';

	public function __construct() {
		add_action( 'tbttm_attempt_finished', array( __CLASS__, 'queue' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * When, and to whom
	 * ------------------------------------------------------------------- */

	/**
	 * Where this attempt's result goes, or '' for nowhere: the logged-in
	 * student's account email, or the guest's. The test's owner trying
	 * their own test gets nothing.
	 *
	 * @param object $attempt Attempt row.
	 * @param object $test    Test row.
	 * @return string
	 */
	public static function recipient( $attempt, $test ) {
		if ( ! $attempt || ! $test || empty( $test->email_result ) ) {
			return '';
		}
		$user_id = (int) $attempt->user_id;
		if ( $user_id > 0 ) {
			if ( $user_id === (int) $test->owner_id ) {
				return '';
			}
			$user  = get_userdata( $user_id );
			$email = $user ? (string) $user->user_email : '';
		} else {
			$email = (string) $attempt->guest_email;
		}
		return is_email( $email ) ? $email : '';
	}

	/**
	 * An attempt has just moved to finished: remember to email its result
	 * once this request's response has been sent. Called exactly once per
	 * attempt, by the request that finished it.
	 *
	 * @param object $attempt Finished attempt row.
	 * @param object $test    Test row.
	 */
	public static function queue( $attempt, $test ) {
		try {
			if ( '' === self::recipient( $attempt, $test ) ) {
				return;
			}
			if ( ! self::$queue ) {
				register_shutdown_function( array( __CLASS__, 'flush' ) );
			}
			self::$queue[] = array( $attempt, $test );
		} catch ( Throwable $e ) {
			self::log( $e->getMessage() );
		}
	}

	/**
	 * End of the request: let the response go, then send what is waiting.
	 */
	public static function flush() {
		$waiting     = self::$queue;
		self::$queue = array();
		if ( ! $waiting ) {
			return;
		}
		// The student has their result from here on; the mail server's pace
		// is no longer theirs to wait for.
		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		foreach ( $waiting as $item ) {
			self::send( $item[0], $item[1] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------- */

	/**
	 * Send one result email. Never throws; returns whether the mailer took it.
	 *
	 * @param object $attempt Finished attempt row.
	 * @param object $test    Test row.
	 * @return bool
	 */
	public static function send( $attempt, $test ) {
		$file = '';
		try {
			$to = self::recipient( $attempt, $test );
			if ( '' === $to || ! self::allow( (int) $test->id, $to ) ) {
				return false;
			}

			// The certificate rides along when one was earned and can be made.
			$attachments = array();
			$pdf         = TBTTM_Certificate::available( $attempt, $test ) ? TBTTM_Certificate::pdf( $attempt, $test ) : '';
			if ( '' !== $pdf ) {
				if ( ! function_exists( 'wp_tempnam' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				$file = (string) wp_tempnam( 'tbttm-certificate' );
				if ( '' !== $file && false !== file_put_contents( $file, $pdf ) ) {
					// The key is the name the student sees (WordPress 6.2 and later).
					$attachments[ TBTTM_Certificate::filename( $test ) ] = $file;
				}
			}

			$message        = self::message( $attempt, $test, (bool) $attachments );
			self::$alt_body = $message['text'];
			add_action( 'phpmailer_init', array( __CLASS__, 'add_plain_text' ) );
			add_action( 'wp_mail_failed', array( __CLASS__, 'log_failure' ) );
			$sent = wp_mail( $to, $message['subject'], $message['html'], array( 'Content-Type: text/html; charset=UTF-8' ), $attachments );
			if ( ! $sent ) {
				self::log( 'the result email was not accepted by the mailer' );
			}
			return (bool) $sent;
		} catch ( Throwable $e ) {
			self::log( $e->getMessage() );
			return false;
		} finally {
			remove_action( 'phpmailer_init', array( __CLASS__, 'add_plain_text' ) );
			remove_action( 'wp_mail_failed', array( __CLASS__, 'log_failure' ) );
			self::$alt_body = '';
			// The temporary PDF goes whether the email went or not.
			if ( '' !== $file && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * May another result email go to this address for this test? At most
	 * HOURLY_LIMIT in any hour, so the guest email field cannot be used to
	 * send mail to a stranger at will. Counts the email it allows.
	 *
	 * @param int    $test_id Test ID.
	 * @param string $email   Address.
	 * @return bool
	 */
	public static function allow( $test_id, $email ) {
		$key   = 'tbttm_mail_' . md5( (int) $test_id . '|' . strtolower( trim( (string) $email ) ) );
		$now   = time();
		$sent  = get_transient( $key );
		$sent  = is_array( $sent ) ? $sent : array();
		$fresh = array();
		foreach ( $sent as $when ) {
			if ( is_numeric( $when ) && (int) $when > $now - HOUR_IN_SECONDS ) {
				$fresh[] = (int) $when;
			}
		}
		if ( count( $fresh ) >= self::HOURLY_LIMIT ) {
			return false;
		}
		$fresh[] = $now;
		set_transient( $key, $fresh, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Give the email a plain-text alternative, when the site's mailer is
	 * WordPress's own (or an SMTP plugin that keeps it).
	 *
	 * @param object $phpmailer The mailer.
	 */
	public static function add_plain_text( $phpmailer ) {
		if ( '' !== self::$alt_body && is_object( $phpmailer ) ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * @param WP_Error $error What the mailer said.
	 */
	public static function log_failure( $error ) {
		self::log( is_wp_error( $error ) ? $error->get_error_message() : 'unknown mail error' );
	}

	private static function log( $text ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'TBT Test Maker: result email: ' . $text ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/* ---------------------------------------------------------------------
	 * What it says
	 * ------------------------------------------------------------------- */

	/**
	 * The email: subject, HTML body, plain-text body.
	 *
	 * @param object $attempt     Finished attempt row.
	 * @param object $test        Test row.
	 * @param bool   $certificate Whether the certificate is attached.
	 * @return array{subject: string, html: string, text: string}
	 */
	public static function message( $attempt, $test, $certificate ) {
		$title = trim( (string) $test->title );
		$name  = TBTTM_Certificate::name( $attempt );
		$first = (string) strtok( trim( $name ), " \t" );
		$hi    = '' !== $first
			/* translators: %s: the student's first name. */
			? sprintf( __( 'Hi %s,', 'tbt-test-maker' ), $first )
			: __( 'Hi,', 'tbt-test-maker' );

		$score   = (int) $attempt->score . ' / ' . (int) $attempt->total . ' · ' . (int) $attempt->percent . '%';
		$has_bar = null !== $test->pass_mark && '' !== $test->pass_mark;
		$passed  = $has_bar && (int) $attempt->percent >= (int) $test->pass_mark;
		$label   = $has_bar ? ( $passed ? __( 'Passed', 'tbt-test-maker' ) : __( 'Not passed yet', 'tbt-test-maker' ) ) : '';

		// The Student page with this result showing. Without a Student page
		// there is nowhere to send them, so the button and its sentence go.
		$link = TBTTM_Urls::result_url( (string) $test->slug, (string) $attempt->token );
		$more = '';
		if ( $certificate && '' !== $link ) {
			$more = __( 'Your certificate is attached. To see which answers were right and which were wrong, open your result page.', 'tbt-test-maker' );
		} elseif ( $certificate ) {
			$more = __( 'Your certificate is attached.', 'tbt-test-maker' );
		} elseif ( '' !== $link ) {
			$more = __( 'To see which answers were right and which were wrong, open your result page.', 'tbt-test-maker' );
		}
		$button = __( 'See my answers', 'tbt-test-maker' );
		$footer = 'The Blue Tree · The Place Where People Grow';
		/* translators: %s: the test title. */
		$subject = sprintf( __( 'Your result: %s', 'tbt-test-maker' ), $title );

		$font = 'font-family:Roboto,Helvetica,Arial,sans-serif;';
		$p    = 'margin:0 0 16px;' . $font . 'font-size:15px;line-height:1.5;color:' . self::INK . ';';
		$pill = $has_bar
			? ' <span style="display:inline-block;border-radius:999px;padding:2px 10px;' . $font . 'font-size:13px;font-weight:600;line-height:1.5;vertical-align:middle;'
				. ( $passed
					? 'background:' . self::SUCCESS_BG . ';color:' . self::SUCCESS . ';'
					: 'background:' . self::WHITE . ';color:' . self::MUTED . ';border:1px solid ' . self::BORDER . ';' )
				. '">' . esc_html( $label ) . '</span>'
			: '';

		$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . self::WHITE . ';">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . self::WHITE . ';"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;border:1px solid ' . self::BORDER . ';border-radius:16px;background:' . self::WHITE . ';">'
			. '<tr><td style="padding:24px 24px 8px;">'
			. '<p style="' . $p . '">' . esc_html( $hi ) . '</p>'
			/* translators: %s: the test title, in bold. */
			. '<p style="' . $p . '">' . sprintf( esc_html__( 'You finished %s. Here is your result.', 'tbt-test-maker' ), '<strong>' . esc_html( $title ) . '</strong>' ) . '</p>'
			. '<p style="margin:0 0 16px;font-family:\'Roboto Slab\',Georgia,serif;font-size:28px;font-weight:700;line-height:1.2;color:' . self::BLUE . ';">' . esc_html( $score ) . $pill . '</p>'
			. ( '' !== $more ? '<p style="' . $p . '">' . esc_html( $more ) . '</p>' : '' )
			. ( '' !== $link
				? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;"><tr><td style="border-radius:12px;background:' . self::BLUE . ';">'
					. '<a href="' . esc_url( $link ) . '" style="display:inline-block;padding:10px 20px;' . $font . 'font-size:15px;font-weight:600;line-height:1.5;color:' . self::WHITE . ';text-decoration:none;border-radius:12px;">' . esc_html( $button ) . '</a>'
					. '</td></tr></table>'
				: '' )
			. '</td></tr>'
			. '<tr><td style="padding:16px 24px 24px;border-top:1px solid ' . self::BORDER . ';">'
			. '<p style="margin:0;' . $font . 'font-size:13px;line-height:1.5;color:' . self::MUTED . ';">' . esc_html( $footer ) . '</p>'
			. '</td></tr></table></td></tr></table></body></html>';

		$text = $hi . "\n\n"
			/* translators: %s: the test title. */
			. sprintf( __( 'You finished %s. Here is your result.', 'tbt-test-maker' ), $title ) . "\n\n"
			. $score . ( '' !== $label ? ' (' . $label . ')' : '' ) . "\n\n"
			. ( '' !== $more ? $more . "\n\n" : '' )
			. ( '' !== $link ? $button . ': ' . $link . "\n\n" : '' )
			. $footer . "\n";

		return array(
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
		);
	}
}
