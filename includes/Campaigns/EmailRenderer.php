<?php
/**
 * Email renderer for Stampy campaigns.
 *
 * Parses campaign post_content (block HTML) and converts it into a
 * table-based email HTML template with inlined CSS and a plain-text
 * alternative part.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Campaigns;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders campaign block content into email-ready HTML and plain text.
 */
final class EmailRenderer {

	/**
	 * Render a campaign post into email HTML.
	 *
	 * @param \WP_Post $post Campaign post.
	 * @return string Email HTML.
	 */
	public function render_html( \WP_Post $post ): string {
		$blocks  = parse_blocks( $post->post_content );
		$body    = $this->render_blocks_html( $blocks );
		$subject = CampaignPostType::get_subject( $post->ID );

		if ( ! $this->contains_unsubscribe_link( $body ) ) {
			$body .= $this->render_footer();
		}

		return $this->wrap_template( $body, $subject );
	}

	/**
	 * Render a campaign post into plain-text email.
	 *
	 * @param \WP_Post $post Campaign post.
	 * @return string Plain-text email.
	 */
	public function render_text( \WP_Post $post ): string {
		$blocks = parse_blocks( $post->post_content );
		$text   = $this->render_blocks_text( $blocks );

		if ( ! $this->contains_unsubscribe_link( $text ) ) {
			$text .= "\n\n---\n" . __( 'Unsubscribe:', 'stampy' ) . ' {unsubscribe_url}';
		}

		return $text;
	}

	/**
	 * Render an array of blocks into email HTML.
	 *
	 * @param array<int|string, mixed> $blocks Parsed blocks.
	 * @return string
	 */
	private function render_blocks_html( array $blocks ): string {
		$output = '';
		foreach ( $blocks as $block ) {
			$output .= $this->render_block_html( $block );
		}
		return $output;
	}

	/**
	 * Render a single block into email HTML.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	private function render_block_html( array $block ): string {
		$block_name = $block['blockName'] ?? '';
		$inner_html = $block['innerHTML'] ?? '';
		$inner      = $block['innerBlocks'] ?? array();

		if ( ! is_string( $block_name ) || '' === $block_name ) {
			$trimmed = trim( $inner_html );
			if ( '' === $trimmed ) {
				return '';
			}
			return $trimmed;
		}

		switch ( $block_name ) {
			case 'core/paragraph':
				return $this->render_paragraph_html( $inner_html );
			case 'core/heading':
				return $this->render_heading_html( $inner_html );
			case 'core/image':
				return $this->render_image_html( $block );
			case 'core/buttons':
				return $this->render_buttons_html( $inner );
			case 'core/button':
				return $this->render_button_html( $inner_html );
			case 'core/list':
				return $this->render_list_html( $inner );
			case 'core/separator':
				return $this->render_separator_html();
			case 'core/spacer':
				return $this->render_spacer_html( $block );
			case 'core/columns':
				return $this->render_columns_html( $inner );
			case 'core/column':
				return $this->render_column_html( $inner );
			case 'core/group':
				return $this->render_blocks_html( $inner );
			default:
				return '';
		}
	}

	/**
	 * Render a paragraph block.
	 *
	 * @param string $inner_html Raw block HTML.
	 * @return string
	 */
	private function render_paragraph_html( string $inner_html ): string {
		$text = $this->extract_inner_text( $inner_html );
		if ( '' === $text ) {
			return '';
		}
		$linked = $this->convert_links_to_inline( $text );
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;"><tr><td style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6;color:#333333;">' . $linked . '</td></tr></table>';
	}

	/**
	 * Render a heading block.
	 *
	 * @param string $inner_html Raw block HTML.
	 * @return string
	 */
	private function render_heading_html( string $inner_html ): string {
		if ( preg_match( '/<h([1-6])[^>]*>/i', $inner_html, $m ) ) {
			$level  = (int) $m[1];
			$text   = $this->extract_inner_text( $inner_html );
			$sizes  = array(
				1 => '28px',
				2 => '24px',
				3 => '20px',
				4 => '18px',
				5 => '16px',
				6 => '14px',
			);
			$size   = $sizes[ $level ] ?? '16px';
			$linked = $this->convert_links_to_inline( $text );
			return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;"><tr><td style="font-family:Arial,Helvetica,sans-serif;font-size:' . $size . ';font-weight:bold;line-height:1.3;color:#222222;">' . $linked . '</td></tr></table>';
		}
		return '';
	}

