<?php
/**
 * Daily token usage rollup.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Usage' ) ) {
	/**
	 * Site-wide daily token stats for settings graphs.
	 */
	class Ahentic_Usage {
		const OPTION_KEY = 'ahentic_token_stats_daily';

		/**
		 * Bump today's rollup.
		 *
		 * @param int $in    Prompt tokens.
		 * @param int $out   Completion tokens.
		 * @param int $total Total tokens.
		 */
		public static function bump_daily( $in, $out, $total ) {
			$day   = gmdate( 'Y-m-d' );
			$stats = get_option( self::OPTION_KEY, array() );
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

			$stats[ $day ]['in']    += max( 0, (int) $in );
			$stats[ $day ]['out']   += max( 0, (int) $out );
			$stats[ $day ]['total'] += max( 0, (int) $total );

			if ( count( $stats ) > 120 ) {
				ksort( $stats );
				$stats = array_slice( $stats, -120, null, true );
			}

			update_option( self::OPTION_KEY, $stats, false );
		}

		/**
		 * Series for REST / settings.
		 *
		 * @param int $days Number of days.
		 * @return array
		 */
		public static function get_series( $days = 30 ) {
			$days  = max( 1, min( 120, (int) $days ) );
			$stats = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $stats ) ) {
				$stats = array();
			}

			$series = array();
			for ( $i = $days - 1; $i >= 0; $i-- ) {
				$day = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
				$row = isset( $stats[ $day ] ) && is_array( $stats[ $day ] ) ? $stats[ $day ] : array();
				$series[] = array(
					'date'  => $day,
					'in'    => isset( $row['in'] ) ? (int) $row['in'] : 0,
					'out'   => isset( $row['out'] ) ? (int) $row['out'] : 0,
					'total' => isset( $row['total'] ) ? (int) $row['total'] : 0,
				);
			}

			return $series;
		}
	}
}
