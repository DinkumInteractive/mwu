<?php 

function new_line() {
echo "
";
}

function long_line() {
echo " ----------------------------------------------------------------";
echo "
";
}

function format_version( $version ) {

	$trimmed = substr( $version, 0, 5 );

	$count = strlen( $trimmed );

	if ( $count < 5 ) {

		$trimmed .= '.0';

	}

	return $trimmed;

}