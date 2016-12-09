<?php

namespace Terminus\Commands;


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

	private $assoc_args;
	private $settings;
	private $queue;


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

		$this->load_helpers();

	}


	/**
	* Perform WordPress mass updates on sites.
	* Note: because of the size of this call, it is cached
	*   and also is the basis for loading individual sites by name
	*
	* ## OPTIONS
	*
	* [--upstream]
	* : Apply upstream updates to site and check if it caused any error.
	*
	* [--auto-commit]
	* : Commit changes with a generic message and switch back to git mode after performing the updates on each site.
	*
	* [--auto-deploy]
	* : Automatically deploy stuff to `test` and `live`.
	*
	* [--skip-backup]
	* : Skip backup before performing the updates on each site.
	*
	* [--name=<regex>]
	* : Filter sites you can access via name.
	*
	* [--env=<env>]
	* : Filter sites by environment.  Default is 'dev'.
	*
	* [--team]
	* : Filter for sites you are a team member of.
	*
	* [--owner]
    * : Filter for sites a specific user owns. Use "me" for your own user.
    *
    * [--org=<id>]
    * : Filter sites you can access via the organization. Use 'all' to get all.
    *
	* [--config-file]
	* : Path to the yml config file
	*
	* @subcommand mass-wordpress-update
	* @alias mwu
	*
	* @param array $args Array of main arguments
	* @param array $assoc_args Array of associative arguments
	*
	*/
	public function massWpUpdate( $args, $assoc_args ) {


		// 	process and validate command arguments into settings
		$this->process_arguments( 'start',  $args, $assoc_args );


		// 	build update queue
		$this->create_queue();


		// 	write queue summary
		if ( count( $this->queue ) === 0 ) {

			$this->failure( 'Given arguments failed to find any sites.' );

		} else {

			$queue_total = count( $this->queue );

			$this->notice( "Updating $queue_total sites:" );

			$i = 1;

			foreach ( $this->queue as $index => $queue ) {

				$this->notice( '=> ' . $queue['name'], false );

				$i++;

			}

		}


		// 	process queue
		new_line();

		foreach ( $this->queue as $index => $queue ) {

			$name = $queue['name'];

			long_line();

			$this->notice( '| ' . $queue['name'], false );

			long_line();

			$this->notice( "=> starting update." );

			$report = $this->process_queue( $queue );

			if ( isset( $this->settings['slack_settings'] ) && $report['report'] || $report['error'] ) {

				$this->notice( "=> sending message to slack", false );

				$this->send_to_slack( $queue, $report );

			}

			$this->notice( "=> finished updating.", false );

			new_line();

			new_line();

		}


	}


	// 	Create update queue
	public function create_queue() {

		// 	exit if no sites found
		if ( ! isset( $this->settings['sites']['update'] ) || count( $this->settings['sites']['update'] ) === 0 ) {

			$this->failure( 'Given arguments failed to find any sites.' );

		}

		// 	creating queue
		$this->queue 	= array();

		$sites 			= $this->settings['sites']['update'];

		$settings 		= $this->settings['sites']['settings'];

		$err_notify 	= ( isset( $this->settings['slack_settings']['err_notify'] ) ? $this->settings['slack_settings']['err_notify'] : false );

		foreach ( $sites as $site ) {

			$this->queue[] = array(
				'name'			=> $site['name'],
				'env'			=> ( isset( $site['env'] ) ? $site['env'] : $settings['env'] ),
				'upstream'		=> ( isset( $site['upstream'] ) ? $site['upstream'] : $settings['upstream'] ),
				'auto-commit'	=> ( isset( $site['auto-commit'] ) ? $site['auto-commit'] : $settings['auto-commit'] ),
				'skip-backup'	=> ( isset( $site['skip-backup'] ) ? $site['skip-backup'] : $settings['skip-backup'] ),
				'auto-deploy'	=> ( isset( $site['auto-deploy'] ) ? $site['auto-deploy'] : $settings['auto-deploy'] ),
				'err_notify'	=> ( isset( $site['err_notify'] ) ? $site['err_notify'] : $err_notify ),
			);

		}

	}


	// 	Run update on queue
	public function process_queue( $queue ) {


		// 	Response
		$response = array(
			'member'	=> array(),
			'message'	=> array(
				'skip_backup'	=> false,
				'upstream'		=> false,
				'updates'		=> false,
				'auto_commit'	=> false,
				'auto_deploy'	=> array(),
				'log'			=> array(),
			),
			'error'		=> false,
			'report'	=> false,
		);


		// 	Create site object
		$args = array(
			'site'	=> $queue['name'],
			'env'	=> $queue['env'],
		);

		// 	Check if site is available
		try {
			
			$site = $this->sites->get( $this->input()->siteName( array(
		    	'args' => $args
		    ) ) );

		} catch ( TerminusException $e ) {

			$error_message = '=> invalid site id.';

			$this->notice( $error_message, false );

			return false;
			
		}

	    $env  = $site->environments->get(
			$this->input()->env( array( 'args' => $args, 'site' => $site ) )
		);

		
		// 	Queue's Variables
		$name 			= $site->get( 'name' );
		$framework 		= $site->get( 'framework' );
		$mode 			= $site->get( 'connection_mode' );
		
		$environ 		= $queue['env'];
		$upstream		= $queue['upstream'];
		$auto_commit	= $queue['auto-commit'];
		$skip_backup	= $queue['skip-backup'];
		$auto_deploy	= $queue['auto-deploy'];
		$err_notify		= $queue['err_notify'];


	    /*	Validations
	    	- exit if site is not using wordpress
	    	- exit if site is in SFTP and have pending changes

	     */
		if ( 'wordpress' != $framework ) {

			$error_message = "=> not a valid WordPress environment.";

			$response['message']['log'][] = $error_message;

			$this->notice( $error_message );

			return $response;

		}

		if ( 'sftp' == $this->connection_info( $name, $environ ) ) {

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

			$diff = (array)$env->diffstat();

			if ( ! empty( $diff ) ) {

				$error_message = "unable to update $environ environment due to pending changes.  Commit changes and try again.";

				$response['error'] = true;

				$response['message']['log'][] = $error_message;

				$this->notice( '=> ' . $error_message, false );

				return $response;

			}

		}


		// 	backup
		if ( $skip_backup ) {

			$this->notice( "=> skipped backup.", false );

		} else {

			$this->notice( "=> creating backup ", false, false );

			$backup_response = $this->create_backup( $name, $environ );

			$response['message']['skip_backup'] = $this->process_response( 'create_backup', $backup_response );
			
			$this->notice( "=> " . $response['message']['skip_backup'], false );

		}

		// 	upstream update
		if ( $upstream ) {

			if ( 'dev' != $environ ) {

				$this->notice( "=> cannot apply upstream on non dev environment.", false );

			} else {

				// 	check for available upstream.
				$this->notice( "=> checking for available updates.", false );

				$available_update = $this->check_upstream( $name );

				if ( $available_update ) {

					$this->notice( "=> upstream update available, beginning update ", false, false );

					$mode = $this->connection_info( $name, $environ );

					//	change connection mode
					if ( 'sftp' == $mode ) {

						$this->change_connection( $name, $environ, 'git' );

					}

					// 	apply upstream
					$this->notice( "=> applying upstream update ", true, false );

					$upstream_response = $this->apply_upstream( $name );

					$response['report'] = true;

					$response['message']['upstream'] = $this->process_response( 'upstream', $upstream_response );
					
					$this->notice( "=> " . $response['message']['upstream'], false );

					// 	check error
					$this->notice( "=> checking for error after upstream updates", false, false );

					$update_error = $this->site_error( $name, 'dev' );

					if ( $update_error ) {

						$upstream_error_message = 'error found on site after applying upstream updates.';

						$response['error'] = true;

						$response['message']['log'][] = $upstream_error_message;

						$this->notice( "=> $upstream_error_message" );

						return $response;

					} else {

						$this->notice( "=> no error found after upstream updates.", true, false );

					}

				} else {

					$unavailable_message = 'no available upstream updates found.';

					$response['message']['upstream'] = $unavailable_message;

					$this->notice( "=> $unavailable_message", false );

				}

			}

		} else {

			$this->notice( "=> skipped applying upstream.", false );

		}

		// 	plugin update
		if ( ! $response['error'] ) {

			// 	check for error
			if ( ! $upstream || ! $response['error'] ) {

				$this->notice( "=> checking site health for plugins update", false );

				$site_error = $this->site_error( $name, 'dev' );

				if ( $site_error ) {

					$site_error_message = 'error found when checking for plugin list.';

					$response['error'] = true;

					$response['message']['log'][] = $site_error_message;

					$this->notice( "=> $site_error_message" );

					return $response;

				}

			}

			$available_update = array();

			$this->notice( "=> checking plugin list", false );

			$plugin_list = $this->get_plugin_list( $name, $environ );

			if ( $plugin_list ) {

				foreach ( $plugin_list as $plugin ) {

					if ( 'available' == $plugin->update ) {

						$available_update[] = $plugin->name;

					}

				}

				if ( $available_update ) {

					$this->notice( "=> avaliable plugins for update [" . implode( ', ', $available_update ) . ']', false );
					
					if ( 'git' == $this->connection_info( $name, $environ ) ) {

						$this->notice( "=> changing connection mode to sftp ", false, false );

						$this->change_connection( $name, $environ, 'sftp' );

					}

					$this->notice( "=> updating plugins ", false, false );

					$plugins_update = $this->update_plugins( $name, $environ );

					$response['report'] = true;

					$response['message']['updates'] = $this->process_response( 'updates', $plugins_update );
					
					$this->notice( "=> " . $response['message']['updates'], true );

				}

			} else {

				$this->notice( "=> plugins not found.", false );

			}

			if ( $response['message']['updates'] ) {

				// 	check error
				$this->notice( "=> checking for error after plugins update", false, false );

				$update_error = $this->site_error( $name, 'dev' );

				if ( $update_error ) {

					$plugin_error_message = 'error found on site after plugins update.';

					$response['error'] = true;

					$response['message']['log'][] = $plugin_error_message;

					$this->notice( "=> $plugin_error_message" );

					return $response;

				} else {

					$this->notice( "=> no error found after plugins update.", true, false );

					$this->notice( "=> commiting changes on site ", true, false );

					$commit_message = 'Updates applied by Mass Wordpress Update terminus plugin.';

					$commit = $this->commit( $name, $environ, $commit_message );

					$response['message']['auto_commit'] = $this->process_response( 'auto_commit', $commit );
					
					$this->notice( "=> " . $response['message']['auto_commit'], false );

				}

			}

		}

		// 	deploy to test and live
		if ( $auto_deploy && 'dev' == $environ &&  ! $response['error'] ) {

			// 	check for error
			if ( ! $upstream && ! $response['message']['updates'] ) {

				$this->notice( "=> checking site health for deployment.", false );

				$site_error = $this->site_error( $name, 'dev' );

				if ( $site_error ) {

					$site_error_message = 'fatal error found on site, deploying aborted.';

					$response['error'] = true;

					$response['message']['log'][] = $site_error_message;

					$this->notice( "=> $site_error_message" );

					return $response;

				}

			}


			// 	Deploy to test
			$this->notice( "=> deploying to test ", false, false );

			$deploy_test = $this->deploy( $name, 'test', 'Deployed files from dev to test' );

			if ( is_null( $deploy_test['data'] ) ) {

				$response['message']['auto_deploy']['test'] = $deploy_test['message'];

			} else {

				$response['report'] = true;

				$response['message']['auto_deploy']['test'] = $this->process_response( 'auto_deploy', $deploy_test );

			}
					
			$this->notice( "=> " . $response['message']['auto_deploy']['test'], false );


			//	check for error on test after deploy
			$this->notice( "=> checking test environment health ", false, false );

			$site_error = $this->site_error( $name, 'test' );

			if ( $site_error ) {

				$site_error_message = 'fatal error found after deploying to test.';

				$response['error'] = true;

				$response['message']['auto_deploy']['test'] = $site_error_message;

				$response['message']['auto_deploy']['live'] = 'live environment deployment aborted.';

				$this->notice( "=> $site_error_message" );

				return $response;

			}


			// 	Deploy to live
			$this->notice( "=> deploying to live ", false, false );

			$deploy_live = $this->deploy( $name, 'live', 'Deployed files from test to live' );

			if ( is_null( $deploy_live['data'] ) ) {

				$response['message']['auto_deploy']['live'] = $deploy_live['message'];

			} else {

				$response['report'] = true;

				$response['message']['auto_deploy']['live'] = $this->process_response( 'auto_deploy', $deploy_live );

			}

			$this->notice( "=> " . $response['message']['auto_deploy']['live'], false );


			//	check for error on live after deploy
			$this->notice( "=> checking live environment health ", false, false );

			$site_error = $this->site_error( $name, 'live' );

			if ( $site_error ) {

				$site_error_message = 'fatal error found after deploying to live.';

				$response['error'] = true;

				$response['message']['auto_deploy']['live'] = $site_error_message;

				$this->notice( "=> $site_error_message" );

				return $response;

			}


		}

		// 	change back to git mode
		if ( 'sftp' == $this->connection_info( $name, $environ ) && ! $response['error']) {

			$this->notice( "=> changing connection mode to git ", false );

			$this->change_connection( $name, $environ, 'sftp' );

		}


		// 	return report
		return $response;


	}


	// 	Process response to output message
	public function process_response( $action, $args ) {

		$response = false;

		switch ( $action ) {

			case 'create_backup':

				$response = ( $args['success'] ? 'suceeded in creating backup.' : 'failed to create backup.' );

				break;

			case 'upstream':

				$response = ( $args['success'] ? 'applied upstream updates.' : 'failed to apply upstream updates.' );

				break;

			case 'updates':

				if ( $args ) {

					$updated = array();

					foreach ( $args as $plugin ) {

						$updated[$plugin->name] = $plugin->new_version;

					}

					$response = 'plugins updated:' . "\n";

					foreach ( $updated as $plugin_name => $plugin_version ) {

						$plugin_version = format_version( $plugin_version );

						$response .= "   [	$plugin_version	]	$plugin_name";

						$response .=  "\n";

					}

				}

				break;

			case 'auto_commit':

				$response = ( $args['success'] ? 'changes commited.' : 'failed to commit changes.' );

				break;
			
			case 'auto_deploy':

				if ( ! $args['success'] && $args['data'] ) {

					$response = ( $args['message'] ? $args['message'] : 'failed to deploy to ' . $args['data'] );

				} else {

					$response = $args['message'];

				}

				break;

			case 'report':

				$response .=  "\n";

				$response .=  '```';

				if ( $args['message']['skip_backup'] ) {

					$response .= '=> ' . ucfirst( $args['message']['skip_backup'] );

					$response .=  "\n";

				}

				if ( $args['message']['upstream'] ) {

					$response .= '=> ' . ucfirst( $args['message']['upstream'] );

					$response .=  "\n";
					
				}

				if ( $args['message']['updates'] ) {

					$response .= '=> ' . ucfirst( $args['message']['updates'] );

					$response .=  "\n";

				}

				if ( $args['message']['auto_commit'] ) {

					$response .= '=> ' . ucfirst( $args['message']['auto_commit'] );

					$response .=  "\n";

				}

				if ( $args['message']['auto_deploy'] ) {

					$response .= '=> ' . ucfirst( $args['message']['auto_deploy']['test'] );

					$response .=  "\n";

					$response .= '=> ' . ucfirst( $args['message']['auto_deploy']['live'] );

					$response .=  "\n";

				}

				if ( $args['message']['log'] ) {

					foreach ( $args['message']['log'] as $log ) {

						$response .= '=> ' . ucfirst( $log );

						$response .=  "\n";

					}

				}

				$response .=  '```';

				break;
			
		}

		return $response;

	}


	// 	Process and validate arguments
	public function process_arguments( $action,  $args = false, $assoc_args = false ) {


		$valid = true;


		switch ( $action ) {


			case 'start':


				/* 	Checking $args and $assoc_args
					- can't use anymore positional args
					- can't use config file if other arguments are used

				 */
				if ( $args ) return false;
				
				if ( count( $assoc_args ) != 1 && isset( $assoc_args['config-file'] ) ) return false;


				/* 	Build target site list adn options
					- use config file when no other arguments given
					- when command don't have any argument, use default config file
					- when command have target site arguments

				 */
				if ( count( $assoc_args ) === 1 && isset( $assoc_args['config-file'] ) ) {

					$config_file = $assoc_args['config-file'];

					if ( ! file_exists( $config_file ) ) {

						$this->failure( 'File ' . $config_file . ' does not exists.' );

						return false;

					}

					$this->settings = spyc_load_file( $config_file );

				}

				if ( count( $assoc_args ) === 0 ) {

					$default_config_file = dirname( __DIR__ ) . '/sites-config.yml';

					if ( ! file_exists( $default_config_file ) ) {

						$this->failure( 'File ' . $default_config_file . ' does not exists.' );

						return false;

					}

					$this->settings = spyc_load_file( $default_config_file );

				}

				if ( count( $assoc_args ) > 0 && ! isset( $assoc_args['config-file'] ) ) {

					// 	Build settings
					$this->settings['sites'] = array(
						'settings'	=> array(
							'env'			=> ( isset( $assoc_args['env'] ) ? $assoc_args['env'] : 'dev' ),
							'upstream'		=> ( isset( $assoc_args['upstream'] ) ? true : false ),
							'auto-commit'	=> ( isset( $assoc_args['auto-commit'] ) ? true : false ),
							'skip-backup'	=> ( isset( $assoc_args['skip-backup'] ) ? false : true ),
							'auto-deploy'	=> ( isset( $assoc_args['auto-deploy'] ) ? true : false ),
						),
						'update'	=> array(),
					);

					// 	Get sites
					$options = array(
						'org_id'    => $this->input()->optional( array(
							'choices' => $assoc_args,
							'default' => null,
							'key'     => 'org',
						) ),
						'team_only' => isset( $assoc_args['team'] ),
					);

					$this->sites->fetch( $options );

					if ( isset( $assoc_args['name'] ) ) {

						$this->sites->filterByName( $assoc_args['name'] );

					}

					if ( isset( $assoc_args['owner'] ) ) {

						$owner_uuid = $assoc_args['owner'];

						if ($owner_uuid == 'me') {

							$owner_uuid = $this->user->id;

						}

						$this->sites->filterByOwner( $owner_uuid );

					}

					$sites = $this->sites->all();

    				foreach ( $sites as $site ) {

      					$name = $site->get('name');

      					$this->settings['sites']['update'][] = array(
      						'name'	=> $name,
      					);

					}

				}


				/* 	Use assoc_args  */
				$this->assoc_args = $assoc_args;


				break;


		}


		return $valid;


	}


	// 	Check a site env connection mode
	private function connection_info( $site_name, $site_env ) {

		$site = new \modSiteCommand( array( 'runner' => new \Terminus\Runner() ) );

		$info = $site->connectionInfo( array(), array(
			'site'	=> $site_name,
			'env'	=> $site_env,
		) );

		return $info['connection_mode'];

	}

	
	// 	Change a site env connection mode
	private function change_connection( $site_name, $site_env, $mode ) {

		$site = new \modSiteCommand( array( 'runner' => new \Terminus\Runner() ) );

		return $site->setConnectionMode( array(), array(
			'site'	=> $site_name,
			'env'	=> $site_env,
			'mode'	=> $mode,
		) );

	}


	// 	Create backup for a site
	private function create_backup( $site_name, $site_env ) {

		$site = new \modSiteCommand( array( 'runner' => new \Terminus\Runner() ) );

		return $site->create_backup( array( 'create' ), array(
			'site'	=> $site_name,
			'env'	=> $site_env,
		) );

	}


	// 	Check for upstream availability
	private function check_upstream( $site_name ) {

		$available = false;

		$site = new \modSiteCommand( array( 'runner' => new \Terminus\Runner() ) );

		$info = $site->upstreamInfo( array(), array(
			'site'	=> $site_name,
		) );

		if ( 'outdated' == $info['status'] ) {

			$available = true;

		}

		return $available;

	}

	
	// 	Check for upstream availability
	private function apply_upstream( $site_name ) {

		$site = new \modSiteCommand( array( 'runner' => new \Terminus\Runner() ) );

		$info = $site->upstream_update( $site_name );

		return $info;

	}


	// 	Call wp-cli command
	private function wp_cli( $site_name, $site_env, $args ) {

		$wpCommand = new \modWpCommand( array( 'runner' => new \Terminus\Runner() ) );

		$info = $wpCommand->__invoke( $args, array(
			'site'	=> $site_name,
			'env'	=> $site_env,
		) );

		return $info;

	}


	// 	Check site plugin list
	private function get_plugin_list( $site_name, $site_env ) {

		$response = false;

		$args = array(
			'plugin list --format=json',
		);

		$info = $this->wp_cli( $site_name, $site_env, $args );

		if ( isset( $info['output'] ) ) {

			$response = json_decode( $info['output'] );

		}

		return $response;

	}


	// 	Check for errors on site
	private function site_error( $site_name, $site_env ) {

		$response = false;

		$args = array(
			'plugin status',
		);

		$info = $this->wp_cli( $site_name, $site_env, $args );

		if ( isset( $info['exit_code'] ) && $info['exit_code'] == 255 || $info['exit_code'] == 1 ) {

			return true;

		}

		return $response;

	}


	// 	Update all plugins
	private function update_plugins( $site_name, $site_env ) {

		$response = false;

		$args = array(
			'plugin update --all --format=json',
		);

		$info = $this->wp_cli( $site_name, $site_env, $args );

		if ( isset( $info['output'] ) ) {

			$response = json_decode( $info['output'] );

		}

		return $response;

	}


	// 	Commit all changes
	private function commit( $site_name, $site_env, $message ) {

		$site = new \modSiteCommand( array( 'runner' => new \Terminus\Runner() ) );

		$info = $site->code( array( 'commit' ), array(
			'site'		=> $site_name,
			'env'		=> $site_env,
			'message'	=> $message,
		) );

		return $info;

	}


	// 	Deploy
	private function deploy( $site_name, $site_env, $message ) {

		$site = new \modSiteCommand( array( 'runner' => new \Terminus\Runner() ) );

		$info = $site->deploy( array(), array(
			'site'		=> $site_name,
			'env'		=> $site_env,
			'cc'		=> true,
			'note'		=> $message,
		) );

		return $info;

	}


	// 	Send report message to slack
	public function send_to_slack( $queue, $report ) {


		$name = $queue['name'];
		
		$message = $this->process_response( 'report', $report );
			

		// 	exit if no message given
		if ( ! $message ) return;


		// 	setting up info
		$assoc_args = array(
			'site' => $name,
		);

		$site = $this->sites->get(
			$this->input()->siteName( array( 'args' => $assoc_args ) )
		);

		$env_url = $this->getSiteEnvUrl( $site );

		$site_info = $site->serialize();

		$date = new \DateTime();


		// 	modify message
		$message 	= "*Terminus update report on " . $name . "*\n\n" . $message;
		$color 		= ( $report['error'] ? '#dd0d0d' : '#2f2cba' );


		// 	send message
		$payload = array(
			'username'		=> $this->settings['slack_settings']['username'],
			'channel'		=> $this->settings['slack_settings']['channel'],
			'icon_emoji'	=> ':' . $this->settings['slack_settings']['icon_emoji'] . ':',
			'text'			=> $message,
			'mrkdwn'		=> true,
			'attachments'	=> array( array(
				'fallback'		=> 'Terminus MWU message.',
				'color'			=> $color,
				'author_name'	=> $name,
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
				'footer'		=> 'Updates performed in: ',
				'footer_icon'	=> 'https://platform.slack-edge.com/img/default_application_icon.png',
				'ts'			=> $date->getTimestamp()
			) ),
		);

		$slack = simple_slack( $this->settings['slack_settings']['url'], $payload );

		$result = $slack->send();

		
		// 	Send to slack team member
		$team_member = ( isset( $queue['err_notify'] ) ? $queue['err_notify'] : false );

		if ( $team_member && $report['error'] ) {

			foreach ( $team_member as $member ) {

				$memberChannel = "@$member";

				$payload['channel'] = "@$member";

				$slack = simple_slack( $this->settings['slack_settings']['url'], $payload );

				$result = $slack->send();

			}

		}



	}


	// 	Get site's urls
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


	// 	Print message to cli
	public function notice( $message, $before = true, $after = true ) {

		if ( $before ) { new_line(); }

		echo $message;

		if ( $after ) { new_line(); }

	}


	// 	Loads all external helper files
	public function load_helpers() {


		// 	Terminus command modifications
		require( 'lib/mod-SiteCommand.php' );
		require( 'lib/mod-WpCommand.php' );


		// 	Helper functions
		require( 'lib/lib-Helpers.php' );
		require( 'lib/lib-Simple_Slack.php' );
		require( 'lib/lib-Spyc.php' );


	}


}