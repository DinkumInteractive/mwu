<?php

namespace Terminus\Commands;


use Terminus\Models\Auth;
use Terminus\Collections\Sites;
use Terminus\Commands\TerminusCommand;
use Terminus\Exceptions\TerminusException;
use Terminus\Models\Organization;
use Terminus\Models\Site;
use Terminus\Models\Upstreams;
use Terminus\Models\User;
use Terminus\Models\Workflow;
use Terminus\Session;
use Terminus\Utils;

/**
 * Actions on multiple sites
 *
 * @command sites
 */
class MassWordPressUpdateCommand extends TerminusCommand {


	public $sites;
	public $sites_list;
	private $args;
	private $assoc_args;
	private $yaml_settings;
	private $slack_settings;


	/**
	* Perform WordPress mass updates on sites.
	*
	* @param array $options Options to construct the command object
	* @return MasspluginsUpdateCommand
	*/
	public function __construct(array $options = []) {

		$options['require_login'] = true;

        $this->user = Session::getUser();

		parent::__construct($options);

		$this->sites = new Sites();

	}


	/**
	* Perform WordPress mass updates on sites.
	* Note: because of the size of this call, it is cached
	*   and also is the basis for loading individual sites by name
	*
	* ## OPTIONS
	*
	* [--env=<env>]
	* : Filter sites by environment.  Default is 'dev'.
	*
	* [--report]
	* : Display the plugins or themes that need updated without actually performing the updates.
	*
	* [--auto-commit]
	* : Commit changes with a generic message and switch back to git mode after performing the updates on each site.
	*
	* [--auto-deploy]
	* : Automatically deploy stuff to `test` and `live`.
	*
	* [--confirm]
	* : Prompt to confirm before actually performing the updates on each site.
	*
	* [--skip-backup]
	* : Skip backup before performing the updates on each site.
	*
	* [--plugins]
	* : A space separated list of specific wordpress plugins to update.
	*
	* [--team]
	* : Filter for sites you are a team member of.
	*
	* [--config-file]
	* : Path to the yml config file
	*
	* [--org=<id>]
	* : Filter sites you can access via the organization. Use 'all' to get all.
	*
	* [--name=<regex>]
	* : Filter sites you can access via name.
	*
	* @subcommand mass-wordpress-update
	* @alias mwu
	*
	* @param array $args Array of main arguments
	* @param array $assoc_args Array of associative arguments
	*
	*/
	public function massWpUpdate( $args, $assoc_args ) {


		/* 	Fetch arguments  */
		$this->args = $args;
		$this->assoc_args = $assoc_args;


		/* 	Using custom config file for update  */
		if ( count( $assoc_args ) === 1 && isset( $this->assoc_args['config-file'] ) ) {

			$this->yaml_settings = $this->yamlParseFile( $this->assoc_args['config-file'] );

		}


		/* 	Using default config file for update  */
		if ( count( $this->assoc_args ) === 0 ) {

			$default_config_file = dirname(__DIR__) . '/sites-config.yml';

			$this->yaml_settings = $this->yamlParseFile($default_config_file);

		}


		/* 	Using slack for notification  */
		if ( isset( $this->yaml_settings['slack_settings'] ) ) {

			$this->slack_settings = $this->yamlGetSlackSettings();

		}


		/* 	Fetch list of sites  */
		$options = array();

		if ( ! isset( $this->assoc_args['config-file'] ) ) {

			$options = array(
				'org_id'	=> $this->input()->optional(
					array(
						'choices'	=> $this->assoc_args,
						'default'	=> null,
						'key'		=> 'org',
					)
				),
				'team_only'	=> isset( $this->assoc_args['team'] ),
			);

		}

		$this->sites->fetch( $options );


		/* 	Filter by name  */
		if ( isset( $this->assoc_args['name'] ) ) {

			$this->sites->filterByName( $this->assoc_args['name'] );

		}


		/* 	Filter specific sites by uuid 
			NOTE: not working in version 0.13.2

		if ( isset( $this->assoc_args['owner'] ) ) {

			$owner_uuid = $this->assoc_args['owner'];

			if ( $owner_uuid == 'me' ) {

				$owner_uuid = $this->user->id;

			}

			$this->sites->filterByOwner($owner_uuid);

		}
		*/


		/*	Check environment validity  */
		$env = isset( $this->assoc_args['env'] ) ? $this->assoc_args['env'] : 'dev';

		$valid_envs = array( 'dev', 'test', 'live' );

		$valid_env = in_array( $env, $valid_envs );

		if ( ! $valid_env ) {

			foreach ( $sites as $site ) {

				$environments = $site->environments->all();

				foreach ($environments as $environment) {

					$e = $environment->get('id');

					if ( $e == $env ) {
						$valid_env = true;
						break;
					}

				}

				if ( $valid_env ) {
					break;
				}
			}
		}


		/*	Update fetched sites list  */
		$sites = $this->sites->all();

		if ( count( $sites ) == 0 ) {

			$this->failure( 'Given arguments failed to find any sites.' );

		} else {

			$sites_queue = array();
			
			foreach (  $sites as $site ) {

				$args = array(
					'name'      => $site->get( 'name' ),
					'env'       => $env,
					'framework' => $site->get( 'framework' ),
				);

				if ( $this->yaml_settings ) {

					$site_args = $this->yamlGetSiteArgs( $site->get( 'name' ) );

					if ( $site_args ) {

						$args = array_merge( $args, $site_args );

						$sites_queue[] = $args;

					} else {

						$this->log()->info( '{name} excluded from updates', array( 'name' => $site->get('name') ) );

					}

				} else {

					$sites_queue[] = $args;

				}

			}

			if ( $sites_queue ) {

				foreach ( $sites_queue as $site_args ) {

					$this->plugins_update( $site_args, $assoc_args );

				}

			}

		}


	}


