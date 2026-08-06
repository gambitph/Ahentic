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
		const CURRENT_PAGE             = 'ahentic-browser/get-current-page';
		const VISIBLE_PAGE             = 'ahentic-browser/get-visible-page';
		const EDITOR_STATE             = 'ahentic-browser/get-editor-state';
		const GET_BLOCKS               = 'ahentic-browser/get-blocks';
		const GET_SELECTION            = 'ahentic-browser/get-selection';
		const GET_BLOCK_TYPE           = 'ahentic-browser/get-block-type';
		const LIST_BLOCK_TYPES         = 'ahentic-browser/list-block-types';
		const FOCUS_BLOCK              = 'ahentic-browser/focus-block';
		const UPDATE_BLOCK_ATTRIBUTES  = 'ahentic-browser/update-block-attributes';
		const REPLACE_BLOCKS           = 'ahentic-browser/replace-blocks';
		const SET_BLOCKS               = 'ahentic-browser/set-blocks';
		const INSERT_BLOCKS            = 'ahentic-browser/insert-blocks';
		const DUPLICATE_BLOCKS         = 'ahentic-browser/duplicate-blocks';
		const MOVE_BLOCKS              = 'ahentic-browser/move-blocks';
		const NORMALIZE_BLOCK_STYLES   = 'ahentic-browser/normalize-block-styles';
		const RESTYLE_BLOCKS_TO_PALETTE = 'ahentic-browser/restyle-blocks-to-palette';
		const CONVERT_BLOCKS           = 'ahentic-browser/convert-blocks';
		const AUDIT_ACCESSIBILITY      = 'ahentic-browser/audit-accessibility';
		const UPDATE_POST_TITLE        = 'ahentic-browser/update-post-title';
		const SAVE_POST                = 'ahentic-browser/save-post';

		/**
		 * @return string[]
		 */
		public static function names() {
			return array(
				self::CURRENT_PAGE,
				self::VISIBLE_PAGE,
				self::EDITOR_STATE,
				self::GET_BLOCKS,
				self::GET_SELECTION,
				self::GET_BLOCK_TYPE,
				self::LIST_BLOCK_TYPES,
				self::FOCUS_BLOCK,
				self::UPDATE_BLOCK_ATTRIBUTES,
				self::REPLACE_BLOCKS,
				self::SET_BLOCKS,
				self::INSERT_BLOCKS,
				self::DUPLICATE_BLOCKS,
				self::MOVE_BLOCKS,
				self::NORMALIZE_BLOCK_STYLES,
				self::RESTYLE_BLOCKS_TO_PALETTE,
				self::CONVERT_BLOCKS,
				self::AUDIT_ACCESSIBILITY,
				self::UPDATE_POST_TITLE,
				self::SAVE_POST,
			);
		}

		/**
		 * Mutating browser abilities (not available in Ask mode).
		 *
		 * @return string[]
		 */
		public static function write_names() {
			return array(
				self::FOCUS_BLOCK,
				self::UPDATE_BLOCK_ATTRIBUTES,
				self::REPLACE_BLOCKS,
				self::SET_BLOCKS,
				self::INSERT_BLOCKS,
				self::DUPLICATE_BLOCKS,
				self::MOVE_BLOCKS,
				self::NORMALIZE_BLOCK_STYLES,
				self::RESTYLE_BLOCKS_TO_PALETTE,
				self::CONVERT_BLOCKS,
				self::UPDATE_POST_TITLE,
				self::SAVE_POST,
			);
		}

		/**
		 * Browser abilities that pause for Allow/Deny before running in the sidebar.
		 *
		 * @return string[]
		 */
		public static function hitl_names() {
			return array(
				self::SAVE_POST,
				self::CONVERT_BLOCKS,
			);
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_browser( $name ) {
			return in_array( (string) $name, self::names(), true );
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function is_readonly( $name ) {
			return ! in_array( (string) $name, self::write_names(), true );
		}

		/**
		 * @param string $name Ability name.
		 * @return bool
		 */
		public static function requires_hitl( $name ) {
			return in_array( (string) $name, self::hitl_names(), true );
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
					'description' => __( 'Returns the block tree from the open block editor (ref, name, preview, content_attr, attribute_keys, innerBlocks), capped for size. Full attribute values are omitted by default — use "preview" to match user phrases and "content_attr" as the attribute key to patch via update-block-attributes. Blocks with image-looking media also include compact attributes (real keys such as url/alt/id, mediaUrl/mediaAlt/mediaId, imageUrl, or other image URL fields) so you can call ahentic/describe-image and update alt without include_attributes. Pass include_attributes:true (ideally with a narrow root_ref) for other full attribute values, e.g. before a non-text style edit. Use returned ref values (b1, b2, …) in later browser tools — never invent clientId hashes. Runs in the browser.', 'ahentic' ),
					'meta'        => $readonly_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'root_ref'           => array(
								'type'        => 'string',
								'description' => __( 'Optional root block ref from a prior get-blocks/get-selection; omit for the full document.', 'ahentic' ),
							),
							'max_blocks'         => array(
								'type'        => 'integer',
								'description' => __( 'Max blocks to return (default 80).', 'ahentic' ),
							),
							'include_attributes' => array(
								'type'        => 'boolean',
								'description' => __( 'Include full attribute values per block (default false). Only set this with a narrow root_ref — on large/third-party blocks (e.g. page builders) it can produce a very large result.', 'ahentic' ),
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
					'description' => __( 'Returns the registered block type schema (attributes, supports, variations, example) for a block name such as core/heading or stackable/heading. Prefer for non-core/third-party blocks; skip for well-known core/* text blocks. Rich-text attrs are flagged — pass HTML strings. Runs in the browser.', 'ahentic' ),
					'meta'        => $readonly_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'name' => array(
								'type'        => 'string',
								'description' => __( 'Block name (e.g. core/heading or stackable/heading). Preferred.', 'ahentic' ),
							),
							'ref'  => array(
								'type'        => 'string',
								'description' => __( 'Optional: if name is omitted, resolve the block’s name from this ref (from get-blocks). Prefer passing name directly.', 'ahentic' ),
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
					'description' => __( 'Patches attributes on one or more blocks by ref. Prefer over full replace for text/light edits. Use get-block-type first for unknown/third-party blocks. Pass refs from get-blocks/get-selection. Runs in the browser.', 'ahentic' ),
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
					'description' => __( 'Replaces the entire open document block tree (no target refs needed). Best for drafting a full article. Pass real block objects {name, attributes, innerBlocks}, or from_memory with a staged blocks artifact key — not plain text stubs. Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'properties' => array(
							'blocks'       => array(
								'description' => __( 'Full document blocks: array of {name, attributes, innerBlocks}. Ignored when from_memory is set.', 'ahentic' ),
							),
							'from_memory'  => array(
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
					'name'        => self::MOVE_BLOCKS,
					'label'       => __( 'Move blocks', 'ahentic' ),
					'description' => __( 'Moves blocks to a new index (and optional parent). Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'refs', 'index' ),
						'properties' => array(
							'refs'     => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Block refs from get-blocks/get-selection.', 'ahentic' ),
							),
							'index'    => array( 'type' => 'integer' ),
							'root_ref' => array(
								'type'        => 'string',
								'description' => __( 'Parent block ref (omit for document root).', 'ahentic' ),
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
							'scope'      => array(
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
					'description' => __( 'Converts third-party blocks (e.g. Stackable) toward core blocks where possible. Returns a converted/skipped report. Requires human approval. Runs in the browser.', 'ahentic' ),
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
							'refs'  => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'Block refs to convert (from get-blocks/get-selection); defaults to selection, or all non-core when scope=all.', 'ahentic' ),
							),
							'scope' => array(
								'type' => 'string',
								'enum' => array( 'selection', 'all' ),
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
					'description' => __( 'Updates the title of the post open in the block editor via the editor store (not DOM). Runs in the browser.', 'ahentic' ),
					'meta'        => $mutate_meta,
					'input'       => array(
						'type'       => 'object',
						'required'   => array( 'title' ),
						'properties' => array(
							'title' => array(
								'type'        => 'string',
								'description' => __( 'New post title.', 'ahentic' ),
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
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return mixed|\WP_Error
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
			$map = array(
				self::CURRENT_PAGE              => __( 'Read the current browser page', 'ahentic' ),
				self::VISIBLE_PAGE              => __( 'Read what is visible on the page', 'ahentic' ),
				self::EDITOR_STATE              => __( 'Read the block editor state', 'ahentic' ),
				self::GET_BLOCKS                => __( 'Read the editor block tree', 'ahentic' ),
				self::GET_SELECTION             => __( 'Read the editor selection', 'ahentic' ),
				self::GET_BLOCK_TYPE            => __( 'Read a block type schema', 'ahentic' ),
				self::LIST_BLOCK_TYPES          => __( 'List registered block types', 'ahentic' ),
				self::FOCUS_BLOCK               => __( 'Focus a block in the editor', 'ahentic' ),
				self::UPDATE_BLOCK_ATTRIBUTES   => __( 'Update block attributes', 'ahentic' ),
				self::REPLACE_BLOCKS            => __( 'Replace blocks in the editor', 'ahentic' ),
				self::SET_BLOCKS                => __( 'Set the editor block tree', 'ahentic' ),
				self::INSERT_BLOCKS             => __( 'Insert blocks in the editor', 'ahentic' ),
				self::DUPLICATE_BLOCKS          => __( 'Duplicate blocks', 'ahentic' ),
				self::MOVE_BLOCKS               => __( 'Move blocks', 'ahentic' ),
				self::NORMALIZE_BLOCK_STYLES    => __( 'Strip custom block styles', 'ahentic' ),
				self::RESTYLE_BLOCKS_TO_PALETTE => __( 'Restyle blocks to a color palette', 'ahentic' ),
				self::CONVERT_BLOCKS            => __( 'Convert blocks to core', 'ahentic' ),
				self::AUDIT_ACCESSIBILITY       => __( 'Audit editor accessibility', 'ahentic' ),
				self::UPDATE_POST_TITLE         => __( 'Update the editor post title', 'ahentic' ),
				self::SAVE_POST                 => __( 'Save the post in the editor', 'ahentic' ),
			);

			return isset( $map[ $name ] ) ? $map[ $name ] : (string) $name;
		}

		/**
		 * Human-readable summary for HITL UI.
		 *
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string
		 */
		public static function hitl_summary( $name, $input = array() ) {
			unset( $input );
			if ( self::SAVE_POST === $name ) {
				return __( 'Save the post currently open in the block editor', 'ahentic' );
			}
			if ( self::CONVERT_BLOCKS === $name ) {
				return __( 'Convert third-party blocks toward core Gutenberg blocks', 'ahentic' );
			}
			return self::summary( $name );
		}

		/**
		 * @param string $name  Ability.
		 * @param array  $input Input.
		 * @return string
		 */
		public static function browser_summary( $name, $input = array() ) {
			unset( $input );
			if ( ! self::is_browser( $name ) ) {
				return '';
			}
			return self::summary( $name );
		}

		/**
		 * @param string $name Ability name.
		 * @return string
		 */
		public static function progress_label( $name ) {
			$map = array(
				self::CURRENT_PAGE              => __( 'Reading the current page…', 'ahentic' ),
				self::VISIBLE_PAGE              => __( 'Reading what is on the screen…', 'ahentic' ),
				self::EDITOR_STATE              => __( 'Reading the block editor…', 'ahentic' ),
				self::GET_BLOCKS                => __( 'Reading editor blocks…', 'ahentic' ),
				self::GET_SELECTION             => __( 'Reading the editor selection…', 'ahentic' ),
				self::GET_BLOCK_TYPE            => __( 'Reading block type schema…', 'ahentic' ),
				self::LIST_BLOCK_TYPES          => __( 'Listing block types…', 'ahentic' ),
				self::FOCUS_BLOCK               => __( 'Focusing a block…', 'ahentic' ),
				self::UPDATE_BLOCK_ATTRIBUTES   => __( 'Updating block attributes…', 'ahentic' ),
				self::REPLACE_BLOCKS            => __( 'Replacing blocks…', 'ahentic' ),
				self::SET_BLOCKS                => __( 'Setting editor blocks…', 'ahentic' ),
				self::INSERT_BLOCKS             => __( 'Inserting blocks…', 'ahentic' ),
				self::DUPLICATE_BLOCKS          => __( 'Duplicating blocks…', 'ahentic' ),
				self::MOVE_BLOCKS               => __( 'Moving blocks…', 'ahentic' ),
				self::NORMALIZE_BLOCK_STYLES    => __( 'Stripping custom block styles…', 'ahentic' ),
				self::RESTYLE_BLOCKS_TO_PALETTE => __( 'Restyling blocks to palette…', 'ahentic' ),
				self::CONVERT_BLOCKS            => __( 'Converting blocks to core…', 'ahentic' ),
				self::AUDIT_ACCESSIBILITY       => __( 'Auditing editor accessibility…', 'ahentic' ),
				self::UPDATE_POST_TITLE         => __( 'Updating the editor title…', 'ahentic' ),
				self::SAVE_POST                 => __( 'Saving the post…', 'ahentic' ),
			);
			$name = (string) $name;
			return isset( $map[ $name ] ) ? $map[ $name ] : '';
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
