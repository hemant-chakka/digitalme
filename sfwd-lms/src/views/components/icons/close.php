<?php
/**
 * View: Close Icon
 *
 * @var array<string> $classes Additional classes to add to the svg icon.
 *
 * @since 4.16.0
 * @version 4.16.0
 *
 * @package LearnDash\Core
 */

$svg_classes = [ 'ld-svgicon', 'ld-svgicon__close' ];

if ( ! empty( $classes ) ) {
	$svg_classes = array_merge( $svg_classes, $classes );
}
?>
<svg
	aria-label="<?php esc_attr_e( 'Close icon', 'learndash' ); ?>"
	class="<?php echo esc_attr( implode( ' ', $svg_classes ) ); ?>"
	role="img"
	width="17"
	height="16"
	viewBox="0 0 17 16"
	fill="none"
	xmlns="http://www.w3.org/2000/svg"
>
	<path fill-rule="evenodd" clip-rule="evenodd" d="M13.5653 2.93534C13.9244 3.29445 13.9244 3.87667 13.5653 4.23577L4.73772 13.0634C4.37862 13.4225 3.7964 13.4225 3.4373 13.0634C3.07819 12.7043 3.07819 12.122 3.4373 11.7629L12.2649 2.93534C12.624 2.57624 13.2062 2.57624 13.5653 2.93534Z"/>
	<path fill-rule="evenodd" clip-rule="evenodd" d="M3.4373 2.93534C3.7964 2.57624 4.37862 2.57624 4.73772 2.93534L13.5653 11.7629C13.9244 12.122 13.9244 12.7043 13.5653 13.0634C13.2062 13.4225 12.624 13.4225 12.2649 13.0634L3.4373 4.23577C3.07819 3.87667 3.07819 3.29445 3.4373 2.93534Z"/>
</svg>