	/**
	* Perform the updates on a specific site and environment.
	*
	* @param array $update_args
	*   The site environment arguments.
	*/
	private function plugins_update( $update_args, $assoc_args ) {


		// 	Get update arguments
		$name = $update_args['name'];
		$environ = $update_args['env'];
		$framework = $update_args['framework'];


		// Get additional arguments
		$report   = $this->isReport();
		$confirm  = $this->getSetting( 'confirm', false );
		$skip     = $this->getSetting( 'skip-backup', false );
		$commit   = $this->getSetting( 'auto-commit', false );
		$plugins  = $this->getSetting( 'plugins', '' );


		// Get site yaml settings if exists
		if ( $this->yaml_settings ) {

			$site_yaml_settings = $this->yamlGetSiteArgs( $name );

			if ( $site_yaml_settings ) {

				$report   = $this->yamlGetSiteSetting( $name, 'report' );
				$confirm  = $this->yamlGetSiteSetting( $name, 'confirm' );
				$commit   = $this->yamlGetSiteSetting( $name, 'auto-commit' );
				$skip     = $this->yamlGetSiteSetting( $name, 'skip-backup' );

			}

		}


		// 	Setup additional update variables
		$all = $plugins ? '' : '--all';
		$dry_run = $report ? '--dry-run' : '';
		$update_report = array();
		$unavailable_packages = array();


		// Check for valid frameworks.
		$valid_frameworks = array( 'wordpress' );

		if ( ! in_array( $framework, $valid_frameworks ) ) {

			$this->log()->error( '{framework} is not a valid framework.  WordPress updates aborted for {environ} environment of {name} site.', array(
				'framework' => $framework,
				'environ' => $environ,
				'name' => $name,
			) );

			return false;

		}


		// 	Get current connection mode
		$assoc_args = array(
			'site' => $name,
			'env'  => $environ,
		);

		$site = $this->sites->get(
			$this->input()->siteName( array( 'args' => $assoc_args ) )
		);

		$env  = $site->environments->get(
			$this->input()->env( array( 'args' => $assoc_args, 'site' => $site ) )
		);

		$mode = $env->get( 'connection_mode' );


		//	Get each environment url
		$env_url = $this->getSiteEnvUrl( $site );


		//	Get site info
		$site_info = $site->serialize();


		//	Check for pending changes in sftp mode.
		if ( $mode == 'sftp' ) {

			$diff = (array)$env->diffstat();

			if ( ! empty( $diff ) ) {

				$this->log()->error( 'Unable to update {environ} environment of {name} site due to pending changes.  Commit changes and try again.', array(
					'environ' => $environ,
					'name' => $name,
				) );

				return false;

			}

		} else {

			// Set connection mode to sftp.
			$mode = $this->setSiteConnectionMode( $name, $environ, 'sftp' );

		}


		// Prompt to confirm updates.
		if ( ! $report && $confirm ) {

			$message = 'Apply plugins updates to %s environment of %s site ';

			$confirmed = $this->input()->confirm( array(
				'message' => $message,
				'context' => array(
					$environ,
					$name,
				),
				'exit' => false,
			) );

			if ( ! $confirmed ) {
				return true; // User says No.
			}

		}

		if ( ! $report ) {

			// Beginning message.
			$this->log()->notice( 'Starting plugins updates for {environ} environment of {name} site.', array(
				'environ' => $environ,
				'name' => $name,
			) );

		}

		$proceed = true;


		//	Check for errors on site
		if ( $this->checkIsSiteDown( $env_url[$environ] ) ) {

			$this->log()->error( 'Unable to update {environ} environment of {name} site due to fatal error on site.', array(
				'environ' => $environ,
				'name' => $name,
			) );

			$proceed = false;

		}


		// 	Doing site backup
		if ( ! $skip && ! $report && $proceed ) {

			$args = array(
				'element' => 'all',
			);

			if ( $proceed = $env->backups->create( $args ) ) {

				if ( is_string( $proceed ) ) {

					$this->log()->info( $proceed );

				} else {

					$proceed->wait();

					$this->workflowOutput($proceed);

				}

			} else {

				$this->log()->error( 'Backup failed. Wordpress Plugins updates aborted for {environ} environment of {name} site.', array(
					'environ' => $environ,
					'name' => $name,
				) );

				return false;

			}

		}


		//	Doing upsteam-update
		if ( $this->isUpstreamUpdate() || $this->yamlGetSiteSetting( $name, 'upstream' ) && $proceed ) {

			// Set connection to git
			if ( $mode == 'git' ) {

				$mode = $this->setSiteConnectionMode( $name, $environ, 'sftp' );

			}

			// Perform upstream update
			$upstreamUpdate = $this->upstream_update( $name, 'dev', true );
			$proceed = $upstreamUpdate['state'];

		}


		//	Perform wordpress updates via wp-cli.
		if ( $proceed ) {

			// 	Check plugin list
			$pluginStatus = $this->getSitePluginStatus( $name, $environ );


			//	Check for available update
			$update_valid = false;

			$unavailable_packages = array();

			foreach ( $pluginStatus as $plugin ) {

				if ( $plugin->version && $plugin->update_version && $plugin->update_package ) {

					$update_valid = true;

				}

				if ( $plugin->version && $plugin->update_version && ! $plugin->update_package ) {

					$unavailable_packages[] = $plugin->name;

				}

			}


			//	Doing update.
			if ( $update_valid ) {
				
				$wp_options = trim("plugin update $plugins $all $dry_run");

				$tm_command = "terminus wp --site=$name --env=$environ \"$wp_options\"";

				$update_site_err = array(
					'message' => 'Unable to perform plugins updates for {environ} environment of {name} site.',
					'args'    => array(
						'environ' => $environ,
						'name'    => $name,
					),
				);

				//	Set connection back to sftp.
				if ( $mode == 'git' ) {

					$mode = $this->setSiteConnectionMode( $name, $environ, 'sftp' );

				}

				$update_site = $this->execute( $tm_command, false, true, $update_site_err );

				$proceed = $update_site['state'];

				$update_report = ( isset( $update_site['data'] ) ? $update_site['data'] : false );

			} else {

				$this->log()->notice( 'There are no available update found in {environ} environment of {name} site.', array(
					'environ' => $environ,
					'name' => $name,
				) );

			}

			//	Reload the environment.
			$env  = $site->environments->get(
				$this->input()->env( array( 'args' => $assoc_args, 'site' => $site ) )
			);

		}


		//	Check site health
		$errEnvs = array();

		$isError = false;

		foreach ( $env_url as $env_name => $url ) {

			$error = $this->checkIsSiteDown( $env_url[$environ] );

			if ( $error ) {

				$isError = true;

			}

			$errEnvs[$env_name] = $error;

		}

		if ( $isError ) {

			$proceed = false;

			foreach ( $errEnvs as $env_name => $value ) {

				if ( $value ) {

					$this->log()->notice( 'Fatal error found in {environ} environment of {name} site. Auto commit and deploy are not performed.', array(
						'environ' => $env_name,
						'name' => $name,
					) );

				}

			}

		}


		//	Commit all changes
		if ( ! $report && ! $isError && $commit ) {


			//	Set connection back to sftp.
			if ( $mode == 'git' ) {

				$mode = $this->setSiteConnectionMode( $name, $environ, 'sftp' );

			}

			$message = 'Updates applied by Mass Wordpress Update terminus plugin.';


			// 	Commit changes
			if ( $workflow = $env->commitChanges( $message ) ) {

				if ( is_string( $workflow ) ) {

					$this->log()->info( $workflow );

				} else {

					$workflow->wait();

					$this->workflowOutput( $workflow );
				}

			} else {

				$this->log()->error(
					'Unable to perform automatic update commit for the {environ} environment of {name} site.',
					array( 'environ' => $environ, 'name' => $name )
				);

				return false;

			}


		}


		//	Doing auto-deploy
		if ( $proceed && ( $this->isAutoDeploy() || $this->yamlGetSiteSetting( $name, 'auto-deploy' ) ) ) {

			// Set connection back to sftp.
			if ( $mode == 'git' ) {

				$mode = $this->setSiteConnectionMode( $name, $environ, 'sftp' );

			}

			$autoDeployTest = $this->auto_deploy( $name, 'test' );

			$autoDeployLive = $this->auto_deploy( $name, 'live' );

		}


		//	Completion message.
		if ( ! $report && ! $isError ) {

			$this->log()->notice( 'Finished plugins updates for {environ} environment of {name} site.', array(
				'environ' => $environ,
				'name' => $name,
			) );

		}


		// 	Set site connection mode back to git
		if ( ! $report && ! $isError ) {

			$mode = $this->setSiteConnectionMode( $name, $environ, 'git' );

		}


		//	Send slack message
		if ( isset( $this->yaml_settings['slack_settings'] ) ) {

			$date = new \DateTime();

			$slack_notif_text = "";

			$slack_notif_text .= "*Terminus update report on " . $name . "*\n\n";

			$update_report = $this->getUpdateNotes( $update_report );

			$team_member = $this->getSlackTeamMember( $name );

			if ( $isError ) {

				$slack_notif_text .= 'Fatal error found on following environments.' . "\n";

				$slack_notif_text .= "```";

				foreach ( $errEnvs as $errEnvName => $errEnvStatus ) {

					if ( $errEnvStatus ) {

						$slack_notif_text .= $errEnvName . "\n";

					}

				}

				$slack_notif_text .= "```" . "\n";

			}

			if ( $update_report ) {

				$slack_notif_text .= "```";

				foreach ( $update_report as $report ) {

					$slack_notif_text .= $report . "\n";

				}

				$slack_notif_text .= "```" . "\n";

			} else {

				$slack_notif_text .= 'No plugin update performed.' . "\n";

			}
			
			if ( $unavailable_packages ) {

				$slack_notif_text .= 'Unavailable plugin update found.' . "\n";

				$slack_notif_text .= "```";

				foreach ( $unavailable_packages as $unavailable_package ) {

					$slack_notif_text .= '- ' . $unavailable_package . "\n";

				}

				$slack_notif_text .= "```";

			}

			$payload = array(
				'username'		=> $this->slack_settings['username'],
				'channel'		=> $this->slack_settings['channel'],
				'icon_emoji'	=> $this->slack_settings['icon_emoji'],
				'text'			=> $slack_notif_text,
				'mrkdwn'		=> true,
				'attachments'	=> array( array(
					'fallback'		=> 'Terminus MWU message.',
					'color'			=> '#ffb305',
					'author_name'	=> 'Terminus MWU',
					'fields'		=> array(
						array(
							'title'		=> 'Dashboard',
							'value'		=> "<https://dashboard.pantheon.io/sites/". $site_info['id'] . "|dashboard.pantheon.io>",
							'short'		=> true
						),
						array(
							'title'		=> 'Dev',
							'value'		=> ( isset( $env_url['dev'] ) ? $env_url['dev'] : 'no link provided.' ),
							'short'		=> true
						),
						array(
							'title'		=> 'Test',
							'value'		=> ( isset( $env_url['test'] ) ? $env_url['test'] : 'no link provided.' ),
							'short'		=> true
						),
						array(
							'title'		=> 'Live',
							'value'		=> ( isset( $env_url['live'] ) ? $env_url['live'] : 'no link provided.' ),
							'short'		=> true
						),
					),
					'image_url'		=> 'http://my-website.com/path/to/image.jpg',
					'thumb_url'		=> 'http://example.com/path/to/thumb.png',
					'footer'		=> 'Updates performed in: ',
					'footer_icon'	=> 'https://platform.slack-edge.com/img/default_application_icon.png',
					'ts'			=> $date->getTimestamp()
				) ),
			);

			$slack = simple_slack( $this->slack_settings['url'], $payload );

			$result = $slack->send();


			// 	Send message to configured team member
			if ( $unavailable_packages && $team_member || $isError ) {

				foreach ( $team_member as $member ) {

					$memberChannel = "@$member";

					$payload['channel'] = "@$member";

					$slack = simple_slack( $this->slack_settings['url'], $payload );

					$result = $slack->send();

				}

			}


		}


	}


