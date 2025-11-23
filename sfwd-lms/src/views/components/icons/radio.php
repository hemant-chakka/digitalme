<?php
/**
 * View: Radio button Icon
 *
 * The .ld-svgicon--radio-selected class is added to the part of the svg that gets displayed when the radio button is selected.
 *
 * @var array<string> $classes Additional classes to add to the svg icon.
 *
 * @since 4.16.0
 * @version 4.16.0
 *
 * @package LearnDash\Core
 */

$svg_classes = [ 'ld-svgicon', 'ld-svgicon__radio' ];

if ( ! empty( $classes ) ) {
	$svg_classes = array_merge( $svg_classes, $classes );
}
?>
<svg
	class="<?php echo esc_attr( implode( ' ', $svg_classes ) ); ?>"
	width="24"
	height="24"
	viewBox="0 0 24 24"
	fill="none"
	xmlns="http://www.w3.org/2000/svg"
	aria-hidden="true"
	focusable="false"
>
	<rect class="ld-svgicon__radio-bg" x="0.5" y="0.5" width="22" height="22" rx="11.5"/>
	<rect class="ld-svgicon__radio-border" x="0.5" y="0.5" width="22" height="22" rx="11.5"/>
	<circle class="ld-svgicon__radio-select" cx="11.5" cy="11.5" r="7"/>
</svg>
