<?php
/**
 * Vision attachment size picker for ahentic/describe-image.
 *
 * @package Ahentic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers Ahentic_Abilities_Media::pick_vision_attachment_size().
 */
class DescribeImageSizeTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__, 2 ) . '/src/abilities/class-abilities-media.php';
	}

	public function test_picks_smallest_registered_size_at_least_1024_long_edge() {
		$meta = array(
			'width'  => 4000,
			'height' => 3000,
			'file'   => '2024/01/photo.jpg',
			'sizes'  => array(
				'thumbnail' => array( 'file' => 'photo-150x150.jpg', 'width' => 150, 'height' => 150 ),
				'medium'    => array( 'file' => 'photo-800x600.jpg', 'width' => 800, 'height' => 600 ),
				'large'     => array( 'file' => 'photo-1200x900.jpg', 'width' => 1200, 'height' => 900 ),
				'xlarge'    => array( 'file' => 'photo-2048x1536.jpg', 'width' => 2048, 'height' => 1536 ),
			),
		);

		$picked = Ahentic_Abilities_Media::pick_vision_attachment_size( $meta );

		$this->assertSame( 'large', $picked['size'] );
		$this->assertSame( 'photo-1200x900.jpg', $picked['file'] );
	}

	public function test_falls_back_to_full_when_no_size_meets_threshold() {
		$meta = array(
			'width'  => 800,
			'height' => 600,
			'file'   => '2024/01/small.jpg',
			'sizes'  => array(
				'thumbnail' => array( 'file' => 'small-150x150.jpg', 'width' => 150, 'height' => 150 ),
				'medium'    => array( 'file' => 'small-300x225.jpg', 'width' => 300, 'height' => 225 ),
			),
		);

		$picked = Ahentic_Abilities_Media::pick_vision_attachment_size( $meta );

		$this->assertSame( 'full', $picked['size'] );
		$this->assertSame( '', $picked['file'] );
	}
}
