<?php 

class modSiteCommand extends \Terminus\Commands\SiteCommand {


	/*	Source 	: Terminus\Commands\SiteCommand.php
		L1604 	: public function setConnectionMode
	 */
	public function setConnectionMode( $args, $assoc_args ) {


		// 	Defaults
		$response 		= array(
			'data'			=> false,
			'message'		=> false,
			'success'		=> false,
		);

		$valid_modes 	= array( 'sftp', 'git' );

		$invalid_env 	= array( 'test', 'live' );


		// 	Check if mode is valid
	    if ( ! isset( $assoc_args['mode'] ) || ! in_array( $assoc_args['mode'], $valid_modes ) ) {

	        $response['message'] = 'You must specify the mode as either sftp or git.';

			$response['success'] = false;

			return $response;

	    }


	    // 	Check if env is valid
	    $mode = strtolower( $assoc_args['mode'] );

	    $site = $this->sites->get( $this->input()->siteName( array(
	    	'args' => $assoc_args
	    ) ) );

	    $environments = array_diff(
	        $site->environments->ids(),
	        $invalid_env
	    );

	    $env = $site->environments->get(
	        $this->input()->env( array(
	        	'args'		=> $assoc_args, 
	        	'choices'	=> $environments,
	        ) )
	    );

	    if ( in_array( $env->id, $invalid_env ) ) {

	    	$response['message'] = 'Connection mode cannot be set in test or live environments';

			$response['success'] = false;

			return $response;

	    }


	    // 	Change connection mode
	    $workflow = $env->changeConnectionMode( $mode );

	    if ( is_string( $workflow ) ) {

	    	// 	Connection is already in said mode
			$response['success'] = true;

			$response['message'] = $workflow;

	    } else {

	    	// 	Connection is changed to said mode
	        $workflow->wait();

	        if ( $workflow->get( 'result' ) == 'succeeded' ) {

				//	Success
				$message = $workflow->get( 'active_description' );

				if ( isset( $messages['success'] ) ) {

					$message = $messages['success'];

				}


	        } else {

				// 	Something failed
				$message = 'Workflow failed.';

				if ( isset( $messages['failure'] ) ) {

					$message = $messages['failure'];

				} elseif ( ! is_null( $final_task = $workflow->get( 'final_task' ) ) ) {

					$message = $final_task->reason;

				}

	        }

			$response['message'] = $message;

			$response['success'] = true;

	    }

	    
	    // 	Exit
	    return $response;


	}


	/*	Source 	: Terminus\Commands\SiteCommand.php
		L394 	: public function connectionInfo
	 */
	public function connectionInfo( $args, $assoc_args ) {

        $site = $this->sites->get(
            $this->input()->siteName( array( 'args' => $assoc_args ) )
        );

        $env_id = $this->input()->env( array( 'args' => $assoc_args, 'site' => $site ) );

        $environment = $site->environments->get( $env_id );

        $data = $environment->serialize();

        return $data;

    }
    