	/**
	 * Render an image block.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	private function render_image_html( array $block ): string {
		$attrs = $block['attrs'] ?? array();
		$url   = $attrs['url'] ?? '';
		$alt   = $attrs['alt'] ?? '';
		$width = $attrs['width'] ?? null;

		$inner_html = $block['innerHTML'] ?? '';

		if ( ! is_string( $url ) || '' === $url ) {
			$url = $this->extract_img_src( $inner_html );
		}
		if ( ! is_string( $alt ) || '' === $alt ) {
			$alt = $this->extract_img_alt( $inner_html );
		}
		if ( '' === $url ) {
			return '';
		}

		$url   = $this->ensure_absolute_url( $url );
		$alt   = is_string( $alt ) ? $alt : '';
		$width = is_numeric( $width ) ? ' style="width:' . (int) $width . 'px;max-width:100%;height:auto;"' : ' style="max-width:100%;height:auto;"';

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;"><tr><td><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . $width . ' /></td></tr></table>';
	}

	/**
	 * Render a buttons block (container).
	 *
	 * @param array<int, array<string, mixed>> $inner Inner blocks.
	 * @return string
	 */
	private function render_buttons_html( array $inner ): string {
		$output = '';
		foreach ( $inner as $child ) {
			$output .= $this->render_block_html( $child );
		}
		return $output;
	}

	/**
	 * Render a single button block.
	 *
	 * @param string $inner_html Raw block HTML.
	 * @return string
	 */
	private function render_button_html( string $inner_html ): string {
		$href = '';
		$text = $this->extract_inner_text( $inner_html );

		if ( preg_match( '/href=["\']([^"\']+)["\']/i', $inner_html, $m ) ) {
			$href = $m[1];
		}

		if ( '' === $text ) {
			return '';
		}

		$href_attr = '' !== $href ? ' href="' . esc_url( $this->ensure_absolute_url( $href ) ) . '"' : '';

		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;"><tr><td style="border-radius:5px;background-color:#2271b1;"><a' . $href_attr . ' style="display:inline-block;padding:12px 24px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;">' . esc_html( $text ) . '</a></td></tr></table>';
	}