	/**
	* Perform upstream updates on site.
	*
	* @param string $command
	*   The name of the target site
	* @param array || boolean : $onStart ( 'message' => string, $args => array() )
	*   Message before execution
	* @param array || boolean : $onSuccess ( 'message' => string, $args => array() )
	*   Message if execution suceeded
	* @param array || boolean : $onError ( 'message' => string, $args => array() )
	*   Message if execution failed
	*/
	private function execute( $command, $onStart = false, $onSuccess = false, $onError = false ) {

		// Value to return
		$response = array(
			'state' => true,
			'data'  => false,
		);

		// Notice before executing something
		if ( $onStart ) {

			$message = 'Starting command "{command}".';

			$messageArgs = array(
				'command'     => $command,
			);

			if ( ! is_bool( $onStart ) && $onStart ) {

				$message = ( isset( $onStart['message'] ) ? $onStart['message'] : $message );

				$messageArgs = ( isset( $onStart['args'] ) ? $onStart['args'] : $messageArgs );

			}

			$this->log()->notice( $message, $messageArgs );

		}


		// Execute commands
		$exec = exec( $command, $update_array, $update_error );


		// Error or something happened
		if ( $onError && $update_error ) {

			$message = 'Command have error result: {error}".';

			$messageArgs = array(
			'error'	=> ( is_array( $update_error ) ? implode( "\n", $update_error ) : $update_error ),
			);

			if ( ! is_bool( $onError ) && $onError ) {

				$message = ( isset( $onError['message'] ) ? $onError['message'] : $message );

				$messageArgs = ( isset( $onError['args'] ) ? $onError['args'] : $messageArgs );

			}

			$this->log()->error( $message, $messageArgs );

			$response['state'] = false;

			$response['data'] = $update_error;

			$response['error'] = $update_error;

		}

		// Display output of update results.
		if ( $onSuccess && $update_array ) {

			$message = implode( "\n", $update_array );

			$messageArgs = array();

			if ( ! empty( $update_array ) ) {

				if ( ! is_bool( $onSuccess ) && $onSuccess ) {

					$message = ( isset( $onSuccess['message'] ) ? $onSuccess['message'] : $message );

					$messageArgs = ( isset( $onSuccess['args'] ) ? $onSuccess['args'] : $messageArgs );

				}

				$this->log()->notice( $message, $messageArgs );

				$response['state'] = true;

				$response['data'] = $update_array;

				return $response;

			}

		}

		return $response;

	}


