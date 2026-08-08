<?php
/**
 * stage-artifact payload normalize: reject stubs / bad shapes early.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Pure seam: Ahentic_Session_Artifacts::normalize_stage_payload.
 */
class ArtifactStagePayloadTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-ahentic-content-placeholder.php';
		Ahentic_Content_Placeholder::reset_rules_for_tests();
		require_once dirname( __DIR__, 2 ) . '/src/session/class-artifacts.php';
	}

	public function test_blocks_string_stub_is_placeholder_not_shape_only() {
		$result = Ahentic_Session_Artifacts::normalize_stage_payload(
			Ahentic_Session_Artifacts::KIND_BLOCKS,
			'A complete approximately 1,000-word article in real WordPress block objects will be provided.'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ahentic_placeholder_content', $result->get_error_code() );
	}

	public function test_blocks_key_holding_string_stub_is_placeholder() {
		$result = Ahentic_Session_Artifacts::normalize_stage_payload(
			Ahentic_Session_Artifacts::KIND_BLOCKS,
			array(
				'blocks' => 'HTML paragraph with a natural internal link to the Metro Manila commute planning article',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ahentic_placeholder_content', $result->get_error_code() );
	}

	public function test_blocks_with_placeholder_attribute_rejected() {
		$result = Ahentic_Session_Artifacts::normalize_stage_payload(
			Ahentic_Session_Artifacts::KIND_BLOCKS,
			array(
				'blocks' => array(
					array(
						'name'       => 'core/paragraph',
						'attributes' => array(
							'content' => '[full article]',
						),
						'innerBlocks' => array(),
					),
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ahentic_placeholder_content', $result->get_error_code() );
	}

	public function test_valid_blocks_normalize() {
		$result = Ahentic_Session_Artifacts::normalize_stage_payload(
			Ahentic_Session_Artifacts::KIND_BLOCKS,
			array(
				'blocks' => array(
					array(
						'name'        => 'core/paragraph',
						'attributes'  => array(
							'content' => 'WordPress makes it easy to publish beautiful posts.',
						),
						'innerBlocks' => array(),
					),
				),
			)
		);
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['blocks'] );
		$this->assertSame( 'core/paragraph', $result['blocks'][0]['name'] );
	}

	public function test_html_placeholder_rejected() {
		$result = Ahentic_Session_Artifacts::normalize_stage_payload(
			Ahentic_Session_Artifacts::KIND_HTML,
			'[expanded guide content]'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ahentic_placeholder_content', $result->get_error_code() );
	}

	public function test_markdown_and_post_content_placeholders_rejected() {
		foreach ( array(
			Ahentic_Session_Artifacts::KIND_MARKDOWN,
			Ahentic_Session_Artifacts::KIND_POST_CONTENT,
		) as $kind ) {
			$result = Ahentic_Session_Artifacts::normalize_stage_payload( $kind, 'Full article content.' );
			$this->assertInstanceOf( WP_Error::class, $result, $kind );
			$this->assertSame( 'ahentic_placeholder_content', $result->get_error_code(), $kind );
		}
	}

	public function test_nested_inner_blocks_placeholder_rejected() {
		$result = Ahentic_Session_Artifacts::normalize_stage_payload(
			Ahentic_Session_Artifacts::KIND_BLOCKS,
			array(
				'blocks' => array(
					array(
						'name'        => 'core/group',
						'attributes'  => array(),
						'innerBlocks' => array(
							array(
								'name'        => 'core/paragraph',
								'attributes'  => array(
									'content' => 'HTML paragraph with a natural internal link to the general Metro Manila commuting methods article',
								),
								'innerBlocks' => array(),
							),
						),
					),
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ahentic_placeholder_content', $result->get_error_code() );
	}

	public function test_html_deferred_editorial_near_miss_allowed() {
		$result = Ahentic_Session_Artifacts::normalize_stage_payload(
			Ahentic_Session_Artifacts::KIND_HTML,
			array(
				'content' => 'Further details will be provided in the next section for subscribers.',
			)
		);
		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Further details', $result['content'] );
	}

	public function test_non_stub_string_blocks_still_invalid_shape() {
		$result = Ahentic_Session_Artifacts::normalize_stage_payload(
			Ahentic_Session_Artifacts::KIND_BLOCKS,
			'not json and not a known stub phrase about articles'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ahentic_invalid_artifact_payload', $result->get_error_code() );
	}
}