	/*	Source 	: Terminus\Commands\SiteCommand.php
		L80 	: public function backups
	 */
	public function create_backup( $args, $assoc_args ) {

		$action = array_shift( $args );

		$response 		= array(
			'data'			=> false,
			'message'		=> false,
			'success'		=> false,
		);

		switch ($action) {
			// case 'get-schedule':
			// 	$this->showBackupSchedule($assoc_args);
			// 	break;
			// case 'set-schedule':
			// 	$this->setBackupSchedule($assoc_args);
			// 	break;
			// case 'cancel-schedule':
			// 	$this->cancelBackupSchedule($assoc_args);
			// 	break;
			// case 'get':
			// 	$url = $this->getBackup($assoc_args);
			// 	$this->output()->outputValue($url);
			// 	break;
			// case 'load':
			// 	$this->loadBackup($assoc_args);
			// 	break;
			case 'create':
				$site = $this->sites->get( $this->input()->siteName( array( 'args' => $assoc_args ) ) );

				$env  = $site->environments->get(
					$this->input()->env( array( 'args' => $assoc_args, 'site' => $site ) )
				);

				$args = $assoc_args;

				unset( $args['site'] );

				unset( $args['env'] );

				$args['element'] = 'database';
				// $args['element'] = $this->input()->backupElement( array(
				// 	'args'    => $args,
				// 	'choices' => array( 'all', 'code', 'database', 'files' ),
				// ) );

				$workflow = $env->backups->create($args);

				if ( is_string( $workflow ) ) {

					$response['success'] = true;

					$response['message'] = $workflow;

			    } else {

			        $workflow->wait();

			        if ( $workflow->get( 'result' ) == 'succeeded' ) {

						//	Success
						$message = $workflow->get( 'active_description' );

						if ( isset( $messages['success'] ) ) {

							$message = $messages['success'];

						}


			        } else {

						// 	Something failed
						$message = 'Workflow failed.';

						if ( isset( $messages['failure'] ) ) {

							$message = $messages['failure'];

						} elseif ( ! is_null( $final_task = $workflow->get( 'final_task' ) ) ) {

							$message = $final_task->reason;

						}

			        }

					$response['message'] = $message;

					$response['success'] = true;

			    }

				break;
			// case 'list':
			// 	default:
			// 	$data = $this->listBackups($assoc_args);
			// 	$this->output()->outputRecordList( $data, array( 
			// 		'file' => 'File', 
			// 		'size' => 'Size', 
			// 		'date' => 'Date' 
			// 	) );
			// 	return $data;
			// 	break;
		}

	    return $response;

    }


    /*	Source 	: Terminus\Commands\SiteCommand.php
		L2059 	: public function upstreamInfo
	 */
    public function upstreamInfo( $args, $assoc_args ) {

		$upstream = $this->sites->get(
			$this->input()->siteName(['args' => $assoc_args,])
		)->upstream;

		return $upstream->serialize();

	}


	/*	Source 	: Terminus\Commands\SiteCommand.php
		L253 	: public function code
	 */
    public function code( $args, $assoc_args ) {

    	$response 		= array(
			'data'			=> false,
			'message'		=> false,
			'success'		=> false,
		);

		$subcommand = array_shift( $args );

        $site       = $this->sites->get(
            $this->input()->siteName(['args' => $assoc_args])
        );

        switch ( $subcommand ) {

        	case 'commit':
                $env     = $site->environments->get(
                    $this->input()->env(['args' => $assoc_args, 'site' => $site])
                );

                $diff    = $env->diffstat();

                $count   = count((array)$diff);

                if ($count === 0) {

                	$response['success'] = true;

                	$response['message'] = 'there are no changed files.';

        			return $response;

                }

                $message  = $assoc_args['message'];

                $workflow = $env->commitChanges($message);

                $workflow->wait();

                if ( $workflow->get( 'result' ) == 'succeeded' ) {

					//	Success
					$message = $workflow->get( 'active_description' );

					if ( isset( $messages['success'] ) ) {

						$message = $messages['success'];

					}

					$response['message'] = $message;

					$response['success'] = true;

		        } else {

					// 	Something failed
					$message = 'Workflow failed.';

					if ( isset( $messages['failure'] ) ) {

						$message = $messages['failure'];

					} elseif ( ! is_null( $final_task = $workflow->get( 'final_task' ) ) ) {

						$message = $final_task->reason;

					}

					$response['message'] = $message;

					$response['success'] = false;

					return $response;

		        }

                break;

        }

        return $response;


	}


