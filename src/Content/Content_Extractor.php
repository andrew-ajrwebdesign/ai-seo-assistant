<?php
/**
 * Extracts readable content from WordPress posts/pages.
 */

namespace AJR\SEOAssistant\Content;

use AJR\SEOAssistant\Core\Utils;

defined( 'ABSPATH' ) || exit;

class Content_Extractor {

	public function get_content( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		$parts = [];

		$title = get_the_title( $post_id );

		if ( ! empty( $title ) ) {
			$parts[] = $title;
		}

		// Only a hand-written excerpt. get_the_excerpt() falls back to the
		// first 55 words of the content, which then appeared twice in the
		// text sent to the AI and read as duplicated copy on the page.
		$excerpt = trim( (string) $post->post_excerpt );

		if ( '' !== $excerpt ) {
			$parts[] = $excerpt;
		}

		// post_content is the primary source for every page. Elementor saves a
		// rendered plain-text copy of the page there, which holds all widget
		// copy (including HTML widgets the settings walker cannot read), so
		// reading it AND the walker doubled every Elementor page's text. The
		// walker is only a fallback for a builder page with an empty copy.
		if ( '' !== trim( (string) $post->post_content ) ) {
			$parts[] = $this->get_wordpress_content( $post->post_content );
		} elseif ( $this->is_elementor_page( $post_id ) ) {
			$parts[] = $this->get_elementor_content( $post_id );
		}

		return Utils::clean_plain_text(
			implode( "\n\n", array_filter( $parts ) )
		);
	}

	private function get_wordpress_content( $content ) {
		if ( has_blocks( $content ) ) {
			$content = do_blocks( $content );
		}

		return $content;
	}

	/**
	 * Whether the page is currently built with Elementor.
	 *
	 * Elementor keeps _elementor_data after a page is switched back to the
	 * WordPress editor, so its presence alone would resurrect stale copy.
	 * Same test as Markdown_Converter.
	 */
	private function is_elementor_page( $post_id ) {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true )
			&& ! empty( get_post_meta( $post_id, '_elementor_data', true ) );
	}

	private function get_elementor_content( $post_id ) {
		$data = get_post_meta( $post_id, '_elementor_data', true );

		if ( empty( $data ) ) {
			return '';
		}

		$elements = json_decode( $data, true );

		if ( empty( $elements ) || ! is_array( $elements ) ) {
			return '';
		}

		$text = [];

		$this->walk_elementor_elements( $elements, $text );

		return implode( "\n", $text );
	}

	private function walk_elementor_elements( $elements, &$text ) {
		foreach ( $elements as $element ) {
			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$this->walk_settings_array( $element['settings'], $text );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_elementor_elements( $element['elements'], $text );
			}
		}
	}

	private function walk_settings_array( $settings, &$text ) {
		foreach ( $settings as $key => $value ) {
			if ( is_string( $value ) && $this->is_likely_text_field( $key, $value ) ) {
				$text[] = $value;
			}

			if ( is_array( $value ) ) {
				$this->walk_settings_array( $value, $text );
			}
		}
	}

	private function is_likely_text_field( $key, $value ) {
		if ( '' === trim( $value ) ) {
			return false;
		}

		$excluded_keys = [
			'_id',
			'id',
			'url',
			'link',
			'image',
			'icon',
			'selected_icon',
			'background_image',
			'css_classes',
			'anchor',
			'html_tag',
			'align',
			'width',
			'height',
			'color',
			'typography',
		];

		if ( in_array( $key, $excluded_keys, true ) ) {
			return false;
		}

		$allowed_fragments = [
			'title',
			'heading',
			'editor',
			'text',
			'description',
			'content',
			'button',
			'label',
			'caption',
			'tab',
			'alert',
			'quote',
		];

		foreach ( $allowed_fragments as $fragment ) {
			if ( false !== strpos( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}
