<?php
/**
 * View: Alert Icon.
 *
 * @var array<string> $classes Additional classes to add to the svg icon.
 *
 * @since 4.20.1
 * @version 4.20.1
 *
 * @package LearnDash\Core
 */

$svg_classes = [ 'ld-svgicon', 'ld-svgicon__alert' ];

if ( ! empty( $classes ) ) {
	$svg_classes = array_merge( $svg_classes, $classes );
}

?>
<svg
	class="<?php echo esc_attr( implode( ' ', $svg_classes ) ); ?>"
	aria-label="<?php esc_attr_e( 'Alert icon', 'learndash' ); ?>"
	role="img"
	width="12"
	height="13"
	viewBox="0 0 12 13"
	fill="none"
	xmlns="http://www.w3.org/2000/svg"
>
	<path fill-rule="evenodd" clip-rule="evenodd" d="M6 0.199953C9.31371 0.199953 12 2.88624 12 6.19995C12 9.51366 9.31371 12.2 6 12.2C2.68629 12.2 2.34843e-07 9.51366 5.24537e-07 6.19995C8.1423e-07 2.88624 2.68629 0.199952 6 0.199953ZM6.9 3.49995L6.9 3.01995C6.9 2.52269 6.49706 2.11995 6 2.11995C5.50396 2.11995 5.1 2.5229 5.1 3.01995L5.1 3.49995C5.1 3.99721 5.10094 7.87995 6 7.87995C6.89906 7.87995 6.9 3.99701 6.9 3.49995ZM6 8.47995C5.50294 8.47995 5.1 8.88289 5.1 9.37995C5.1 9.87701 5.50294 10.28 6 10.28C6.49706 10.28 6.9 9.87701 6.9 9.37995C6.9 8.88289 6.49706 8.47995 6 8.47995Z"/>
</svg>
