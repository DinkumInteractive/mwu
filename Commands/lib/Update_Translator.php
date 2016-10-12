<?php 

if ( class_exists( 'Update_Translator' ) ) return;

class Update_Translator {


	public $data = array();

	
	public $table_reports;

	
	public function __construct( $update_notes ) {


		// 	Filter for update table
		$table = array();

		foreach ( $update_notes as $update_note ) {

			$note = $this->parse_line( $update_note );

			if ( $note ) {

				$table[] = $note;

			}

		}

		if ( $table ) {

			// 	Get plugin update notes
			$table = array_splice( $table, 1 );

			$table_data = array();

			$table_array = array();

			foreach ( $table as $value ) {

				$text = ltrim( $value, '|' );

				$text = rtrim( $text, '|' );

				$text = trim(preg_replace('/\s\s+/', ' ', str_replace("\n", " ", $text)));

				$table_data[] = explode( ' | ', $text );

			}

			foreach ( $table_data as $key => $value ) {

				$table_array[] = array(
					'name'			=> ( isset( $value[0] ) ? $value[0] : false ),
					'old_version'	=> ( isset( $value[1] ) ? $value[1] : false ),
					'new_version'	=> ( isset( $value[2] ) ? $value[2] : false ),
					'status'		=> ( isset( $value[3] ) ? $value[3] : false ),
				);

			}

			// Save parsed data
			$this->data = $table_array;

		}


	}


	public function parse_line( $str ) {

		$line = false;

		if ( $str[0] === '|' ) {

			$str = trim( $str, ' ' );

			$line = $str;

		}

		if ( $str[0] === '|' || $str[0] === '+' ) {

			$str = trim( $str, ' ' );

			$this->table_reports[] = $str;

		}

		return $line;

	}


}