<?php
/**
 * Browser (JS) abilities catalog — run in the sidebar, not in PHP.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ahentic_Abilities_Browser' ) ) {
	/**
	 * Client-runtime abilities for the agent loop.
	 */
	class Ahentic_Abilities_Browser {
		const CURRENT_PAGE              = 'ahentic-browser/get-current-page';
		const VISIBLE_PAGE              = 'ahentic-browser/get-visible-page';
		const EDITOR_STATE              = 'ahentic-browser/get-editor-state';
		const GET_BLOCKS                = 'ahentic-browser/get-blocks';
		const GET_SELECTION             = 'ahentic-browser/get-selection';
		const GET_BLOCK_TYPE            = 'ahentic-browser/get-block-type';
		const LIST_BLOCK_TYPES          = 'ahentic-browser/list-block-types';
		const FOCUS_BLOCK               = 'ahentic-browser/focus-block';
		const UPDATE_BLOCK_ATTRIBUTES   = 'ahentic-browser/update-block-attributes';
		const REPLACE_BLOCKS            = 'ahentic-browser/replace-blocks';
		const SET_BLOCKS                = 'ahentic-browser/set-blocks';
		const INSERT_BLOCKS             = 'ahentic-browser/insert-blocks';
		const DUPLICATE_BLOCKS          = 'ahentic-browser/duplicate-blocks';
		const DELETE_BLOCKS             = 'ahentic-browser/delete-blocks';
		const MOVE_BLOCKS               = 'ahentic-browser/move-blocks';
		const NORMALIZE_BLOCK_STYLES    = 'ahentic-browser/normalize-block-styles';
		const RESTYLE_BLOCKS_TO_PALETTE = 'ahentic-browser/restyle-blocks-to-palette';
		const CONVERT_BLOCKS            = 'ahentic-browser/convert-blocks';
		const AUDIT_ACCESSIBILITY       = 'ahentic-browser/audit-accessibility';
		const UPDATE_POST_TITLE         = 'ahentic-browser/update-post-title';
		const UPDATE_POST_DOCUMENT      = 'ahentic-browser/update-post-document';
		const SET_FEATURED_IMAGE        = 'ahentic-browser/set-featured-image';
		const SET_POST_TERMS            = 'ahentic-browser/set-post-terms';
		const SAVE_POST                 = 'ahentic-browser/save-post';
		const FILL_FIELDS               = 'ahentic-browser/fill-fields';

		/**
		 * Single policy catalog: drives names / write / HITL / non_preallowable / progress / summary.
		 *
		 * @return array<string, array{write?:bool, hitl?:bool, non_preallowable?:bool, progress:string, summary:string, hitl_summary?:string}>
		 */
		private static function catalog() {
			return array(
				self::CURRENT_PAGE              => array(
					'page_only' => true,
					'progress'  => __( 'Reading the current page…', 'ahentic' ),
					'summary'   => __( 'Read the current browser page', 'ahentic' ),
				),
				self::VISIBLE_PAGE              => array(
					'page_only' => true,
					'progress'  => __( 'Reading what is on the screen…', 'ahentic' ),
					'summary'   => __( 'Read what is visible on the page', 'ahentic' ),
				),
				self::EDITOR_STATE              => array(
					'progress' => __( 'Reading the block editor…', 'ahentic' ),
					'summary'  => __( 'Read the block editor state', 'ahentic' ),
				),
				self::GET_BLOCKS                => array(
					'progress' => __( 'Reading editor blocks…', 'ahentic' ),
					'summary'  => __( 'Read the editor block tree', 'ahentic' ),
				),
				self::GET_SELECTION             => array(
					'progress' => __( 'Reading the editor selection…', 'ahentic' ),
					'summary'  => __( 'Read the editor selection', 'ahentic' ),
				),
				self::GET_BLOCK_TYPE            => array(
					'progress' => __( 'Reading block type schema…', 'ahentic' ),
					'summary'  => __( 'Read a block type schema', 'ahentic' ),
				),
				self::LIST_BLOCK_TYPES          => array(
					'progress' => __( 'Listing block types…', 'ahentic' ),
					'summary'  => __( 'List registered block types', 'ahentic' ),
				),
				self::FOCUS_BLOCK               => array(
					'write'    => true,
					'progress' => __( 'Focusing a block…', 'ahentic' ),
					'summary'  => __( 'Focus a block in the editor', 'ahentic' ),
				),
				self::UPDATE_BLOCK_ATTRIBUTES   => array(
					'write'    => true,
					'progress' => __( 'Updating block attributes…', 'ahentic' ),
					'summary'  => __( 'Update block attributes', 'ahentic' ),
				),
				self::REPLACE_BLOCKS            => array(
					'write'    => true,
					'progress' => __( 'Replacing blocks…', 'ahentic' ),
					'summary'  => __( 'Replace blocks in the editor', 'ahentic' ),
				),
				self::SET_BLOCKS                => array(
					'write'    => true,
					'progress' => __( 'Setting editor blocks…', 'ahentic' ),
					'summary'  => __( 'Set the editor block tree', 'ahentic' ),
				),
				self::INSERT_BLOCKS             => array(
					'write'    => true,
					'progress' => __( 'Inserting blocks…', 'ahentic' ),
					'summary'  => __( 'Insert blocks in the editor', 'ahentic' ),
				),
				self::DUPLICATE_BLOCKS          => array(
					'write'    => true,
					'progress' => __( 'Duplicating blocks…', 'ahentic' ),
					'summary'  => __( 'Duplicate blocks', 'ahentic' ),
				),
				self::DELETE_BLOCKS             => array(
					'write'    => true,
					'progress' => __( 'Deleting blocks…', 'ahentic' ),
					'summary'  => __( 'Delete blocks', 'ahentic' ),
				),
				self::MOVE_BLOCKS               => array(
					'write'    => true,
					'progress' => __( 'Moving blocks…', 'ahentic' ),
					'summary'  => __( 'Move blocks', 'ahentic' ),
				),
				self::NORMALIZE_BLOCK_STYLES    => array(
					'write'    => true,
					'progress' => __( 'Stripping custom block styles…', 'ahentic' ),
					'summary'  => __( 'Strip custom block styles', 'ahentic' ),
				),
				self::RESTYLE_BLOCKS_TO_PALETTE => array(
					'write'    => true,
					'progress' => __( 'Restyling blocks to palette…', 'ahentic' ),
					'summary'  => __( 'Restyle blocks to a color palette', 'ahentic' ),
				),
				self::CONVERT_BLOCKS            => array(
					'write'        => true,
					'hitl'         => true,
					'progress'     => __( 'Converting blocks…', 'ahentic' ),
					'summary'      => __( 'Convert blocks between libraries', 'ahentic' ),
					'hitl_summary' => __( 'Convert blocks toward the requested target library or block type', 'ahentic' ),
				),
				self::AUDIT_ACCESSIBILITY       => array(
					'progress' => __( 'Auditing editor accessibility…', 'ahentic' ),
					'summary'  => __( 'Audit editor accessibility', 'ahentic' ),
				),
				self::UPDATE_POST_TITLE         => array(
					'write'    => true,
					'progress' => __( 'Updating the editor title…', 'ahentic' ),
					'summary'  => __( 'Update the editor post title', 'ahentic' ),
				),
				self::UPDATE_POST_DOCUMENT      => array(
					'write'    => true,
					'progress' => __( 'Updating the editor document…', 'ahentic' ),
					'summary'  => __( 'Update the editor post document fields', 'ahentic' ),
				),
				self::SET_FEATURED_IMAGE        => array(
					'write'    => true,
					'progress' => __( 'Updating the featured image…', 'ahentic' ),
					'summary'  => __( 'Set the editor featured image', 'ahentic' ),
				),
				self::SET_POST_TERMS            => array(
					'write'    => true,
					'progress' => __( 'Updating post terms…', 'ahentic' ),
					'summary'  => __( 'Set the editor post terms', 'ahentic' ),
				),
				self::SAVE_POST                 => array(
					'write'        => true,
					'hitl'         => true,
					'progress'     => __( 'Saving the post…', 'ahentic' ),
					'summary'      => __( 'Save the post in the editor', 'ahentic' ),
					'hitl_summary' => __( 'Save the post currently open in the block editor', 'ahentic' ),
				),
				self::FILL_FIELDS               => array(
					'write'            => true,
					// HITL is input-aware (password / email / role); see requires_hitl().
					'page_only'        => true,
					'non_preallowable' => true,
					'progress'         => __( 'Filling form fields…', 'ahentic' ),
					'summary'          => __( 'Fill form fields on the open page', 'ahentic' ),
				),
			);
		}

		/**
		 * Browser tools that run on any open tab (admin forms, front-end) — not editor-only.
		 *
		 * @return string[]
		 */
		public static function page_only_names() {
			$out = array();
			foreach ( self::catalog() as $name => $entry ) {
				if ( ! empty( $entry['page_only'] ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}

		/**
		 * Whether the ability may run without an open block editor.
		 *
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_page_only( $name ) {
			return in_array( (string) $name, self::page_only_names(), true );
		}

		/**
		 * Option keys that must never be filled via the open form (same as update-option denylist).
		 *
		 * @return string[]
		 */
		public static function fill_fields_option_denylist() {
			if ( class_exists( 'Ahentic_Abilities_Settings' )
				&& is_callable( array( 'Ahentic_Abilities_Settings', 'option_write_denylist' ) ) ) {
				return Ahentic_Abilities_Settings::option_write_denylist();
			}
			return array(
				'siteurl',
				'home',
				'default_role',
				'users_can_register',
				'admin_email',
			);
		}

		/**
		 * Whether a fill-fields target key is hard-denied.
		 *
		 * @param string $key Field name or id.
		 * @return bool
		 */
		public static function fill_fields_key_is_denied( $key ) {
			$key = (string) $key;
			if ( '' === $key ) {
				return false;
			}
			return in_array( $key, self::fill_fields_option_denylist(), true );
		}

		/**
		 * Whether a fill-fields target is sensitive (HITL) — password / email / role-like.
		 * Hard-denied keys are not "sensitive"; they are refused entirely.
		 *
		 * @param string $key   Field name, id, or label.
		 * @param string $label Optional label text.
		 * @return bool
		 */
		public static function fill_fields_key_is_sensitive( $key, $label = '' ) {
			$key   = (string) $key;
			$label = (string) $label;
			if ( self::fill_fields_key_is_denied( $key ) ) {
				return false;
			}
			$haystack = strtolower( trim( $key . ' ' . $label ) );
			if ( '' === $haystack ) {
				return false;
			}
			return (bool) preg_match(
				'/pass(word|wd)?|user_pass|pwd|e-?mail|\brole\b|capabilit(y|ies)/i',
				$haystack
			);
		}

		/**
		 * Collect name/id/label keys from a fill-fields input payload.
		 *
		 * @param array $input Ability input.
		 * @return array{denied: string[], sensitive: bool}
		 */
		public static function fill_fields_classify_input( $input ) {
			$input     = is_array( $input ) ? $input : array();
			$fields    = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
			$denied    = array();
			$sensitive = false;

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name  = isset( $field['name'] ) ? (string) $field['name'] : '';
				$id    = isset( $field['id'] ) ? (string) $field['id'] : '';
				$label = isset( $field['label'] ) ? (string) $field['label'] : '';

				foreach ( array( $name, $id ) as $key ) {
					if ( self::fill_fields_key_is_denied( $key ) ) {
						$denied[] = $key;
					}
				}
				if ( self::fill_fields_key_is_sensitive( $name, $label )
					|| self::fill_fields_key_is_sensitive( $id, $label )
					|| ( '' === $name && '' === $id && self::fill_fields_key_is_sensitive( '', $label ) ) ) {
					$sensitive = true;
				}
			}

			return array(
				'denied'    => array_values( array_unique( $denied ) ),
				'sensitive' => $sensitive,
			);
		}

		/**
		 * Refuse fill-fields when any target is on the option hard-denylist.
		 *
		 * @param array $input Ability input.
		 * @return true|\WP_Error
		 */
		public static function fill_fields_preflight( $input = array() ) {
			$classified = self::fill_fields_classify_input( $input );
			if ( empty( $classified['denied'] ) ) {
				return true;
			}
			$keys = implode( ', ', $classified['denied'] );
			return new WP_Error(
				'ahentic_option_denied',
				sprintf(
					/* translators: %s: comma-separated option keys */
					__( 'Cannot fill hard-denied option field(s): %s. These cannot be changed through Ahentic (same denylist as ahentic/update-option).', 'ahentic' ),
					$keys
				),
				array( 'keys' => $classified['denied'] )
			);
		}

		/**
		 * Whether fill-fields should pause for Allow given this input.
		 *
		 * @param array $input Ability input.
		 * @return bool
		 */
		public static function fill_fields_input_requires_hitl( $input = array() ) {
			$classified = self::fill_fields_classify_input( $input );
			if ( ! empty( $classified['denied'] ) ) {
				// Denied fields are refused in preflight; do not HITL them.
				return false;
			}
			return ! empty( $classified['sensitive'] );
		}

		/**
		 * All browser ability names from the policy catalog.
		 *
		 * @return string[] Ability names.
		 */
		public static function names() {
			return array_keys( self::catalog() );
		}

		/**
		 * Mutating browser abilities (not available in Ask mode).
		 *
		 * @return string[]
		 */
		public static function write_names() {
			$out = array();
			foreach ( self::catalog() as $name => $entry ) {
				if ( ! empty( $entry['write'] ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}

		/**
		 * Browser abilities that pause for Allow/Deny before running in the sidebar.
		 *
		 * @return string[]
		 */
		public static function hitl_names() {
			$out = array();
			foreach ( self::catalog() as $name => $entry ) {
				if ( ! empty( $entry['hitl'] ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}

		/**
		 * Whether the ability is a browser-runtime tool in this module.
		 *
		 * @param string $name Ability name.
		 * @return bool True when listed in the browser catalog.
		 */
		public static function is_browser( $name ) {
			return in_array( (string) $name, self::names(), true );
		}

		/**
		 * Whether the ability is readonly (Ask-mode safe).
		 *
		 * @param string $name Ability name.
		 * @return bool True when not a write ability.
		 */
		public static function is_readonly( $name ) {
			return ! in_array( (string) $name, self::write_names(), true );
		}

		/**
		 * Whether the ability pauses for Allow/Deny before browser execution.
		 *
		 * fill-fields is input-aware: ordinary fields run without HITL; password /
		 * email / role-like targets still pause (hard-denied keys are refused instead).
		 *
		 * @param string $name  Ability name.
		 * @param array  $input Optional tool input (used for fill-fields).
		 * @return bool True when HITL is required.
		 */
		public static function requires_hitl( $name, $input = array() ) {
			$name = (string) $name;
			if ( self::FILL_FIELDS === $name ) {
				return self::fill_fields_input_requires_hitl( $input );
			}
			return in_array( $name, self::hitl_names(), true );
		}

		/**
		 * Form fills that must never honor session/always allowlists.
		 *
		 * @return string[]
		 */
		public static function non_preallowable_names() {
			$out = array();
			foreach ( self::catalog() as $name => $entry ) {
				if ( ! empty( $entry['non_preallowable'] ) ) {
					$out[] = $name;
				}
			}
			return $out;
		}

		/**
		 * @param string $name Ability.
		 * @return bool
		 */
		public static function is_non_preallowable( $name ) {
			return in_array( (string) $name, self::non_preallowable_names(), true );
		}

		/**
		 * Register category.
		 */
		public static function register_category() {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}
			wp_register_ability_category(
				'ahentic-browser',
				array(
					'label'       => __( 'Ahentic Browser', 'ahentic' ),
					'description' => __( 'Client-side page inspection and block editor abilities for Ahentic.', 'ahentic' ),
				)
			);
		}

		/**
		 * Register abilities (PHP stubs — orchestrator pauses for browser execution).
		 */
		public static function register() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$permission = static function () {
				return current_user_can( 'manage_options' );
			};

			$readonly_meta = array(
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
				'show_in_rest' => false,
				'ahentic'      => array(
					'runtime' => 'browser',
				),
			);

			$mutate_meta = array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => false,
				'ahentic'      => array(
					'runtime' => 'browser',
				),
			);

			$defs = array(
				array(
					'name'        => self::CURRENT_PAGE,
					'label'       => __( 'Get current page', 'ahentic' ),
					'description' => __( 'Returns the URL, title, admin screen hints, and whether the block editor is open (post_id, post_type, editor_title) for the page where the Ahentic sidebar is open. Runs in the browser.', 'ahentic' ),
					'meta'        => $readonly_meta,
					'input'       => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
				),
				array(
					'name'        => self::VISIBLE_PAGE,
					'label'       => __( 'Get visible page', 'ahentic' ),
					'description' => __( 'Reads what is visible on the open tab: page identity, headings, admin notices, primary actions, form fields, and a capped text excerpt from the main content. Use for “what’s on this screen” / teacher mode. Runs in the browser.', 'ahentic' ),
					'meta'        => $readonly_meta,
					'input'       => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
				),
				array(
					'name'        => self::EDITOR_STATE,
					'label'       => __( 'Get editor state', 'ahentic' ),
					'description' => __( 'Returns whether the block editor is open, post id, dirty state, editor mode, and current title. Runs in the browser.', 'ahentic' ),
					'meta'        => $readonly_meta,
					'input'       => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
				),
				array(
					'name'        => self::GET_BLOCKS,
					'label'       => __( 'Get blocks', 'ahentic' ),
					'description' => __( 'Returns the block tree from the open block editor (ref, name, preview, content_attr, media_kind, attribute_keys, innerBlocks), capped for size. Full attribute values are omitted by default, but the primary rich-text content/text/… attribute is included as capped HTML so light edits (internal links) can patch from one read. Use "preview" to match user phrases and "content_attr" as the attribute key for update-block-attributes. Blocks with image-looking media also include compact attributes (url/alt/id, nested background objects, …) and media_kind (image or background) for describe-image without include_attributes. Page heroes are often media_kind=background on a container/group/cover in any block library, not a nested image overlay. Pass include_attributes:true (ideally with a narrow root_ref) for other full attribute values. Pass refs/ref to return ONLY those blocks (attributes included by default). Use returned ref values (b1, b2, …) in later browser tools — never invent clientId hashes. Runs in the browser.', 'ahentic' ),
					'meta'        => $readonly_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'refs'               => array(
								'description' => __( 'Optional block ref or list of refs from a prior get-blocks/get-selection. When set, only those blocks are returned (not the full document).', 'ahentic' ),
							),
							'ref'                => array(
								'type'        => 'string',
								'description' => __( 'Optional single block ref (same as refs with one item).', 'ahentic' ),
							),
							'root_ref'           => array(
								'type'        => 'string',
								'description' => __( 'Optional root block ref from a prior get-blocks/get-selection; omit for the full document. Ignored when refs/ref is set.', 'ahentic' ),
							),
							'max_blocks'         => array(
								'type'        => 'integer',
								'description' => __( 'Max blocks to return (default 80).', 'ahentic' ),
							),
							'include_attributes' => array(
								'type'        => 'boolean',
								'description' => __( 'Include full attribute values per block (default false for full-document reads; default true when refs/ref is set). Only set this with a narrow root_ref or refs — on large/third-party blocks (e.g. page builders) it can produce a very large result.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::GET_SELECTION,
					'label'       => __( 'Get selection', 'ahentic' ),
					'description' => __( 'Returns the selected block(s), attributes, and rich-text highlight from the open block editor. Primary context for “improve this”. Use returned ref values in later browser tools. Runs in the browser.', 'ahentic' ),
					'meta'        => $readonly_meta,
					'input'       => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
				),
				array(
					'name'        => self::GET_BLOCK_TYPE,
					'label'       => __( 'Get block type', 'ahentic' ),
					'description' => __( 'Returns the registered block type schema (attributes, supports, variations, example) for a block name such as core/heading or stackable/heading. Prefer for non-core/third-party blocks; skip for well-known core/* text blocks. Pass fields:"convert" (or "content") for a slim schema (content/media attrs only) when diagnosing convert leftovers — prefer ahentic-browser/convert-blocks with target for library conversion. Rich-text attrs are flagged — pass HTML strings. Runs in the browser.', 'ahentic' ),
					'meta'        => $readonly_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'   => array(
								'type'        => 'string',
								'description' => __( 'Block name (e.g. core/heading or stackable/heading). Preferred.', 'ahentic' ),
							),
							'ref'    => array(
								'type'        => 'string',
								'description' => __( 'Optional: if name is omitted, resolve the block’s name from this ref (from get-blocks). Prefer passing name directly.', 'ahentic' ),
							),
							'fields' => array(
								'type'        => 'string',
								'enum'        => array( 'full', 'convert', 'content' ),
								'description' => __( 'full (default) returns the complete schema; convert/content return content and media attributes only.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::LIST_BLOCK_TYPES,
					'label'       => __( 'List block types', 'ahentic' ),
					'description' => __( 'Lists registered block types, optionally filtered by namespace (core, stackable, ugb, …). Runs in the browser.', 'ahentic' ),
					'meta'        => $readonly_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'namespace' => array(
								'type'        => 'string',
								'description' => __( 'Optional namespace filter (e.g. core, stackable).', 'ahentic' ),
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => __( 'Max types to return (default 100).', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::FOCUS_BLOCK,
					'label'       => __( 'Focus block', 'ahentic' ),
					'description' => __( 'Selects and scrolls to a block by ref in the open editor. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'ref' ),
						'properties' => array(
							'ref' => array(
								'type'        => 'string',
								'description' => __( 'Block ref from get-blocks/get-selection (e.g. b1).', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::UPDATE_BLOCK_ATTRIBUTES,
					'label'       => __( 'Update block attributes', 'ahentic' ),
					'description' => __( 'Patches attributes on one or more blocks by ref. Prefer over full replace for text/light edits. Guessed media keys (url/id/alt, mediaurl, …) are remapped onto the live block\'s keys, including nested image objects, container/group/cover background images in any block library, and compiled CSS strings that still contain the old URL. A media URL aimed at a tiny nested overlay is applied to the closest ancestor with a background image instead (retargeted). Returns ok:false (attributes_not_applied) with live_media if the value did not land. Do not treat a dispatch as success. Use get-block-type first for unknown/third-party blocks when remap is not enough. Pass refs from get-blocks/get-selection. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'attributes' ),
						'properties' => array(
							'ref'        => array(
								'description' => __( 'Block ref (or array of refs) from get-blocks/get-selection.', 'ahentic' ),
							),
							'refs'       => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Optional list of refs (alternative to ref).', 'ahentic' ),
							),
							'attributes' => array(
								'type'                 => 'object',
								'description'          => __( 'Attribute key/value patches.', 'ahentic' ),
								'additionalProperties' => true,
							),
						),
					),
				),
				array(
					'name'        => self::REPLACE_BLOCKS,
					'label'       => __( 'Replace blocks', 'ahentic' ),
					'description' => __( 'Replaces blocks (by refs or current selection) with a new block tree built via createBlock. Pass real block objects {name, attributes, innerBlocks} — not plain text or bracket stubs. Prefer set-blocks to rewrite the whole document. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'blocks' ),
						'properties' => array(
							'refs'   => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Block refs to replace (from get-blocks/get-selection); defaults to current selection.', 'ahentic' ),
							),
							'ref'    => array(
								'type'        => 'string',
								'description' => __( 'Single block ref to replace (alternative to refs).', 'ahentic' ),
							),
							'blocks' => array(
								'description' => __( 'Replacement blocks: array of {name, attributes, innerBlocks}. Prefer structured objects over serialized markup.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::SET_BLOCKS,
					'label'       => __( 'Set blocks', 'ahentic' ),
					'description' => __( 'Replaces the entire open document block tree (no target refs needed). Best for drafting a full article. Pass real block objects {name, attributes, innerBlocks}, or from_memory with a staged blocks artifact key — not plain text stubs. Put the article H1 in the post title via update-post-document; body headings start at level 2 (playbook post-title-headings). Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'blocks'      => array(
								'description' => __( 'Full document blocks: array of {name, attributes, innerBlocks}. Ignored when from_memory is set.', 'ahentic' ),
							),
							'from_memory' => array(
								'type'        => 'string',
								'description' => __( 'Session artifact key (kind=blocks from ahentic/stage-artifact). Expands to blocks; wins over inline blocks.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::INSERT_BLOCKS,
					'label'       => __( 'Insert blocks', 'ahentic' ),
					'description' => __( 'Inserts blocks into the open editor at an index, after a ref, or at the end of the root. Pass real block objects {name, attributes, innerBlocks} — not plain text or bracket stubs. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'blocks' ),
						'properties' => array(
							'blocks'    => array(
								'description' => __( 'Blocks to insert: array of {name, attributes, innerBlocks}.', 'ahentic' ),
							),
							'after_ref' => array(
								'type'        => 'string',
								'description' => __( 'Insert after this block ref (from get-blocks/get-selection).', 'ahentic' ),
							),
							'root_ref'  => array(
								'type'        => 'string',
								'description' => __( 'Parent block ref (omit for document root).', 'ahentic' ),
							),
							'index'     => array(
								'type'        => 'integer',
								'description' => __( 'Insert index within the root.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::DUPLICATE_BLOCKS,
					'label'       => __( 'Duplicate blocks', 'ahentic' ),
					'description' => __( 'Duplicates the given refs or current selection. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'refs' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Block refs from get-blocks/get-selection.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::DELETE_BLOCKS,
					'label'       => __( 'Delete blocks', 'ahentic' ),
					'description' => __( 'Removes blocks by refs or current selection from the open editor. Prefer this over replace-blocks/set-blocks when deleting — empty replacements are rejected. Does not save. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'refs' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Block refs from get-blocks/get-selection; defaults to current selection.', 'ahentic' ),
							),
							'ref'  => array(
								'type'        => 'string',
								'description' => __( 'Single block ref to delete (alternative to refs).', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::MOVE_BLOCKS,
					'label'       => __( 'Move blocks', 'ahentic' ),
					'description' => __( 'Reorders or reparents blocks in the open editor. Prefer before_ref/after_ref for “move below/above X”; use index with optional root_ref for absolute placement. Not for leaving the content (featured/excerpt) — write the destination then delete-blocks. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'refs' ),
						'properties' => array(
							'refs'       => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Block refs from get-blocks/get-selection.', 'ahentic' ),
							),
							'index'      => array(
								'type'        => 'integer',
								'description' => __( 'Absolute index within the target parent (use with optional root_ref). Mutually exclusive with before_ref/after_ref.', 'ahentic' ),
							),
							'root_ref'   => array(
								'type'        => 'string',
								'description' => __( 'Parent block ref for index placement (omit or empty for document root). Do not combine with before_ref/after_ref.', 'ahentic' ),
							),
							'before_ref' => array(
								'type'        => 'string',
								'description' => __( 'Move so the blocks sit immediately before this ref (same parent as the anchor).', 'ahentic' ),
							),
							'after_ref'  => array(
								'type'        => 'string',
								'description' => __( 'Move so the blocks sit immediately after this ref (same parent as the anchor).', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::NORMALIZE_BLOCK_STYLES,
					'label'       => __( 'Normalize block styles', 'ahentic' ),
					'description' => __( 'Strips custom style/color/typography attributes from the selection or all blocks on the page. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'scope' => array(
								'type'        => 'string',
								'enum'        => array( 'selection', 'all' ),
								'description' => __( 'Defaults to selection when blocks are selected, otherwise all.', 'ahentic' ),
							),
							'refs'  => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Optional block refs from get-blocks/get-selection.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::RESTYLE_BLOCKS_TO_PALETTE,
					'label'       => __( 'Restyle blocks to palette', 'ahentic' ),
					'description' => __( 'Maps custom colors on selected/all blocks toward a provided palette (slug + hex). Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'colors' ),
						'properties' => array(
							'colors' => array(
								'type'        => 'array',
								'description' => __( 'Palette entries: { slug, color } or hex strings.', 'ahentic' ),
							),
							'scope'  => array(
								'type' => 'string',
								'enum' => array( 'selection', 'all' ),
							),
							'refs'   => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Optional block refs from get-blocks/get-selection.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::CONVERT_BLOCKS,
					'label'       => __( 'Convert blocks', 'ahentic' ),
					'description' => __( 'Converts blocks toward a target library or block type using Gutenberg transforms when available (same machinery as Transform to). Pass target as a namespace (core, stackable, …) or exact name (stackable/heading). Default target is core. Prefer this over get-block-type + set-blocks rewrites for library conversion. Returns converted/skipped/failed. dry_run:true previews without changing the canvas. Requires human approval. Runs in the browser.', 'ahentic' ),
					'meta'        => array_merge(
						$mutate_meta,
						array(
							'annotations' => array(
								'readonly'    => false,
								'destructive' => true,
								'idempotent'  => false,
							),
						)
					),
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'target'  => array(
								'type'        => 'string',
								'description' => __( 'Destination namespace (core, stackable, …) or exact block name (stackable/text). Default: core.', 'ahentic' ),
							),
							'refs'    => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Block refs to convert (from get-blocks/get-selection); defaults to selection, or all blocks not already matching target when scope=all.', 'ahentic' ),
							),
							'scope'   => array(
								'type' => 'string',
								'enum' => array( 'selection', 'all' ),
							),
							'dry_run' => array(
								'type'        => 'boolean',
								'description' => __( 'When true, report what would convert without changing the editor.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::AUDIT_ACCESSIBILITY,
					'label'       => __( 'Audit accessibility', 'ahentic' ),
					'description' => __( 'Scans the open editor blocks for common accessibility issues (empty headings, missing image alt, heading order). Readonly report. Runs in the browser.', 'ahentic' ),
					'meta'        => $readonly_meta,
					'input'       => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
				),
				array(
					'name'        => self::UPDATE_POST_TITLE,
					'label'       => __( 'Update post title', 'ahentic' ),
					'description' => __( 'Legacy alias of update-post-document for title-only edits. Prefer ahentic-browser/update-post-document. Updates via the editor store (not DOM); does not save. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'title' ),
						'properties' => array(
							'title'   => array(
								'type'        => 'string',
								'description' => __( 'New post title.', 'ahentic' ),
							),
							'post_id' => array(
								'type'        => 'integer',
								'description' => __( 'Optional. When set, must match the post open in the editor or the ability fails.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::UPDATE_POST_DOCUMENT,
					'label'       => __( 'Update post document', 'ahentic' ),
					'description' => __( 'Updates title, excerpt, and/or slug of the post open in the block editor via the editor store (editPost). Does not save — leave the document dirty like other canvas edits. Prefer this over ahentic/update-post while the editor is open. When drafting, the post title is the document H1 — put the article headline here, not as a core/heading level 1 in the body (playbook post-title-headings). Featured image stays ahentic-browser/set-featured-image. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'title'   => array(
								'type'        => 'string',
								'description' => __( 'New post title (non-empty when provided).', 'ahentic' ),
							),
							'excerpt' => array(
								'type'        => 'string',
								'description' => __( 'New post excerpt; empty string clears.', 'ahentic' ),
							),
							'slug'    => array(
								'type'        => 'string',
								'description' => __( 'New post slug (non-empty when provided).', 'ahentic' ),
							),
							'post_id' => array(
								'type'        => 'integer',
								'description' => __( 'Optional. When set, must match the post open in the editor or the ability fails.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::SET_FEATURED_IMAGE,
					'label'       => __( 'Set featured image', 'ahentic' ),
					'description' => __( 'Sets or clears the featured image of the post open in the block editor via the editor store (featured_media). Does not save — leave the document dirty like other canvas edits. Prefer this when the block editor is open for the target post; use ahentic/set-featured-image when the editor is closed. Pass attachment_id 0 to clear. Optional post_id rejects a mismatch with the open document. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'attachment_id' ),
						'properties' => array(
							'attachment_id' => array(
								'type'        => 'integer',
								'description' => __( 'Media Library attachment ID to set as featured image, or 0 to clear.', 'ahentic' ),
							),
							'post_id'       => array(
								'type'        => 'integer',
								'description' => __( 'Optional. When set, must match the post open in the editor or the ability fails.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::SET_POST_TERMS,
					'label'       => __( 'Set post terms', 'ahentic' ),
					'description' => __( 'Sets categories, tags, and/or custom taxonomy terms on the post open in the block editor via the editor store (replace-per-taxonomy). Does not save — leave the document dirty. Prefer this while the editor is open so the document panel stays in sync; use ahentic/update-post tax fields when the editor is closed (taxonomy-only server update-post is also allowed while the editor is open). Pass term IDs (from list-terms/get-term/create-term). Omit a key to leave that taxonomy unchanged; pass [] to clear. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'categories' => array(
								'description' => __( 'Full set of category term IDs (replace). Omit to leave unchanged; [] clears.', 'ahentic' ),
							),
							'tags'       => array(
								'description' => __( 'Full set of tag term IDs (replace). Omit to leave unchanged; [] clears.', 'ahentic' ),
							),
							'tax_input'  => array(
								'type'                 => 'object',
								'description'          => __( 'Map of taxonomy slug (or REST base) → full list of term IDs. Overrides categories/tags for the same taxonomy when both are set.', 'ahentic' ),
								'additionalProperties' => true,
							),
							'post_id'    => array(
								'type'        => 'integer',
								'description' => __( 'Optional. When set, must match the post open in the editor or the ability fails.', 'ahentic' ),
							),
						),
					),
				),
				array(
					'name'        => self::SAVE_POST,
					'label'       => __( 'Save post', 'ahentic' ),
					'description' => __( 'Saves the post open in the block editor. Use ONLY when the user explicitly asks to save, publish, or persist changes — not as a default last step after insert-blocks or other canvas edits (unsaved editor state is enough). Requires human approval. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
				),
				array(
					'name'        => self::FILL_FIELDS,
					'label'       => __( 'Fill fields', 'ahentic' ),
					'description' => __( 'Fills visible form inputs on the open tab (name preferred, then id; label only to disambiguate). On admin screens prefer this over ahentic/update-option when the control is already on the open form (inspect with get-visible-page first). Does NOT submit — the user clicks Save/Update. Ordinary fields need no Allow; password / email / role-like fields still require Allow. Hard-denied options (siteurl, home, default_role, users_can_register, admin_email) are refused. Native input/select/textarea only — prefer editor-store ahentic-browser/* for block canvas / document fields. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'fields' ),
						'properties' => array(
							'fields' => array(
								'type'        => 'array',
								'description' => __( 'Fields to fill. Prefer name, then id; optional label to disambiguate. value is a string or boolean (checkbox/radio).', 'ahentic' ),
								'items'       => array(
									'type'       => 'object',
									'required'   => array( 'value' ),
									'properties' => array(
										'name'  => array(
											'type'        => 'string',
											'description' => __( 'HTML name attribute (preferred).', 'ahentic' ),
										),
										'id'    => array(
											'type'        => 'string',
											'description' => __( 'HTML id when name is missing or ambiguous.', 'ahentic' ),
										),
										'label' => array(
											'type'        => 'string',
											'description' => __( 'Visible label text for disambiguation only.', 'ahentic' ),
										),
										'value' => array(
											'description' => __( 'Value to set. Strings for text/select/textarea; boolean or checked/unchecked for checkbox/radio.', 'ahentic' ),
										),
									),
								),
							),
						),
					),
				),
			);

			foreach ( $defs as $def ) {
				wp_register_ability(
					$def['name'],
					array(
						'label'               => $def['label'],
						'description'         => $def['description'],
						'category'            => 'ahentic-browser',
						'input_schema'        => $def['input'],
						'output_schema'       => array( 'type' => 'object' ),
						'execute_callback'    => array( __CLASS__, 'execute_stub' ),
						'permission_callback' => $permission,
						'meta'                => $def['meta'],
					)
				);
			}
		}

		/**
		 * Browser abilities must not execute in PHP.
		 *
		 * @param mixed $input Unused.
		 * @return \WP_Error
		 */
		public static function execute_stub( $input = array() ) {
			unset( $input );
			return new WP_Error(
				'ahentic_browser_runtime',
				__( 'This ability must run in the browser.', 'ahentic' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Dispatch stub — browser abilities never execute in PHP.
		 *
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return mixed|\WP_Error Always a browser-runtime error.
		 */
		public static function execute( $name, $input = array() ) {
			unset( $name, $input );
			return self::execute_stub();
		}

		/**
		 * Short summary for pending-tool UI / progress.
		 *
		 * @param string $name Ability.
		 * @return string
		 */
		public static function summary( $name ) {
			$catalog = self::catalog();
			$key     = (string) $name;
			if ( isset( $catalog[ $key ]['summary'] ) ) {
				return $catalog[ $key ]['summary'];
			}
			return $key;
		}

		/**
		 * Human-readable summary for HITL UI.
		 *
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string
		 */
		public static function hitl_summary( $name, $input = array() ) {
			$input = is_array( $input ) ? $input : array();
			$key   = (string) $name;

			if ( self::FILL_FIELDS === $key ) {
				return self::hitl_summary_fill_fields( $input );
			}

			if ( self::CONVERT_BLOCKS === $key ) {
				return self::hitl_summary_convert_blocks( $input );
			}

			$catalog = self::catalog();
			if ( isset( $catalog[ $key ]['hitl_summary'] ) ) {
				return $catalog[ $key ]['hitl_summary'];
			}
			return self::summary( $name );
		}

		/**
		 * HITL copy for convert-blocks including the requested target.
		 *
		 * @param array $input Ability input.
		 * @return string
		 */
		private static function hitl_summary_convert_blocks( $input ) {
			$target = isset( $input['target'] ) ? strtolower( trim( (string) $input['target'] ) ) : 'core';
			if ( '' === $target ) {
				$target = 'core';
			}
			return sprintf(
				/* translators: %s: block namespace or block type name (e.g. stackable, core/heading) */
				__( 'Convert blocks toward %s', 'ahentic' ),
				$target
			);
		}

		/**
		 * HITL copy listing field→value pairs (no submit).
		 *
		 * @param array $input Ability input.
		 * @return string
		 */
		private static function hitl_summary_fill_fields( $input ) {
			$fields = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
			if ( empty( $fields ) ) {
				return __( 'Fill form fields on the open page (does not submit)', 'ahentic' );
			}

			$parts = array();
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$target = '';
				if ( ! empty( $field['name'] ) ) {
					$target = (string) $field['name'];
				} elseif ( ! empty( $field['id'] ) ) {
					$target = (string) $field['id'];
				} elseif ( ! empty( $field['label'] ) ) {
					$target = (string) $field['label'];
				} else {
					$target = __( 'field', 'ahentic' );
				}
				$value = array_key_exists( 'value', $field ) ? $field['value'] : '';
				if ( is_bool( $value ) ) {
					$value = $value ? 'true' : 'false';
				} else {
					$value = (string) $value;
					if ( 'password' === strtolower( (string) ( $field['type'] ?? '' ) ) || preg_match( '/pass(word)?/i', $target ) ) {
						$value = $value !== '' ? '••••••' : '';
					}
				}
				$parts[] = $target . '=' . $value;
				if ( count( $parts ) >= 8 ) {
					break;
				}
			}

			$count = count( $fields );
			$list  = implode( ', ', $parts );
			if ( $count > count( $parts ) ) {
				$list .= ', …';
			}

			return sprintf(
				/* translators: %s: field=value list */
				__( 'Fill form fields (does not submit): %s', 'ahentic' ),
				$list
			);
		}

		/**
		 * Summary for awaiting_browser pending-tool UI.
		 *
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string Human-readable summary or empty when not a browser ability.
		 */
		public static function browser_summary( $name, $input = array() ) {
			unset( $input );
			if ( ! self::is_browser( $name ) ) {
				return '';
			}
			return self::summary( $name );
		}

		/**
		 * Live-status progress label for a browser ability.
		 *
		 * @param string $name Ability name.
		 * @return string Progress label or empty string when unknown.
		 */
		public static function progress_label( $name ) {
			$catalog = self::catalog();
			$key     = (string) $name;
			return isset( $catalog[ $key ]['progress'] ) ? $catalog[ $key ]['progress'] : '';
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_abilities_api_categories_init', array( 'Ahentic_Abilities_Browser', 'register_category' ) );
	add_action( 'wp_abilities_api_init', array( 'Ahentic_Abilities_Browser', 'register' ) );
}
if ( class_exists( 'Ahentic_Abilities' ) ) {
	Ahentic_Abilities::register_module( 'Ahentic_Abilities_Browser' );
}