	/**
	* Perform upstream updates on site.
	*
	* @param string $siteName
	*   The name of the target site
	* @param string $toEnv
	*   Target site environment to deploy to
	* @param string $apply
	*   Apply upstream if true, inspect if false
	* @param array $args
	*   Additional arguments ( accept-upstream : bool, updatedb : bool )
	*/
	private function upstream_update( $siteName, $toEnv, $apply, $args = array() ) {

		$command =  'echo y | terminus site upstream-updates';

		$command .= ( $apply ? ' apply' : ' list' );

		$command .= ' --site=' . $siteName;

		$command .= ' --env=' . $toEnv;

		$default_args = array(
			'accept-upstream' => true,
			'updatedb'        => true,
		);

		$args = array_merge( $default_args, $args );

		if ( $args ) {

			foreach ( $args as $key => $value ) {

				switch ( $key ) {

					case 'accept-upstream':
						$command .= ( $value ? ' --accept-upstream' : '' );
					break;

					case 'updatedb':
						$command .= ( $value ? ' --updatedb' : '' );
					break;

				}

			}

		}

		$upstream = $this->execute( $command, true, true, true );

		return $upstream;

	}


	/**
	* Perform the updates on a specific site and environment.
	*
	* @param string $siteName
	*   The name of the target site
	* @param string $toEnv
	*   Target site environment to deploy to
	* @param array $args
	*   Additional arguments ( cc : bool, updatedb : bool, note: string )
	*/
	private function auto_deploy( $siteName, $toEnv, $args = array() ) {

		$fromEnv = ( $toEnv === 'live' ? 'test' : 'dev' );

		$command =  'terminus site deploy';

		$command .= ' --site=' . $siteName;

		$command .= ' --env=' . $toEnv;

		$default_args = array(
			'cc'        => true,
			'note'      => 'Deployed files from '. $fromEnv .' to '. $toEnv .'.',
		);

		$err = array(
			'message' => 'Unable to perform auto-deploy from {fromEnv} to {toEnv} environment of site: {siteName} .',
			'args'    => array(
				'fromEnv'     => $fromEnv,
				'toEnv'       => $toEnv,
				'siteName'    => $siteName,
			),
		);

		$args = array_merge( $default_args, $args );

		if ( $args ) {

			foreach ( $args as $key => $value ) {

				switch ( $key ) {

					case 'cc':
						$command .= ( $value ? ' --cc' : '' );
					break;

					case 'note':
						$command .= ( $value ? ' --note="' . $value . '"': '' );
					break;

				}

			}

		}

		$auto_deploy = $this->execute( $command, true, true, $err );

		return $auto_deploy;

	}


