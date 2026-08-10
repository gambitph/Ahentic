<?php
/**
 * Daily token usage rollup + site-wide spend limits + soft session spend pause helpers.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Usage' ) ) {
	/**
	 * Site-wide daily token stats and runaway spend backstop.
	 */
	class Ahentic_Usage {
		/**
		 * UTC daily rollup (legacy; still bumped for historical UTC consumers).
		 */
		const OPTION_KEY = 'ahentic_token_stats_daily';

		/**
		 * Site-timezone daily totals for enforcement.
		 */
		const OPTION_SITE_TZ = 'ahentic_token_stats_site_tz';

		/**
		 * Limit + runaway lock state.
		 */
		const OPTION_LIMITS = 'ahentic_token_limits';

		const DEFAULT_DAILY_LIMIT = 1000000;

		const RUNAWAY_STREAK_DAYS = 3;

		const CODE_DAILY_LIMIT  = 'ahentic_daily_token_limit';
		const CODE_RUNAWAY_LOCK = 'ahentic_runaway_token_lock';

		/**
		 * Soft per-session cumulative spend pause (not the soft context-fill budget).
		 *
		 * Every SESSION_SOFT_BUDGET_TOKENS of session `tokensUsed`, the orchestrator
		 * Continuable-pauses so a human can Continue or Stop. Site daily limits remain
		 * the hard spend backstop.
		 */
		const SESSION_SOFT_BUDGET_TOKENS = 200000;

		const CODE_SESSION_SOFT_BUDGET = 'ahentic_session_token_budget';

		/**
		 * Default limits option shape (fresh install).
		 *
		 * @return array{daily_limit:int,runaway_locked:bool,streak:int,last_hit_day:string}
		 */
		public static function default_limits_state() {
			return array(
				'daily_limit'     => self::DEFAULT_DAILY_LIMIT,
				'runaway_locked'  => false,
				'streak'          => 0,
				'last_hit_day'    => '',
				'temp_limit'      => 0,
				'temp_limit_day'  => '',
			);
		}

		/**
		 * Normalize raw option / partial state.
		 *
		 * @param mixed $raw Raw option value.
		 * @return array{daily_limit:int,runaway_locked:bool,streak:int,last_hit_day:string,temp_limit:int,temp_limit_day:string}
		 */
		public static function normalize_limits_state( $raw ) {
			$base = self::default_limits_state();
			if ( ! is_array( $raw ) ) {
				return $base;
			}

			$limit = isset( $raw['daily_limit'] ) ? (int) $raw['daily_limit'] : $base['daily_limit'];
			if ( $limit < 1 ) {
				$limit = self::DEFAULT_DAILY_LIMIT;
			}

			$temp_limit = isset( $raw['temp_limit'] ) ? (int) $raw['temp_limit'] : 0;
			if ( $temp_limit < 0 ) {
				$temp_limit = 0;
			}

			return array(
				'daily_limit'    => $limit,
				'runaway_locked' => ! empty( $raw['runaway_locked'] ),
				'streak'         => max( 0, (int) ( isset( $raw['streak'] ) ? $raw['streak'] : 0 ) ),
				'last_hit_day'   => isset( $raw['last_hit_day'] ) ? (string) $raw['last_hit_day'] : '',
				'temp_limit'     => $temp_limit,
				'temp_limit_day' => isset( $raw['temp_limit_day'] ) ? (string) $raw['temp_limit_day'] : '',
			);
		}

		/**
		 * Effective daily cap for a site-tz day (includes same-day temporary boost).
		 *
		 * A same-day temp boost can only raise the cap — never pin it below the
		 * permanent daily_limit (e.g. after the user raises the settings field).
		 *
		 * @param array       $limits    Normalized limits.
		 * @param string|null $today_ymd Optional Y-m-d (defaults to today).
		 * @return int
		 */
		public static function effective_daily_limit( array $limits, $today_ymd = null ) {
			$limits    = self::normalize_limits_state( $limits );
			$today_ymd = null === $today_ymd ? self::site_tz_day() : (string) $today_ymd;
			$permanent = (int) $limits['daily_limit'];
			if (
				'' !== $limits['temp_limit_day']
				&& $limits['temp_limit_day'] === $today_ymd
				&& (int) $limits['temp_limit'] > 0
			) {
				return max( $permanent, (int) $limits['temp_limit'] );
			}
			return $permanent;
		}

		/**
		 * Whether spend may proceed.
		 *
		 * @param array $limits      Normalized limits state.
		 * @param int   $today_total Site-tz total tokens used today.
		 * @return array{ok:bool,code?:string}
		 */
		public static function evaluate_gate( array $limits, $today_total ) {
			$limits = self::normalize_limits_state( $limits );
			if ( ! empty( $limits['runaway_locked'] ) ) {
				return array(
					'ok'   => false,
					'code' => self::CODE_RUNAWAY_LOCK,
				);
			}

			$today_total = max( 0, (int) $today_total );
			$cap         = self::effective_daily_limit( $limits );
			if ( $today_total >= $cap ) {
				return array(
					'ok'   => false,
					'code' => self::CODE_DAILY_LIMIT,
				);
			}

			return array( 'ok' => true );
		}

		/**
		 * Whether a session may keep spending against its soft cumulative budget.
		 *
		 * Pure seam: pause when tokensUsed reaches the next boundary after the
		 * human-acked watermark (0 → 200k → 400k → …).
		 *
		 * @param int $tokens_used    Session cumulative tokensUsed.
		 * @param int $acked_through  Highest soft-budget boundary the user Continued past.
		 * @return array{ok:bool,code?:string,threshold:int}
		 */
		public static function evaluate_session_soft_budget( $tokens_used, $acked_through = 0 ) {
			$tokens_used   = max( 0, (int) $tokens_used );
			$acked_through = max( 0, (int) $acked_through );
			$step          = self::SESSION_SOFT_BUDGET_TOKENS;
			$threshold     = $acked_through + $step;

			if ( $tokens_used >= $threshold ) {
				return array(
					'ok'        => false,
					'code'      => self::CODE_SESSION_SOFT_BUDGET,
					'threshold' => $threshold,
				);
			}

			return array(
				'ok'        => true,
				'threshold' => $threshold,
			);
		}

		/**
		 * Raise the soft-budget watermark to the highest crossed boundary.
		 *
		 * Call on Continue after a session soft-budget pause so the next pause
		 * is one full SESSION_SOFT_BUDGET_TOKENS later.
		 *
		 * @param int $tokens_used Session cumulative tokensUsed at Continue time.
		 * @return int New acked_through value (multiple of SESSION_SOFT_BUDGET_TOKENS).
		 */
		public static function ack_session_soft_budget( $tokens_used ) {
			$tokens_used = max( 0, (int) $tokens_used );
			$step        = self::SESSION_SOFT_BUDGET_TOKENS;
			if ( $step < 1 ) {
				return 0;
			}
			return (int) ( floor( $tokens_used / $step ) * $step );
		}

		/**
		 * User-facing Continuable copy for a session soft-budget pause.
		 *
		 * @param int $threshold Boundary that was hit (e.g. 200000, 400000).
		 * @return string
		 */
		public static function session_soft_budget_message( $threshold ) {
			$threshold = max( self::SESSION_SOFT_BUDGET_TOKENS, (int) $threshold );
			$approx    = self::format_soft_budget_count( $threshold );

			return sprintf(
				/* translators: %s: approximate token count like 200k */
				__( 'This chat has used ~%s tokens. That can mean a lot of work, or a loop. Continue to keep going, or Stop.', 'ahentic' ),
				$approx
			);
		}

		/**
		 * Compact token count for soft-budget copy (200000 → 200k).
		 *
		 * @param int $tokens Token count.
		 * @return string
		 */
		public static function format_soft_budget_count( $tokens ) {
			$tokens = max( 0, (int) $tokens );
			if ( $tokens >= 1000 ) {
				return (string) (int) round( $tokens / 1000 ) . 'k';
			}
			return (string) $tokens;
		}

		/**
		 * Set daily limit without clearing runaway lock.
		 *
		 * Clears an obsolete same-day temp boost when the new permanent limit
		 * already meets or exceeds it (temp is only meaningful as a raise).
		 *
		 * @param array $limits Normalized limits.
		 * @param int   $limit  New daily limit.
		 * @return array
		 */
		public static function with_daily_limit( array $limits, $limit ) {
			$limits = self::normalize_limits_state( $limits );
			$limit  = (int) $limit;
			if ( $limit < 1 ) {
				$limit = self::DEFAULT_DAILY_LIMIT;
			}
			$limits['daily_limit'] = $limit;
			if ( (int) $limits['temp_limit'] > 0 && $limit >= (int) $limits['temp_limit'] ) {
				$limits['temp_limit']     = 0;
				$limits['temp_limit_day'] = '';
			}
			return $limits;
		}

		/**
		 * Raise the effective cap by 10% for today only (does not change permanent daily_limit).
		 *
		 * @param array $limits Normalized limits.
		 * @return array
		 */
		public static function with_temporary_boost_10( array $limits ) {
			$limits  = self::normalize_limits_state( $limits );
			$today   = self::site_tz_day();
			$current = self::effective_daily_limit( $limits, $today );
			$limits['temp_limit']     = max( $current + 1, (int) ceil( $current * 1.1 ) );
			$limits['temp_limit_day'] = $today;
			return $limits;
		}

		/**
		 * Raise the permanent daily limit by 10% and clear any same-day temp boost.
		 *
		 * @param array $limits Normalized limits.
		 * @return array
		 */
		public static function with_permanent_boost_10( array $limits ) {
			$limits  = self::normalize_limits_state( $limits );
			$today   = self::site_tz_day();
			$current = self::effective_daily_limit( $limits, $today );
			$limits['daily_limit']    = max( $current + 1, (int) ceil( $current * 1.1 ) );
			$limits['temp_limit']     = 0;
			$limits['temp_limit_day'] = '';
			return $limits;
		}

		/**
		 * Record a limit-enforcement hit for a site-tz calendar day.
		 *
		 * @param array  $limits        Normalized limits.
		 * @param string $today_ymd     Site-tz Y-m-d for the hit.
		 * @param string $yesterday_ymd Site-tz Y-m-d for the previous calendar day.
		 * @return array Updated limits (may set runaway_locked).
		 */
		public static function apply_enforcement( array $limits, $today_ymd, $yesterday_ymd ) {
			$limits    = self::normalize_limits_state( $limits );
			$today_ymd = (string) $today_ymd;
			if ( '' === $today_ymd ) {
				return $limits;
			}

			if ( $limits['last_hit_day'] === $today_ymd ) {
				return $limits;
			}

			if ( $limits['last_hit_day'] === (string) $yesterday_ymd && $limits['streak'] > 0 ) {
				$limits['streak'] = (int) $limits['streak'] + 1;
			} else {
				$limits['streak'] = 1;
			}

			$limits['last_hit_day'] = $today_ymd;
			if ( $limits['streak'] >= self::RUNAWAY_STREAK_DAYS ) {
				$limits['runaway_locked'] = true;
			}

			return $limits;
		}

		/**
		 * Clear runaway lock and reset streak.
		 *
		 * @param array $limits Normalized limits.
		 * @return array
		 */
		public static function unlock_limits( array $limits ) {
			$limits                   = self::normalize_limits_state( $limits );
			$limits['runaway_locked'] = false;
			$limits['streak']         = 0;
			$limits['last_hit_day']   = '';
			$limits['temp_limit']     = 0;
			$limits['temp_limit_day'] = '';
			return $limits;
		}

		/**
		 * Whether adding $add to $before reaches/exceeds $limit (and was not already over for a fresh trip).
		 *
		 * Used to decide if this spend should fire enforcement. Already-at-cap before still trips
		 * so a race that bumps while blocked still marks the hit-day once.
		 *
		 * @param int $before Total before this spend.
		 * @param int $add    Tokens added.
		 * @param int $limit  Daily limit.
		 * @return bool
		 */
		public static function spend_crosses_limit( $before, $add, $limit ) {
			$before = max( 0, (int) $before );
			$add    = max( 0, (int) $add );
			$limit  = max( 1, (int) $limit );
			$after  = $before + $add;
			return $after >= $limit;
		}

		/**
		 * User-facing message for a limit code.
		 *
		 * @param string $code Error code.
		 * @return string
		 */
		public static function message_for_code( $code ) {
			$settings = self::settings_url();
			if ( self::CODE_RUNAWAY_LOCK === $code ) {
				return sprintf(
					/* translators: %s: settings URL */
					__( 'Token usage hit the daily limit 3 days in a row, so Ahentic paused prompts to protect your spend. Open Settings (%s) and use “Acknowledge & unlock” if you want to continue.', 'ahentic' ),
					$settings
				);
			}

			return sprintf(
				/* translators: %s: settings URL */
				__( 'Daily token limit reached. Ongoing agent runs were stopped. Increase the limit in Settings (%s) to continue today.', 'ahentic' ),
				$settings
			);
		}

		/**
		 * Settings page URL.
		 *
		 * @return string
		 */
		public static function settings_url() {
			$slug = class_exists( 'Ahentic_Admin' ) ? Ahentic_Admin::SETTINGS_SLUG : 'ahentic';
			if ( function_exists( 'admin_url' ) ) {
				return admin_url( 'options-general.php?page=' . $slug );
			}
			return 'options-general.php?page=' . $slug;
		}

		/**
		 * Site-timezone calendar day Y-m-d.
		 *
		 * @param int|null $timestamp Optional unix timestamp.
		 * @return string
		 */
		public static function site_tz_day( $timestamp = null ) {
			$timestamp = null === $timestamp ? time() : (int) $timestamp;
			if ( function_exists( 'wp_timezone' ) ) {
				$dt = new DateTimeImmutable( '@' . $timestamp );
				$dt = $dt->setTimezone( wp_timezone() );
				return $dt->format( 'Y-m-d' );
			}
			return gmdate( 'Y-m-d', $timestamp );
		}

		/**
		 * Previous site-tz calendar day relative to $today_ymd.
		 *
		 * @param string $today_ymd Y-m-d.
		 * @return string
		 */
		public static function site_tz_yesterday( $today_ymd ) {
			try {
				$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
				$dt = new DateTimeImmutable( $today_ymd . ' 12:00:00', $tz );
				return $dt->modify( '-1 day' )->format( 'Y-m-d' );
			} catch ( Exception $e ) {
				$day_seconds = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
				return gmdate( 'Y-m-d', time() - $day_seconds );
			}
		}

		/**
		 * Load limits option.
		 *
		 * @return array
		 */
		public static function get_limits_state() {
			return self::normalize_limits_state( get_option( self::OPTION_LIMITS, null ) );
		}

		/**
		 * Persist limits option.
		 *
		 * @param array $limits Limits state.
		 */
		public static function save_limits_state( array $limits ) {
			update_option( self::OPTION_LIMITS, self::normalize_limits_state( $limits ), false );
		}

		/**
		 * Daily limit value.
		 *
		 * @return int
		 */
		public static function get_daily_limit() {
			$state = self::get_limits_state();
			return (int) $state['daily_limit'];
		}

		/**
		 * Unlock runaway protection.
		 */
		public static function unlock_runaway() {
			self::save_limits_state( self::unlock_limits( self::get_limits_state() ) );
		}

		/**
		 * Apply a +10% temporary (today only) boost and persist.
		 */
		public static function boost_temporary_10() {
			self::save_limits_state( self::with_temporary_boost_10( self::get_limits_state() ) );
		}

		/**
		 * Apply a +10% permanent boost and persist.
		 */
		public static function boost_permanent_10() {
			self::save_limits_state( self::with_permanent_boost_10( self::get_limits_state() ) );
		}

		/**
		 * Today's site-tz total tokens.
		 *
		 * @return int
		 */
		public static function get_today_total() {
			$day   = self::site_tz_day();
			$stats = get_option( self::OPTION_SITE_TZ, array() );
			if ( ! is_array( $stats ) || ! isset( $stats[ $day ] ) || ! is_array( $stats[ $day ] ) ) {
				return 0;
			}
			return isset( $stats[ $day ]['total'] ) ? (int) $stats[ $day ]['total'] : 0;
		}

		/**
		 * Status payload for settings UI.
		 *
		 * @return array
		 */
		public static function get_status() {
			$limits = self::get_limits_state();
			$used   = self::get_today_total();
			$eff    = self::effective_daily_limit( $limits );
			$gate   = self::evaluate_gate( $limits, $used );
			$temp   = (
				'' !== $limits['temp_limit_day']
				&& $limits['temp_limit_day'] === self::site_tz_day()
				&& (int) $limits['temp_limit'] > 0
			);
			return array(
				'daily_limit'     => (int) $limits['daily_limit'],
				'effective_limit' => $eff,
				'temp_boost'      => $temp,
				'today_used'      => $used,
				'today'           => self::site_tz_day(),
				'runaway_locked'  => ! empty( $limits['runaway_locked'] ),
				'streak'          => (int) $limits['streak'],
				'blocked'         => empty( $gate['ok'] ),
				'block_code'      => empty( $gate['ok'] ) && isset( $gate['code'] ) ? $gate['code'] : '',
			);
		}

		/**
		 * Pre-flight: may this site spend more tokens?
		 *
		 * @return true|\WP_Error
		 */
		public static function assert_may_spend() {
			$gate = self::evaluate_gate( self::get_limits_state(), self::get_today_total() );
			if ( ! empty( $gate['ok'] ) ) {
				return true;
			}
			$code = isset( $gate['code'] ) ? $gate['code'] : self::CODE_DAILY_LIMIT;
			self::note_enforcement( $code );
			return new WP_Error(
				$code,
				self::message_for_code( $code ),
				array( 'status' => 429 )
			);
		}

		/**
		 * Quiet gate check (no hit-day bookkeeping) — e.g. skip summary LLM after a trip.
		 *
		 * @return true|\WP_Error
		 */
		public static function check_may_spend() {
			$gate = self::evaluate_gate( self::get_limits_state(), self::get_today_total() );
			if ( ! empty( $gate['ok'] ) ) {
				return true;
			}
			$code = isset( $gate['code'] ) ? $gate['code'] : self::CODE_DAILY_LIMIT;
			return new WP_Error(
				$code,
				self::message_for_code( $code ),
				array( 'status' => 429 )
			);
		}

		/**
		 * Record a hit-day when enforcement fires (spend trip or refuse). Fires action once per new hit.
		 *
		 * @param string $preferred_code Hint when already runaway-locked.
		 */
		public static function note_enforcement( $preferred_code = '' ) {
			$day    = self::site_tz_day();
			$limits = self::get_limits_state();
			$updated = self::apply_enforcement( $limits, $day, self::site_tz_yesterday( $day ) );
			$is_new_hit = ( $limits['last_hit_day'] !== $updated['last_hit_day'] )
				|| ( empty( $limits['runaway_locked'] ) && ! empty( $updated['runaway_locked'] ) );
			if ( ! $is_new_hit ) {
				return;
			}

			self::save_limits_state( $updated );

			$code = ! empty( $updated['runaway_locked'] )
				? self::CODE_RUNAWAY_LOCK
				: self::CODE_DAILY_LIMIT;
			if ( self::CODE_RUNAWAY_LOCK === $preferred_code ) {
				$code = self::CODE_RUNAWAY_LOCK;
			}

			/**
			 * Fires when the daily token limit is enforced (or runaway engages).
			 *
			 * @param string $code Ahentic_Usage::CODE_*.
			 */
			do_action( 'ahentic_token_limit_enforced', $code );
		}

		/**
		 * Bump today's rollup (UTC graph + site-tz enforcement) and trip limits if needed.
		 *
		 * @param int $in    Prompt tokens.
		 * @param int $out   Completion tokens.
		 * @param int $total Total tokens.
		 */
		public static function bump_daily( $in, $out, $total ) {
			$in    = max( 0, (int) $in );
			$out   = max( 0, (int) $out );
			$total = max( 0, (int) $total );

			self::bump_option_day( self::OPTION_KEY, gmdate( 'Y-m-d' ), $in, $out, $total );

			$before = self::get_today_total();
			$day    = self::site_tz_day();
			self::bump_option_day( self::OPTION_SITE_TZ, $day, $in, $out, $total );

			$limits = self::get_limits_state();
			if ( ! self::spend_crosses_limit( $before, $total, self::effective_daily_limit( $limits ) ) ) {
				return;
			}

			self::note_enforcement(
				! empty( $limits['runaway_locked'] ) ? self::CODE_RUNAWAY_LOCK : self::CODE_DAILY_LIMIT
			);
		}

		/**
		 * Contiguous Y-m-d keys ending on a day (oldest first).
		 *
		 * @param string $end_ymd End day (inclusive).
		 * @param int    $count   Number of days.
		 * @return string[]
		 */
		public static function day_keys_ending_on( $end_ymd, $count ) {
			$count = max( 1, min( 120, (int) $count ) );
			$keys  = array();
			$day   = (string) $end_ymd;
			for ( $i = 0; $i < $count; $i++ ) {
				array_unshift( $keys, $day );
				if ( $i < $count - 1 ) {
					$day = self::site_tz_yesterday( $day );
				}
			}
			return $keys;
		}

		/**
		 * Locale-aware short label for a series day (e.g. "Aug 7").
		 *
		 * @param string $ymd Y-m-d.
		 * @return string
		 */
		public static function format_series_day_label( $ymd ) {
			try {
				$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
				$dt = new DateTimeImmutable( $ymd . ' 12:00:00', $tz );
				if ( function_exists( 'wp_date' ) ) {
					return wp_date( 'M j', $dt->getTimestamp(), $tz );
				}
				if ( function_exists( 'date_i18n' ) ) {
					return date_i18n( 'M j', $dt->getTimestamp() );
				}
				return $dt->format( 'M j' );
			} catch ( Exception $e ) {
				return (string) $ymd;
			}
		}

		/**
		 * Build a zero-filled series from a stats map and day keys.
		 *
		 * @param array    $stats  Day => {in,out,total}.
		 * @param string[] $keys   Y-m-d keys oldest first.
		 * @param string[] $labels Parallel display labels (optional).
		 * @return array<int,array{date:string,label:string,in:int,out:int,total:int}>
		 */
		public static function build_series_from_stats( array $stats, array $keys, array $labels = array() ) {
			$series = array();
			foreach ( $keys as $i => $day ) {
				$row      = isset( $stats[ $day ] ) && is_array( $stats[ $day ] ) ? $stats[ $day ] : array();
				$series[] = array(
					'date'  => (string) $day,
					'label' => isset( $labels[ $i ] ) ? (string) $labels[ $i ] : (string) $day,
					'in'    => isset( $row['in'] ) ? (int) $row['in'] : 0,
					'out'   => isset( $row['out'] ) ? (int) $row['out'] : 0,
					'total' => isset( $row['total'] ) ? (int) $row['total'] : 0,
				);
			}
			return $series;
		}

		/**
		 * Usage percent for settings UI (0–100). Two decimals when in (0, 1).
		 *
		 * @param int $used  Tokens used.
		 * @param int $limit Denominator.
		 * @return int|float
		 */
		public static function format_usage_pct( $used, $limit ) {
			$used  = max( 0, (int) $used );
			$limit = (int) $limit;
			if ( $limit <= 0 ) {
				return 0;
			}
			$raw     = ( $used / $limit ) * 100;
			$clamped = max( 0.0, min( 100.0, $raw ) );
			if ( $clamped > 0 && $clamped < 1 ) {
				return round( $clamped, 2 );
			}
			return (int) round( $clamped );
		}

		/**
		 * Denominator for the live usage bar (input vs effective temp boost).
		 *
		 * @param int $input_limit     Value in the settings field.
		 * @param int $effective_limit Today's effective cap.
		 * @return int
		 */
		public static function live_bar_denominator( $input_limit, $effective_limit ) {
			$input_limit = (int) $input_limit;
			if ( $input_limit <= 0 ) {
				return 0;
			}
			return max( $input_limit, max( 0, (int) $effective_limit ) );
		}

		/**
		 * Bar fill width percent (never collapses to 0 while there is usage).
		 *
		 * @param int|float $pct  Clamped usage percent.
		 * @param int       $used Tokens used.
		 * @return float
		 */
		public static function usage_bar_width_pct( $pct, $used ) {
			$pct  = (float) $pct;
			$used = (int) $used;
			if ( $used <= 0 || $pct <= 0 ) {
				return 0.0;
			}
			return max( $pct, 0.5 );
		}

		/**
		 * Series for REST / settings graphs (site-timezone days).
		 *
		 * @param int $days Number of days.
		 * @return array<int,array{date:string,label:string,in:int,out:int,total:int}>
		 */
		public static function get_series( $days = 30 ) {
			$days  = max( 1, min( 120, (int) $days ) );
			$stats = get_option( self::OPTION_SITE_TZ, array() );
			if ( ! is_array( $stats ) ) {
				$stats = array();
			}

			$keys   = self::day_keys_ending_on( self::site_tz_day(), $days );
			$labels = array();
			foreach ( $keys as $key ) {
				$labels[] = self::format_series_day_label( $key );
			}

			return self::build_series_from_stats( $stats, $keys, $labels );
		}

		/**
		 * Increment one day row in a stats option.
		 *
		 * @param string $option Option name.
		 * @param string $day    Y-m-d key.
		 * @param int    $in     In tokens.
		 * @param int    $out    Out tokens.
		 * @param int    $total  Total tokens.
		 */
		private static function bump_option_day( $option, $day, $in, $out, $total ) {
			$stats = get_option( $option, array() );
			if ( ! is_array( $stats ) ) {
				$stats = array();
			}

			if ( ! isset( $stats[ $day ] ) || ! is_array( $stats[ $day ] ) ) {
				$stats[ $day ] = array(
					'in'    => 0,
					'out'   => 0,
					'total' => 0,
				);
			}

			$stats[ $day ]['in']    += $in;
			$stats[ $day ]['out']   += $out;
			$stats[ $day ]['total'] += $total;

			if ( count( $stats ) > 120 ) {
				ksort( $stats );
				$stats = array_slice( $stats, -120, null, true );
			}

			update_option( $option, $stats, false );
		}
	}
}
