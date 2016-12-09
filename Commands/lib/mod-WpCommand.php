<?php 

use Terminus\Collections\Sites;

class modWpCommand extends \Terminus\Commands\WpCommand {


	/*	Source 	: Terminus\Commands\WpCommand.php
		L40 	: public function __invoke
	 */
	public function __invoke( $args, $assoc_args, $skip_check = true ) {

		$command = array_shift( $args );

		$this->ensureCommandIsPermitted($command);

		$sites = new Sites();

		$site = $sites->get($this->input()->siteName(['args' => $assoc_args,]));

		$this->environment = $site->environments->get(
			$this->input()->env(['args' => $assoc_args, 'site' => $site,])
		);

		if ( ! $skip_check ) {

			$this->checkConnectionMode($this->environment);

		}

		$this->ssh_command = "{$this->command} $command";

        $result = $this->environment->sendCommandViaSsh($this->ssh_command);

        return $result;

	}


}