<?php
/**
 * Curated WordPress playbooks for the Ahentic agent loop.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Playbooks' ) ) {
	/**
	 * Loads playbook JSON and exposes ahentic/get-wordpress-guidance.
	 */
	class Ahentic_Playbooks {
		const GUIDANCE = 'ahentic/get-wordpress-guidance';

		const MAX_MATCHES = 2;
		const MAX_GUIDANCE_CHARS = 6000;

		/**
		 * Ability names provided by this module.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array( self::GUIDANCE );
		}

		/**
		 * Register category.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			wp_register_ability_category(
				'ahentic-guidance',
				array(
					'label'       => __( 'Ahentic Guidance', 'ahentic' ),
					'description' => __( 'Curated WordPress best-practice playbooks for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register abilities.
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			wp_register_ability(
				self::GUIDANCE,
				array(
					'label'               => __( 'Get WordPress guidance', 'ahentic' ),
					'description'         => __( 'Returns a curated Ahentic playbook for WordPress site-operator best practices (plugins, SEO, cleanup, pre-launch, editor vs server, post title vs H1, custom code). Pass topic (playbook id) or query (short phrase). Omit both to list available playbooks.', 'ahentic' ),
					'category'            => 'ahentic-guidance',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'topic' => array(
								'type'        => 'string',
								'description' => __( 'Playbook id (e.g. plugin-hygiene, seo-decisioning) or a short topic phrase.', 'ahentic' ),
							),
							'query' => array(
								'type'        => 'string',
								'description' => __( 'Free-text search across playbook titles, when_to_use, and triggers (e.g. “add google analytics”).', 'ahentic' ),
							),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( __CLASS__, 'execute_get_guidance' ),
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
					'meta'                => array(
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
						'show_in_rest' => false,
					),
				)
			);
		}

		/**
		 * Execute get-wordpress-guidance (also used as Abilities API fallback).
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Input.
		 * @return array|\WP_Error
		 */
		public static function execute( $name, $input = array() ) {
			if ( self::GUIDANCE !== (string) $name ) {
				return new WP_Error(
					'ahentic_ability_unknown',
					__( 'Unknown playbook ability.', 'ahentic' ),
					array( 'status' => 404 )
				);
			}
			return self::execute_get_guidance( $input );
		}

		/**
		 * Resolve catalog and/or matching playbooks.
		 *
		 * @param array $input Input args.
		 * @return array
		 */
		public static function execute_get_guidance( $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$topic = isset( $input['topic'] ) ? trim( (string) $input['topic'] ) : '';
			$query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';

			$index = self::load_index();
			$catalog = self::format_catalog( $index );

			if ( '' === $topic && '' === $query ) {
				return array(
					'ok'       => true,
					'mode'     => 'catalog',
					'catalog'  => $catalog,
					'hint'     => 'Pass topic (playbook id) or query to load full guidance. Wave-1 ids: plugin-hygiene, custom-code-snippets, pre-launch-gaps, seo-decisioning, safe-cleanup, editor-vs-server, editor-leave-canvas, editor-wrap-blocks, web-image-fit, post-title-headings.',
					'playbooks'=> array(),
				);
			}

			$search = '' !== $query ? $query : $topic;
			$ids    = self::resolve_playbook_ids( $search, $index );

			$playbooks = array();
			foreach ( $ids as $id ) {
				$pb = self::get_playbook( $id );
				if ( empty( $pb ) ) {
					continue;
				}
				$playbooks[] = self::format_playbook_payload( $pb );
			}

			if ( empty( $playbooks ) ) {
				return array(
					'ok'       => true,
					'mode'     => 'no_match',
					'catalog'  => $catalog,
					'searched' => $search,
					'hint'     => 'No playbook matched. Pick an id from catalog or try a shorter query.',
					'playbooks'=> array(),
				);
			}

			return array(
				'ok'        => true,
				'mode'      => 'guidance',
				'searched'  => $search,
				'playbooks' => $playbooks,
				'hint'      => 'Follow principles and steps; prefer related_abilities; avoid anti_patterns. Then use site tools — do not invent site facts from this playbook alone.',
			);
		}

		/**
		 * Absolute path to playbooks data directory.
		 *
		 * @return string
		 */
		private static function data_dir() {
			return plugin_dir_path( AHENTIC_FILE ) . 'src/data/playbooks/';
		}

		/**
		 * Load index.json (cached per request).
		 *
		 * @return array<string, mixed>
		 */
		public static function load_index() {
			static $cached = null;
			if ( null !== $cached ) {
				return $cached;
			}
			$cached = array( 'playbooks' => array() );
			$path   = self::data_dir() . 'index.json';
			$data   = self::read_json_file( $path );
			if ( is_array( $data ) ) {
				$cached = $data;
			}
			return $cached;
		}

		/**
		 * Load one playbook by id.
		 *
		 * @param string $id Playbook id.
		 * @return array<string, mixed>
		 */
		public static function get_playbook( $id ) {
			$id = sanitize_title( (string) $id );
			if ( '' === $id ) {
				return array();
			}

			static $cache = array();
			if ( isset( $cache[ $id ] ) ) {
				return $cache[ $id ];
			}

			$index = self::load_index();
			$file  = $id . '.json';
			if ( ! empty( $index['playbooks'] ) && is_array( $index['playbooks'] ) ) {
				foreach ( $index['playbooks'] as $entry ) {
					if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
						continue;
					}
					if ( (string) $entry['id'] === $id && ! empty( $entry['file'] ) ) {
						$file = (string) $entry['file'];
						break;
					}
				}
			}

			// Prevent path traversal — only basename under data dir.
			$file = basename( $file );
			$path = self::data_dir() . $file;
			$data = self::read_json_file( $path );
			if ( ! is_array( $data ) || empty( $data['id'] ) ) {
				$cache[ $id ] = array();
				return $cache[ $id ];
			}
			$cache[ $id ] = $data;
			return $cache[ $id ];
		}

		/**
		 * @param string $path Absolute path.
		 * @return array<string, mixed>|null
		 */
		private static function read_json_file( $path ) {
			if ( ! is_readable( $path ) ) {
				return null;
			}
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin data file.
			if ( false === $raw || '' === $raw ) {
				return null;
			}
			$data = json_decode( $raw, true );
			return is_array( $data ) ? $data : null;
		}

		/**
		 * Catalog entries for the model.
		 *
		 * @param array $index Index data.
		 * @return array<int, array<string, string>>
		 */
		private static function format_catalog( $index ) {
			$out = array();
			if ( empty( $index['playbooks'] ) || ! is_array( $index['playbooks'] ) ) {
				return $out;
			}
			foreach ( $index['playbooks'] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
					continue;
				}
				$out[] = array(
					'id'          => (string) $entry['id'],
					'title'       => isset( $entry['title'] ) ? (string) $entry['title'] : (string) $entry['id'],
					'when_to_use' => isset( $entry['when_to_use'] ) ? (string) $entry['when_to_use'] : '',
				);
			}
			return $out;
		}

		/**
		 * Resolve playbook ids from topic/query.
		 *
		 * @param string $search Search string.
		 * @param array  $index  Index.
		 * @return string[]
		 */
		private static function resolve_playbook_ids( $search, $index ) {
			$search = trim( (string) $search );
			if ( '' === $search ) {
				return array();
			}

			$slug = sanitize_title( $search );
			$known = array();
			if ( ! empty( $index['playbooks'] ) && is_array( $index['playbooks'] ) ) {
				foreach ( $index['playbooks'] as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
						$known[] = (string) $entry['id'];
					}
				}
			}

			if ( in_array( $slug, $known, true ) ) {
				return array( $slug );
			}
			// Allow underscores / spaces normalized to the same id.
			if ( in_array( str_replace( '_', '-', $slug ), $known, true ) ) {
				return array( str_replace( '_', '-', $slug ) );
			}

			$needle = strtolower( $search );
			$scored = array();

			foreach ( $known as $id ) {
				$pb = self::get_playbook( $id );
				if ( empty( $pb ) ) {
					continue;
				}
				$score = self::score_playbook( $pb, $needle );
				if ( $score > 0 ) {
					$scored[ $id ] = $score;
				}
			}

			if ( empty( $scored ) ) {
				return array();
			}

			arsort( $scored, SORT_NUMERIC );
			return array_slice( array_keys( $scored ), 0, self::MAX_MATCHES );
		}

		/**
		 * Simple keyword score.
		 *
		 * @param array  $pb     Playbook.
		 * @param string $needle Lowercase query.
		 * @return int
		 */
		private static function score_playbook( $pb, $needle ) {
			$score = 0;
			$id    = isset( $pb['id'] ) ? strtolower( (string) $pb['id'] ) : '';
			$title = isset( $pb['title'] ) ? strtolower( (string) $pb['title'] ) : '';
			$when  = isset( $pb['when_to_use'] ) ? strtolower( (string) $pb['when_to_use'] ) : '';

			if ( $id && false !== strpos( $needle, $id ) ) {
				$score += 50;
			}
			if ( $id && false !== strpos( $id, $needle ) ) {
				$score += 40;
			}
			if ( $title && false !== strpos( $title, $needle ) ) {
				$score += 30;
			}
			if ( $when && false !== strpos( $when, $needle ) ) {
				$score += 20;
			}

			$tokens = preg_split( '/[\s,\/]+/', $needle );
			$tokens = is_array( $tokens ) ? $tokens : array();

			$triggers = isset( $pb['triggers'] ) && is_array( $pb['triggers'] ) ? $pb['triggers'] : array();
			foreach ( $triggers as $trigger ) {
				$t = strtolower( (string) $trigger );
				if ( '' === $t ) {
					continue;
				}
				if ( false !== strpos( $needle, $t ) || false !== strpos( $t, $needle ) ) {
					$score += 25;
					continue;
				}
				foreach ( $tokens as $tok ) {
					$tok = trim( (string) $tok );
					if ( strlen( $tok ) < 3 ) {
						continue;
					}
					if ( false !== strpos( $t, $tok ) ) {
						$score += 5;
					}
				}
			}

			foreach ( $tokens as $tok ) {
				$tok = trim( (string) $tok );
				if ( strlen( $tok ) < 4 ) {
					continue;
				}
				if ( false !== strpos( $when, $tok ) || false !== strpos( $title, $tok ) ) {
					$score += 3;
				}
			}

			return $score;
		}

		/**
		 * Payload for the model (capped).
		 *
		 * @param array $pb Playbook.
		 * @return array<string, mixed>
		 */
		private static function format_playbook_payload( $pb ) {
			$guidance = self::format_guidance_text( $pb );
			if ( strlen( $guidance ) > self::MAX_GUIDANCE_CHARS ) {
				$guidance = substr( $guidance, 0, self::MAX_GUIDANCE_CHARS - 1 ) . '…';
			}

			return array(
				'id'                => isset( $pb['id'] ) ? (string) $pb['id'] : '',
				'title'             => isset( $pb['title'] ) ? (string) $pb['title'] : '',
				'when_to_use'       => isset( $pb['when_to_use'] ) ? (string) $pb['when_to_use'] : '',
				'related_abilities' => isset( $pb['related_abilities'] ) && is_array( $pb['related_abilities'] )
					? array_values( array_map( 'strval', $pb['related_abilities'] ) )
					: array(),
				'guidance'          => $guidance,
				'principles'        => isset( $pb['principles'] ) && is_array( $pb['principles'] ) ? array_values( $pb['principles'] ) : array(),
				'steps'             => isset( $pb['steps'] ) && is_array( $pb['steps'] ) ? array_values( $pb['steps'] ) : array(),
				'anti_patterns'     => isset( $pb['anti_patterns'] ) && is_array( $pb['anti_patterns'] ) ? array_values( $pb['anti_patterns'] ) : array(),
			);
		}

		/**
		 * Compact text block for the model.
		 *
		 * @param array $pb Playbook.
		 * @return string
		 */
		public static function format_guidance_text( $pb ) {
			$lines   = array();
			$lines[] = 'Playbook: ' . ( isset( $pb['title'] ) ? (string) $pb['title'] : '' ) . ' (' . ( isset( $pb['id'] ) ? (string) $pb['id'] : '' ) . ')';
			if ( ! empty( $pb['when_to_use'] ) ) {
				$lines[] = 'When: ' . (string) $pb['when_to_use'];
			}
			if ( ! empty( $pb['principles'] ) && is_array( $pb['principles'] ) ) {
				$lines[] = 'Principles:';
				foreach ( $pb['principles'] as $p ) {
					$lines[] = '- ' . (string) $p;
				}
			}
			if ( ! empty( $pb['steps'] ) && is_array( $pb['steps'] ) ) {
				$lines[] = 'Steps:';
				$i = 1;
				foreach ( $pb['steps'] as $s ) {
					$lines[] = $i . '. ' . (string) $s;
					++$i;
				}
			}
			if ( ! empty( $pb['anti_patterns'] ) && is_array( $pb['anti_patterns'] ) ) {
				$lines[] = 'Avoid:';
				foreach ( $pb['anti_patterns'] as $a ) {
					$lines[] = '- ' . (string) $a;
				}
			}
			if ( ! empty( $pb['related_abilities'] ) && is_array( $pb['related_abilities'] ) ) {
				$lines[] = 'Related abilities: ' . implode( ', ', array_map( 'strval', $pb['related_abilities'] ) );
			}
			return implode( "\n", $lines );
		}

		/**
		 * @param string $name Ability name.
		 * @return string
		 */
		public static function progress_label( $name ) {
			if ( self::GUIDANCE === (string) $name ) {
				return __( 'Loading WordPress guidance…', 'ahentic' );
			}
			return '';
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Playbooks', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Playbooks', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Playbooks' );
}
