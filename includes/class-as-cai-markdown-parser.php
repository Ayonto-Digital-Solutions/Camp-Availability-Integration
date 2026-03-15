<?php
/**
 * Simple Markdown to HTML Parser
 *
 * @package AS_Camp_Availability_Integration
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AS_CAI_Markdown_Parser {

	public function parse( $markdown ) {
		if ( empty( $markdown ) ) {
			return '';
		}

		// STEP 0: Escape ALL HTML first to prevent raw HTML injection.
		// This ensures code examples, PHP snippets, and HTML in docs are safe.
		$html = htmlspecialchars( $markdown, ENT_QUOTES, 'UTF-8' );

		// Store code blocks and inline code with placeholders.
		$code_blocks  = array();
		$inline_codes = array();

		// Step 1: Extract and protect code blocks (now with escaped backticks).
		// Match ``` with optional language, content, closing ```.
		$html = preg_replace_callback( '/```([a-z]*)\s*\n(.*?)\n\s*```/s', function ( $matches ) use ( &$code_blocks ) {
			$language    = $matches[1];
			$code        = $matches[2]; // Already escaped by htmlspecialchars above.
			$placeholder = '___CODE_BLOCK_' . count( $code_blocks ) . '___';
			$code_blocks[ $placeholder ] = '<pre style="background:#1f2937;color:#e5e7eb;padding:16px;border-radius:8px;overflow-x:auto;margin:16px 0;font-size:13px;line-height:1.5;"><code>' . $code . '</code></pre>';
			return "\n" . $placeholder . "\n";
		}, $html );

		// Step 2: Extract and protect inline code.
		$html = preg_replace_callback( '/`([^`]+)`/', function ( $matches ) use ( &$inline_codes ) {
			$code        = $matches[1]; // Already escaped.
			$placeholder = '___INLINE_CODE_' . count( $inline_codes ) . '___';
			$inline_codes[ $placeholder ] = '<code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:0.875em;">' . $code . '</code>';
			return $placeholder;
		}, $html );

		// Step 3: Process markdown syntax.

		// Horizontal rules.
		$html = preg_replace( '/^---+$/m', '<hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">', $html );

		// Headers.
		$html = preg_replace( '/^#### (.+)$/m', '<h4 style="margin:20px 0 8px;color:#1f2937;">$1</h4>', $html );
		$html = preg_replace( '/^### (.+)$/m', '<h3 style="margin:24px 0 12px;color:#1f2937;font-size:1.25rem;">$1</h3>', $html );
		$html = preg_replace( '/^## (.+)$/m', '<h2 style="margin:28px 0 12px;color:#1f2937;font-size:1.5rem;">$1</h2>', $html );
		$html = preg_replace( '/^# (.+)$/m', '<h1 style="margin:32px 0 16px;color:#1f2937;font-size:2rem;">$1</h1>', $html );

		// Bold (escaped ** becomes \*\*).
		$html = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );

		// Italic.
		$html = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $html );

		// Links (escaped brackets: &lsqb; etc won't match, so only actual markdown links work).
		$html = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" style="color:#667eea;">$1</a>', $html );

		// Tables: detect markdown tables and convert.
		$html = preg_replace_callback( '/((?:^\|.+\|$\n?){2,})/m', function ( $matches ) {
			$rows   = array_filter( explode( "\n", trim( $matches[1] ) ) );
			$output = '<table style="width:100%;border-collapse:collapse;margin:16px 0;"><thead>';
			$is_header = true;
			foreach ( $rows as $row ) {
				$row = trim( $row, '| ' );
				// Skip separator rows.
				if ( preg_match( '/^[\s\-\|:]+$/', $row ) ) {
					$is_header = false;
					$output   .= '</thead><tbody>';
					continue;
				}
				$cells = array_map( 'trim', explode( '|', $row ) );
				$tag   = $is_header ? 'th' : 'td';
				$style = $is_header
					? 'style="padding:8px 12px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600;text-align:left;"'
					: 'style="padding:8px 12px;border:1px solid #e5e7eb;"';
				$output .= '<tr>';
				foreach ( $cells as $cell ) {
					$output .= '<' . $tag . ' ' . $style . '>' . $cell . '</' . $tag . '>';
				}
				$output .= '</tr>';
			}
			$output .= '</tbody></table>';
			return $output;
		}, $html );

		// Lists.
		$html = preg_replace_callback( '/^[\-\*] (.+)$/m', function ( $matches ) {
			return '<li>' . $matches[1] . '</li>';
		}, $html );
		$html = preg_replace( '/((?:<li>.*?<\/li>\s*)+)/s', '<ul style="margin:12px 0;padding-left:24px;">$1</ul>', $html );

		// Paragraphs.
		$html = '<p>' . preg_replace( '/\n\n+/', '</p><p>', $html ) . '</p>';

		// Clean up empty paragraphs around block elements.
		$html = preg_replace( '/<p>\s*(<(?:h[1-4]|hr|pre|table|ul|ol|div)[^>]*>)/s', '$1', $html );
		$html = preg_replace( '/(<\/(?:h[1-4]|hr|pre|table|ul|ol|div)>)\s*<\/p>/s', '$1', $html );
		$html = preg_replace( '/<p>\s*<\/p>/', '', $html );

		// Line breaks within paragraphs.
		$html = nl2br( $html );

		// Step 4: Restore code blocks and inline code.
		foreach ( $code_blocks as $placeholder => $code_html ) {
			$html = str_replace( $placeholder, $code_html, $html );
		}

		foreach ( $inline_codes as $placeholder => $code_html ) {
			$html = str_replace( $placeholder, $code_html, $html );
		}

		return $html;
	}
}