	// 	apply upstream
	public function upstream_update( $siteName ) {

		$response 		= array(
			'data'			=> false,
			'message'		=> false,
			'success'		=> false,
		);

		$assoc_args = array(
			'site' 				=> $siteName,
			'env'  				=> 'dev',
			'updatedb'			=> true,
			'accept-upstream'	=> true,
		);

		$site = $this->sites->get(
			$this->input()->siteName( array( 'args' => $assoc_args ) )
		);

		$upstream_updates = $site->upstream->getUpdates();

		if ( isset( $upstream_updates->remote_url ) && isset( $upstream_updates->behind ) ) {

			if (!isset($upstream_updates) || empty((array)$upstream_updates->update_log)) {
				
                $response['message'] = 'No updates to apply.';

                $response['success'] = true;
                
                return $response;
                
            }

		} else {

			$this->failure( 'There was a problem checking your upstream status. Please try again.' );

		}

		if ( ! empty( $upstream_updates->update_log ) ) {

			$env = $site->environments->get(
				$this->input()->env(['args' => $assoc_args, 'site' => $siteName,])
			);

			if ( in_array( $env->id, ['test', 'live',] ) ) {

				$this->failure( 'Upstream updates cannot be applied to the {env} environment',
					array( 'env' => $env->id )
				);

			}

			$updatedb = ( isset( $assoc_args['updatedb'] ) && $assoc_args['updatedb'] );

			$acceptupstream = ( isset( $assoc_args['accept-upstream'] ) && $assoc_args['accept-upstream'] );

			$workflow = $env->applyUpstreamUpdates( $updatedb, $acceptupstream );

			while ( ! $workflow->isFinished() ) {

				$workflow->fetch();

				sleep(3);

				fwrite( STDERR, '.' );

			}

			echo "\n";

			if ( $workflow->isSuccessful() ) {

				$response['success'] = true;

				$response['message'] = $upstream_updates->update_log;

			} else {

				$response['success'] = false;

				$response['message'] = '';

				$final_task = $workflow->get('final_task');

				if ( ( $final_task != null ) && ! empty( $final_task->messages ) ) {

					foreach ( $final_task->messages as $data => $message ) {

						$response['message'] .= $message->message . "\n";

					}

				}

			}

		} else {

			$response['message'] = 'There are no upstream updates to apply.';

		}

		return $response;

	}


	/*	Source 	: Terminus\Commands\SiteCommand.php
		L679 	: public function deploy
	 */
	public function deploy( $args, $assoc_args ) {

		$response 		= array(
			'data'			=> false,
			'message'		=> false,
			'success'		=> false,
		);

		$site = $this->sites->get( $this->input()->siteName( ['args' => $assoc_args,] ) );

		$env  = $site->environments->get( 
			$this->input()->env(['args' => $assoc_args, 'site' => $site])
		);

		if ( ! $env || ! in_array( $env->id, array( 'test', 'live' ) ) ) {

			$response['message'] = 'You can only deploy to the test or live environment.';

			$response['success'] = false;

			return $response;

		}

		if ( ! $env->hasDeployableCode() ) {

			$response['data'] = null;

			$response['message'] = 'There is nothing to deploy to '. $assoc_args['env'] .' env.';

			$response['success'] = false;

			echo "\n";
			
			return $response;

		}

		$annotation = $assoc_args['note'];

		$cc       	= (integer)array_key_exists( 'cc', $assoc_args );

		$updatedb 	= (integer)array_key_exists( 'updatedb', $assoc_args );

		$params = array(
			'updatedb' 		=> $updatedb,
			'clear_cache' 	=> $cc,
			'annotation' 	=> $annotation,
		);

		$workflow = $env->deploy( $params );

		while ( ! $workflow->isFinished() ) {

			$workflow->fetch();

			sleep(3);

			fwrite( STDERR, '.' );

		}

		echo "\n";

		if ( $workflow->isSuccessful() ) {

			$response['success'] = true;

			$response['message'] = 'deployed to ' . $assoc_args['env'] . '.';

		} else {

			$response['data'] = $assoc_args['env'];

			$response['success'] = false;

			$response['message'] = '';

			$final_task = $workflow->get('final_task');

			if ( ( $final_task != null ) && ! empty( $final_task->messages ) ) {

				foreach ( $final_task->messages as $data => $message ) {

					$response['message'] .= $message->message . "\n";

				}

			}

		}

		return $response;

    }


}