	/**
	 * Render a list block.
	 *
	 * @param array<int, array<string, mixed>> $inner Inner blocks (list items).
	 * @return string
	 */
	private function render_list_html( array $inner ): string {
		$items = '';
		foreach ( $inner as $item ) {
			$item_name = $item['blockName'] ?? '';
			if ( 'core/list-item' === $item_name ) {
				$text   = $this->extract_inner_text( $item['innerHTML'] ?? '' );
				$linked = $this->convert_links_to_inline( $text );
				$items .= '<li style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6;color:#333333;margin:0 0 4px 0;">' . $linked . '</li>';
			}
		}

		if ( '' === $items ) {
			return '';
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;"><tr><td style="padding-left:8px;"><ul style="padding-left:20px;margin:0;">' . $items . '</ul></td></tr></table>';
	}

	/**
	 * Render a separator block.
	 *
	 * @return string
	 */
	private function render_separator_html(): string {
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;"><tr><td style="border-top:1px solid #dddddd;line-height:0;font-size:0;">&nbsp;</td></tr></table>';
	}

	/**
	 * Render a spacer block.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	private function render_spacer_html( array $block ): string {
		$attrs  = $block['attrs'] ?? array();
		$height = isset( $attrs['height'] ) ? (int) $attrs['height'] : 16;
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 ' . $height . 'px 0;"><tr><td height="' . $height . '" style="line-height:' . $height . 'px;font-size:' . $height . 'px;">&nbsp;</td></tr></table>';
	}

	/**
	 * Render a columns block as table cells.
	 *
	 * @param array<int, array<string, mixed>> $inner Inner column blocks.
	 * @return string
	 */
	private function render_columns_html( array $inner ): string {
		$cells = '';
		$count = count( $inner );
		if ( 0 === $count ) {
			return '';
		}
		foreach ( $inner as $col ) {
			$col_html = $this->render_block_html( $col );
			$cells   .= '<td valign="top" width="' . (int) ( 100 / $count ) . '%" style="padding:0 8px;">' . $col_html . '</td>';
		}
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;"><tr>' . $cells . '</tr></table>';
	}

	/**
	 * Render a column block.
	 *
	 * @param array<int, array<string, mixed>> $inner Inner blocks.
	 * @return string
	 */
	private function render_column_html( array $inner ): string {
		return $this->render_blocks_html( $inner );
	}

	/**
	 * Render the auto-appended footer (unsubscribe + CAN-SPAM address).
	 *
	 * @return string
	 */
	private function render_footer(): string {
		$address          = get_option( 'stampy_physical_address', '' );
		$footer_text      = __( 'You are receiving this email because you subscribed to our mailing list.', 'stampy' );
		$unsubscribe_text = __( 'Unsubscribe', 'stampy' );

		$address_html = '';
		if ( is_string( $address ) && '' !== $address ) {
			$address_html = '<br>' . esc_html( $address );
		}

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0 0;border-top:1px solid #dddddd;padding-top:16px;"><tr><td style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#999999;">' . esc_html( $footer_text ) . '<br><a href="{unsubscribe_url}" style="color:#999999;text-decoration:underline;">' . esc_html( $unsubscribe_text ) . '</a>' . $address_html . '</td></tr></table>';
	}

	/**
	 * Wrap the body in the email template.
	 *
	 * @param string $body    Rendered body HTML.
	 * @param string $subject Email subject (for the <title>).
	 * @return string
	 */
	private function wrap_template( string $body, string $subject ): string {
		return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>' . esc_html( $subject ) . '</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;">
<tr>
<td align="center" style="padding:20px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:4px;max-width:600px;">
<tr>
<td style="padding:32px 40px;">
' . $body . '
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>';
	}

	/**
	 * Render blocks into plain text.
	 *
	 * @param array<int|string, mixed> $blocks Parsed blocks.
	 * @return string
	 */
	private function render_blocks_text( array $blocks ): string {
		$output = '';
		foreach ( $blocks as $block ) {
			$output .= $this->render_block_text( $block );
		}
		return $output;
	}

	/**
	 * Render a single block to plain text.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	private function render_block_text( array $block ): string {
		$block_name = $block['blockName'] ?? '';
		$inner_html = $block['innerHTML'] ?? '';
		$inner      = $block['innerBlocks'] ?? array();

		if ( ! is_string( $block_name ) || '' === $block_name ) {
			$trimmed = trim( $inner_html );
			if ( '' === $trimmed ) {
				return '';
			}
			return $trimmed . "\n\n";
		}

		switch ( $block_name ) {
			case 'core/paragraph':
				$text = $this->strip_html( $inner_html );
				return '' === $text ? '' : $text . "\n\n";
			case 'core/heading':
				$text = $this->strip_html( $inner_html );
				return '' === $text ? '' : strtoupper( $text ) . "\n\n";
			case 'core/image':
				$url = $this->extract_img_src( $inner_html );
				$alt = $block['attrs']['alt'] ?? '';
				if ( is_string( $alt ) && '' !== $alt ) {
					return '[Image: ' . $alt . '](' . $url . ")\n\n";
				}
				return '' !== $url ? '[' . $url . "]\n\n" : '';
			case 'core/buttons':
				return $this->render_blocks_text( $inner );
			case 'core/button':
				$text = $this->extract_inner_text( $inner_html );
				$href = '';
				if ( preg_match( '/href=["\']([^"\']+)["\']/i', $inner_html, $m ) ) {
					$href = $m[1];
				}
				if ( '' === $text ) {
					return '';
				}
				return '' !== $href ? '[' . $text . '](' . $this->ensure_absolute_url( $href ) . ")\n\n" : $text . "\n\n";
			case 'core/list':
				$items = '';
				foreach ( $inner as $item ) {
					if ( 'core/list-item' === ( $item['blockName'] ?? '' ) ) {
						$t      = $this->strip_html( $item['innerHTML'] ?? '' );
						$items .= '  * ' . $t . "\n";
					}
				}
				return '' === $items ? '' : $items . "\n";
			case 'core/separator':
				return "---\n\n";
			case 'core/spacer':
				return '';
			case 'core/columns':
				return $this->render_blocks_text( $inner );
			case 'core/column':
				return $this->render_blocks_text( $inner );
			case 'core/group':
				return $this->render_blocks_text( $inner );
			default:
				return '';
		}
	}

	/**
	 * Extract text content from a block's innerHTML.
	 *
	 * Strips HTML tags but preserves link href as [text](url) for plain text.
	 * For HTML output, returns the inner content as-is (links already inline).
	 *
	 * @param string $html Block innerHTML.
	 * @return string
	 */
	private function extract_inner_text( string $html ): string {
		return trim( wp_strip_all_tags( $html ) );
	}

	/**
	 * Convert <a> tags to inline-styled links for email HTML.
	 *
	 * @param string $text Already-stripped text (no HTML tags).
	 * @return string
	 */
	private function convert_links_to_inline( string $text ): string {
		return esc_html( $text );
	}

	/**
	 * Strip HTML tags and decode entities for plain text.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function strip_html( string $html ): string {
		$decoded = wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) );
		return trim( $decoded );
	}

	/**
	 * Extract the src URL from an <img> tag in raw HTML.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function extract_img_src( string $html ): string {
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Extract the alt text from an <img> tag in raw HTML.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function extract_img_alt( string $html ): string {
		if ( preg_match( '/<img[^>]+alt=["\']([^"\']*)["\']/i', $html, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Ensure a URL is absolute (relative → home_url() prepended).
	 *
	 * @param string $url URL to check.
	 * @return string
	 */
	private function ensure_absolute_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( '{unsubscribe_url}' === $url || 0 === strpos( $url, '{' ) ) {
			return $url;
		}
		return home_url( $url );
	}

	/**
	 * Check whether the content already contains an unsubscribe link.
	 *
	 * @param string $content Rendered content.
	 * @return bool
	 */
	private function contains_unsubscribe_link( string $content ): bool {
		return false !== strpos( $content, '{unsubscribe_url}' ) ||
			false !== stripos( $content, 'unsubscribe' );
	}
}
