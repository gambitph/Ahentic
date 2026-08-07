<?php
/**
 * Image artifact bytes report file size, not JSON pointer length.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Pure seam: Ahentic_Session_Artifacts::resolve_artifact_bytes.
 */
class ArtifactBytesTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/session/class-artifacts.php';
	}

	/**
	 * Image kind uses filesize of the temp path when readable.
	 */
	public function test_image_bytes_use_filesize_not_pointer_json() {
		$path = tempnam( sys_get_temp_dir(), 'ahentic-img-' );
		$this->assertNotFalse( $path );
		$blob = str_repeat( 'IMG', 200 ); // 600 bytes
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $blob );

		$payload     = array(
			'path'      => $path,
			'mime_type' => 'image/png',
			'width'     => 64,
			'height'    => 64,
		);
		$encoded_len = strlen( (string) json_encode( $payload ) );
		$this->assertLessThan( 200, $encoded_len, 'pointer JSON should stay tiny' );

		$bytes = Ahentic_Session_Artifacts::resolve_artifact_bytes(
			Ahentic_Session_Artifacts::KIND_IMAGE,
			$payload,
			$encoded_len
		);
		$this->assertSame( 600, $bytes );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $path );
	}

	/**
	 * Non-image kinds keep encoded payload length.
	 */
	public function test_non_image_bytes_use_encoded_length() {
		$payload = array( 'content' => 'hello world' );
		$len     = 42;
		$bytes   = Ahentic_Session_Artifacts::resolve_artifact_bytes(
			Ahentic_Session_Artifacts::KIND_HTML,
			$payload,
			$len
		);
		$this->assertSame( 42, $bytes );
	}
}
