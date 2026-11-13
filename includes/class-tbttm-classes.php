<?php
/**
 * Read-only bridge to TBT Notes classes and lessons.
 *
 * This plugin never touches the Notes tables directly. Everything goes
 * through the public static methods on TBT_Notes_DB, the same ones TBT Swipe
 * and Translation Drill use, so ownership logic lives in exactly one place.
 *
 * Notes is optional. Every method returns a safe empty value when it is
 * inactive, so deactivating Notes removes the class picker and nothing else.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Classes {

	/**
	 * Methods this bridge relies on. Checked individually so a future Notes
	 * refactor surfaces as "class attachment unavailable" rather than a fatal.
	 */
	const REQUIRED_METHODS = array(
		'get_classes_for_teacher',
		'get_lessons_for_class',
		'get_class',
		'get_lesson',
	);

	/**
	 * Is TBT Notes present and exposing everything we need?
	 *
	 * @return bool
	 */
	public static function available() {
		if ( ! class_exists( 'TBT_Notes_DB' ) ) {
			return false;
		}
		foreach ( self::REQUIRED_METHODS as $method ) {
			if ( ! method_exists( 'TBT_Notes_DB', $method ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The classes a teacher owns.
	 *
	 * @param int $user_id Teacher user ID.
	 * @return array[] Each with at least id, title. Empty when Notes is unavailable.
	 */
	public static function for_teacher( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! self::available() || $user_id <= 0 ) {
			return array();
		}
		$classes = TBT_Notes_DB::get_classes_for_teacher( $user_id );
		return is_array( $classes ) ? $classes : array();
	}

	/**
	 * The lessons in a class.
	 *
	 * @param int $class_id Class ID.
	 * @return array[] Each with at least id, header. Empty when Notes is unavailable.
	 */
	public static function lessons_for_class( $class_id ) {
		$class_id = (int) $class_id;
		if ( ! self::available() || $class_id <= 0 ) {
			return array();
		}
		$lessons = TBT_Notes_DB::get_lessons_for_class( $class_id );
		return is_array( $lessons ) ? $lessons : array();
	}

	/**
	 * Does this user own this class? False on any failure, including Notes
	 * being inactive — an unverifiable class is never an attachable one.
	 *
	 * @param int $user_id  User ID.
	 * @param int $class_id Class ID.
	 * @return bool
	 */
	public static function user_owns_class( $user_id, $class_id ) {
		$user_id  = (int) $user_id;
		$class_id = (int) $class_id;
		if ( ! self::available() || $user_id <= 0 || $class_id <= 0 ) {
			return false;
		}
		$class = TBT_Notes_DB::get_class( $class_id );
		if ( ! is_array( $class ) || ! isset( $class['teacher_id'] ) ) {
			return false;
		}
		return (int) $class['teacher_id'] === $user_id;
	}

	/**
	 * Is this lesson part of this class?
	 *
	 * @param int $lesson_id Lesson ID.
	 * @param int $class_id  Class ID.
	 * @return bool
	 */
	public static function lesson_belongs_to_class( $lesson_id, $class_id ) {
		$lesson_id = (int) $lesson_id;
		$class_id  = (int) $class_id;
		if ( ! self::available() || $lesson_id <= 0 || $class_id <= 0 ) {
			return false;
		}
		$lesson = TBT_Notes_DB::get_lesson( $lesson_id );
		if ( ! is_array( $lesson ) || ! isset( $lesson['class_id'] ) ) {
			return false;
		}
		return (int) $lesson['class_id'] === $class_id;
	}

	/**
	 * Display title for a class, or '' if it cannot be resolved.
	 *
	 * @param int $class_id Class ID.
	 * @return string
	 */
	public static function class_title( $class_id ) {
		$class_id = (int) $class_id;
		if ( ! self::available() || $class_id <= 0 ) {
			return '';
		}
		$class = TBT_Notes_DB::get_class( $class_id );
		return ( is_array( $class ) && isset( $class['title'] ) ) ? (string) $class['title'] : '';
	}

	/**
	 * Display name for a lesson, or ''. Notes calls this field "header".
	 *
	 * @param int $lesson_id Lesson ID.
	 * @return string
	 */
	public static function lesson_title( $lesson_id ) {
		$lesson_id = (int) $lesson_id;
		if ( ! self::available() || $lesson_id <= 0 ) {
			return '';
		}
		$lesson = TBT_Notes_DB::get_lesson( $lesson_id );
		return ( is_array( $lesson ) && isset( $lesson['header'] ) ) ? (string) $lesson['header'] : '';
	}

	/**
	 * Validate a class/lesson pair submitted with a test.
	 *
	 * Unattached is a first-class state. Like a drill, a test may
	 * belong to a class without naming a lesson: the class is the grouping
	 * that matters in the library, and the lesson is a convenience.
	 *
	 * @param int      $user_id   User saving the test.
	 * @param int|null $class_id  Submitted class ID, or null.
	 * @param int|null $lesson_id Submitted lesson ID, or null.
	 * @return array|WP_Error array( 'class_id' => int|null, 'lesson_id' => int|null )
	 */
	public static function validate_attachment( $user_id, $class_id, $lesson_id ) {
		$class_id  = $class_id ? (int) $class_id : null;
		$lesson_id = $lesson_id ? (int) $lesson_id : null;

		if ( null === $class_id ) {
			return array( 'class_id' => null, 'lesson_id' => null );
		}

		if ( ! self::user_owns_class( $user_id, $class_id ) ) {
			return new WP_Error(
				'tbttm_invalid_class',
				__( 'You don\'t have access to that class.', 'tbt-test-maker' ),
				array( 'status' => 403 )
			);
		}

		if ( null !== $lesson_id && ! self::lesson_belongs_to_class( $lesson_id, $class_id ) ) {
			return new WP_Error(
				'tbttm_invalid_lesson',
				__( 'That lesson belongs to a different class.', 'tbt-test-maker' ),
				array( 'status' => 400 )
			);
		}

		return array( 'class_id' => $class_id, 'lesson_id' => $lesson_id );
	}
}