	/**
	* Change a site's connection mode
	* @param $siteName : name of the site
	* @param $siteEnv  : target environment of the site
	* @param $siteCon  : target connection mode
	*/
	private function setSiteConnectionMode( $siteName, $siteEnv, $siteCon ) {

		$this->execute( 'terminus site set-connection-mode --site='. $siteName .' --env='. $siteEnv .' --mode='. $siteCon , true, true, true );

		return $siteCon;

	}


	/**
	* Get yaml file from specified file location.
	*
	* @param string $dir
	*   File location of yaml config file.
	*/
	private function yamlParseFile( $dir ){

		if ( ! file_exists( $dir ) ) {

			$this->failure( 'File ' . $dir . ' does not exists.' );

			return false;

		}

		require_once 'lib/Spyc.php';

		$yaml_settings = spyc_load_file( $dir );

		$yaml_settings = $this->yamlValidateSettings( $yaml_settings );

		return $yaml_settings;

	}


	/**
	* Get a site all yaml configuration.
	*
	* @param string $name
	*   Name of the site.
	*/
	private function yamlGetSiteArgs( $name ){

		if ( ! $this->yaml_settings ) return false;

		$args = false;

		foreach ( $this->yaml_settings['sites']['update'] as $key => $value ) {

			if ( $value['name'] === $name ) {

				$args = $value;

				if ( isset( $this->yaml_settings['sites']['settings'] ) ) {

					$args = array_merge( $this->yaml_settings['sites']['settings'], $args );

				}

				return $args;

			}

		}

		return $args;

	}


