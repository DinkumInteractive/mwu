<?php 

if ( class_exists( 'Simple_Slack' ) ) return;

class Simple_Slack {

	private $url = false;

	protected $post = array();

	public function __construct( $post ) {

		if ( ! $post ) return $this;

		$this->post = $post;

	}

	public function __get( $key ) {

		return $this->post[ $key ];

	}

	public function __set( $key, $value ) {

		$this->post[ $key ] = $value;

		return $this;

	}

	public function set_url( $url ) {

		$this->url = $url;

		return $this;

	}

	public function set_post( $post ) {

		$this->post = $post;

		return $this;

	}

	public function set_attachment( $attachment ) {

		$this->post['attachment'] = $attachments;

		return $this;

	}

	public function get_attachment( $key ) {

		if ( ! isset( $this->post['attachment'][$key] ) ) return null;

		return $this->post['attachment'][$key];

	}

	public function send() {

		if ( ! $this->url ) return;

		if ( ! $this->post ) return;

		$post = $this->post;

		$payload = json_encode( $post );

		$ch = curl_init();

		curl_setopt( $ch, CURLOPT_URL, $this->url );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json') );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );

		$result = curl_exec( $ch );

		// Check if any error occurred
		if ( curl_errno( $ch ) ) {
			//	Curl error | https://curl.haxx.se/libcurl/c/libcurl-errors.html
			echo 'Curl error: ' . curl_error( $ch );
		}

		$payload_pretty = json_encode($post,JSON_PRETTY_PRINT); 

		/*	Uncomment to debug
			var_dump($payload_pretty);
			var_dump($result);
		 */

		curl_close( $ch );

		return $result;

	}

}


function simple_slack( $url, $post ) {

	$slack = new Simple_Slack( $post );

	$slack->set_url( $url );

	return $slack;

}