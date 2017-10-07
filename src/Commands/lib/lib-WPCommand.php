<?php 
if ( class_exists( 'MWU_WPCommand' ) ) return;

class MWU_WPCommand {

	protected $env;

	public function __construct( $env ) {

		$this->env = $env;

	}

	private function run( $command, $return_raw = false ) {

		$result = $this->env->sendCommandViaSsh( $command );

		return ( $return_raw ? $result : $this->parse( $result ) );

	}

	private function parse( $str ) {

		return json_decode( $str['output'] );

	}

	public function get_plugin_list() {

		$fields = array(
			'name',
			'status',
			'version',
			'update',
			'update_version',
			'update_package',
		);

		$command = 'wp plugin list --format=json --fields=' . implode( ',', $fields );

		return $this->run( $command );

	}

	public function has_update() {

		$has_update = false;

		$plugins = $this->get_plugin_list();

		foreach ( $plugins as $plugin ) {

			if ( 'available' === $plugin->update && 'active' === $plugin->status ) {

				$has_update = true;

				break;

			}
			
		}

		return $has_update;

	}

	public function get_update_list() {

		$update_list = array();

		$plugins = $this->get_plugin_list();

		foreach ( $plugins as $plugin ) {

			if ( 'available' === $plugin->update ) {

				$update_list[] = $plugin;

			}
			
		}

		return $update_list;

	}

	public function is_error() {

		$command = "wp option get siteurl";

		$result = $this->run( $command, true );

	}

	public function update_plugins() {

		$command = "wp plugin update --all --format=json";

		$result = $this->run( $command, true );

		$response = array();

		if ( isset( $result['output'] ) ) {
			
			$response = json_decode( $result['output'] );

		}

		return $response;

	}

}