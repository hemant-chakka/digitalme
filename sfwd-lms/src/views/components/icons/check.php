<?php
/**
 * View: Check Icon.
 *
 * @var array<string> $classes Additional classes to add to the svg icon.
 *
 * @since 4.20.1
 * @version 4.20.1
 *
 * @package LearnDash\Core
 */

$svg_classes = [ 'ld-svgicon', 'ld-svgicon__check' ];

if ( ! empty( $classes ) ) {
	$svg_classes = array_merge( $svg_classes, $classes );
}

?>
<svg
	class="<?php echo esc_attr( implode( ' ', $svg_classes ) ); ?>"
	aria-label="<?php esc_attr_e( 'Check icon', 'learndash' ); ?>"
	role="img"
	width="12"
	height="10"
	viewBox="0 0 12 10"
	fill="none"
	xmlns="http://www.w3.org/2000/svg"
>
	<path d="M10.0996 0.149902L11.5522 1.0874L5.18628 9.48584H3.73364L0.1875 4.95459L1.64014 3.70459L4.45996 6.0874L10.0996 0.149902Z"/>
</svg>