	/**
	* Get a specific site setting.
	*
	* @param string $name
	*   Name of the site.
	* @param string $key
	*   Key of value needed.
	*/
	private function yamlGetSiteSetting( $name, $key ){

		if ( ! $this->yaml_settings ) return false;

		$settings = $this->yamlGetSiteArgs( $name );

		$value = ( isset( $settings[$key] ) ? $settings[$key] : false  );

		return $value;

	}


	/**
	* Get a specific site setting.
	*
	* @param array $settings
	*   Validate yaml settings and returns filtered one.
	*/
	private function yamlValidateSettings( $settings ){

		// we can add config validations here
		return $settings;

	}


	/**
	* Get slack setting.
	*
	*/
	private function yamlGetSlackSettings() {

		if ( ! $this->yaml_settings ) return false;

		if ( ! isset( $this->yaml_settings['slack_settings'] ) ) return false;

		$slack_settings = $this->yaml_settings['slack_settings'];

		foreach ( $slack_settings as $key => $value ) {

			switch ( $key ) {

				case 'channel':
				$slack_settings[$key] = "#{$value}";
				break;

				case 'icon_emoji':
				$slack_settings[$key] = ":{$value}:";
				break;

			}

		}

		if ( $slack_settings ) {

			require_once 'lib/Simple_Slack.php';

		}

		return $slack_settings;

	}


	/**
	* Parse update notes to array table
	*
	*/
	private function getUpdateNotes( $update_notes ) {

		require_once( 'lib/Update_Translator.php' );

		$data = new \Update_Translator( $update_notes );

		return $data->table_reports;

	}


	/**
	* Parse update notes to get keyed array
	*
	*/
	private function getUpdateData( $update_notes ) {

		require_once( 'lib/Update_Translator.php' );

		$data = new \Update_Translator( $update_notes );

		return $data->data;

	}


	/**
	* Get dev, test, and live url of a site
	*
	*/
	private function getSiteEnvUrl( $site ) {

		$env_url = array();

		foreach ( $site->environments->all() as $environment ) {

			$info = $environment->serialize();

			if ( isset( $info['id'] ) ) {

				switch ( $info['id'] ) {

					case 'dev':
					case 'test':
					case 'live':

						$env_url[$info['id']] = $info['domain'];

						break;

				}

			}

		}

		return $env_url;

	}


	/**
	* Function to get dry run data
	*
	*/
	private function getSitePluginStatus( $name, $environ ) {

		$result = false;

		$wp_options = "plugin list --all --fields=name,status,version,update_version,update_package --format=json";

		$tm_command = "terminus wp \"$wp_options\" --site=$name --env=$environ";

		$exec = exec( $tm_command, $on_success, $on_error );

		if ( $on_success && isset( $on_success[0] ) ) {

			$result = json_decode( $on_success[0] );

		}

		return $result;

	}


	/**
	* Function to alert slack user of unavailable packages
	*
	*/
	private function getSlackTeamMember( $site_name ) {

		$team_member_name = $this->yamlGetSiteSetting( $site_name, 'err_notify' );

		if ( ! $team_member_name ) {

			$team_member_name = ( isset( $this->yaml_settings['slack_settings']['err_notify'] ) ? $this->yaml_settings['slack_settings']['err_notify'] : false );

		}

		return $team_member_name;

	}


	/**
	* Function to check for string in a site
	*
	*/
	private function siteFindStr( $url, $find ) {

		$ch = curl_init( $url );

		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );

		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$text = curl_exec( $ch );

		$test = strpos( $text, $find );

		if ( $test === false ) return false;

		return true;

	}


	/**
	* Function to check if a site is down
	*
	*/
	private function checkIsSiteDown( $url ) {

		exec( 'curl -sL -w "%{http_code}" "'. $url .'"', $on_success );

		preg_match_all( '!\d+!', end( $on_success ), $matches );

		$code = intval( implode( ' ', $matches[0] ) );

		$err_string = 'Fatal error:'; 

		// $err_string = '<b>Fatal error</b>:'; 

		if ( $code === 500 ) return true;

		if ( $on_success ) {

			foreach ( $on_success as $value ) {
				
				$test = ( strpos( $value, $err_string ) !== false );

				if ( $test ) return true;

			}

		}

		return false;

	}


	/* Validations */

	private function getSetting( $name, $default_value = false ) {

		return isset($this->assoc_args[$name]) ? true : $default_value;

	}

	private function isReport() {

		return isset($this->assoc_args['report']) ? true : false;

	}

	private function isConfigFile() {

		return isset($this->assoc_args['config-file']) ? true : false;

	}

	private function isAutoDeploy() {

		if ( $this->isConfigFile() ) return false;

		return isset($this->assoc_args['auto-deploy']) ? true : false;

	}

	private function isUpstreamUpdate() {

		if ( $this->isConfigFile() ) return false;

		return isset($this->assoc_args['upstream']) ? true : false;

	}


}