<?php

namespace Terminus\Commands;

use Terminus\Commands\TerminusCommand;
use Terminus\Commands\SiteCommand;
use Terminus\Exceptions\TerminusException;
use Terminus\Models\Collections\Sites;
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
  private $args;
  private $assoc_args;
  private $yaml_settings;


  
  /**
   * Perform WordPress mass updates on sites.
   *
   * @param array $options Options to construct the command object
   * @return MasspluginsUpdateCommand
   */
  public function __construct(array $options = []) {

    $options['require_login'] = true;

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
   * [--owner]
   * : Filter for sites a specific user owns. Use "me" for your own user.
   *
   * [--exclude]
   * : A comma separated list of sites to be excluded from updates.
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
   * [--cached]
   * : Causes the command to return cached sites list instead of retrieving anew.
   *
   * @subcommand mass-wordpress-update
   * @alias mwu
   *
   * @param array $args Array of main arguments
   * @param array $assoc_args Array of associative arguments
   *
   */
  public function massWpUpdate($args, $assoc_args) {

    // Fetch arguments
    $this->args = $args;
    $this->assoc_args = $assoc_args;

    // Always fetch a fresh list of sites.
    if (!isset($this->assoc_args['cached'])) {
      $this->sites->rebuildCache();
    }

    // Get all sites
    $this->sites->fetch();
    $sites = $this->sites->all();

    // Filter arguments
    if (isset($this->assoc_args['team'])) {
      $sites = $this->filterByTeamMembership($sites);
    }

    if (isset($this->assoc_args['org'])) {
      $org_id = $this->input()->orgId(
        [
          'allow_none' => true,
          'args'       => $assoc_args,
          'default'    => 'all',
        ]
      );
      $sites = $this->filterByOrganizationalMembership($sites, $org_id);
    }

    else if (isset($this->assoc_args['name'])) {
      $sites = $this->filterByName($sites, $assoc_args['name']);
    }

    if (isset($this->assoc_args['owner'])) {
      $owner_uuid = $assoc_args['owner'];
      if ($owner_uuid == 'me') {
        $owner_uuid = Session::getData()->user_uuid;
      }
      $sites = $this->filterByOwner($sites, $owner_uuid);
    }

    // Check if config file included
    if (isset($assoc_args['config-file'])) {
      $this->yaml_settings = $this->parseYaml($assoc_args['config-file']);
    }

    if (count($sites) == 0) {
      $this->failure('You have no sites.');
    }

    // Validate the --env argument value, if needed.
    $env = isset($this->assoc_args['env']) ? $assoc_args['env'] : 'dev';
    $valid_envs = array('dev', 'test', 'live');
    $valid_env = in_array($env, $valid_envs);
    if (!$valid_env) {
      foreach ($sites as $site) {
        $environments = $site->environments->all();
        foreach ($environments as $environment) {
          $e = $environment->get('id');
          if ($e == $env) {
            $valid_env = true;
            break;
          }
        }
        if ($valid_env) {
          break;
        }
      }
    }
    if (!$valid_env) {
      $message = 'Invalid --env argument value. Allowed values are dev, test, live or a valid multi-site environment.';
      $this->failure($message);
    }


    // Loop through each site and update.
    $sites_count = count($sites);

    foreach ( $sites as $site ) {

      $args = array(
        'name'      => $site->get('name'),
        'env'       => $env,
        'framework' => $site->attributes->framework,
      );

      if ( $this->yaml_settings ) {

        if ( $this->isYamlUpdateSite( $site->get('name') ) ) {

          $site_args = $this->yamlGetSiteArgs( $site->get('name'), $this->yaml_settings );

          $args = array_merge( $args, $site_args );

          $this->update( $args, $assoc_args );

        } else {

          $this->log()->info('{name} excluded from updates', ['name' => $site->get('name')]);

        }

      } else {

        $this->update( $args, $assoc_args );

      }

    }

    if (!$this->isReport()) {

      $this->log()->info('Done updating plugins ...');

    }

  }




  /**
   * Perform the updates on a specific site and environment.
   *
   * @param array $args
   *   The site environment arguments.
   * @param array $assoc_args
   *   The site associative arguments.
   */
  private function update($update_args) {

    $name = $update_args['name'];
    $environ = $update_args['env'];
    $framework = $update_args['framework'];

    // Get additional arguments
    $report   = $this->isReport();
    $confirm  = $this->getSetting('confirm', false);
    $skip     = $this->getSetting('skip-backup', false);
    $commit   = $this->getSetting('auto-commit', false);
    $plugins  = $this->getSetting('plugins', '');

    // Get site yaml settings if exists
    if ( $this->yaml_settings ) {

      $site_yaml_settings = $this->yamlGetSiteArgs( $name, $this->yaml_settings );

      if ( $site_yaml_settings ) {

        $report   = $this->yamlGetSiteSetting( $name, 'report' );
        $confirm  = $this->yamlGetSiteSetting( $name, 'confirm' );
        $commit   = $this->yamlGetSiteSetting( $name, 'auto-commit' );
        $skip     = $this->yamlGetSiteSetting( $name, 'skip-backup' );

      }

    }

    $all = $plugins ? '' : '--all';
    $dry_run = $report ? '--dry-run' : '';

    // Check for valid frameworks.
    $valid_frameworks = array(
      'wordpress'
    );
    if (!in_array($framework, $valid_frameworks)) {
      $this->log()->error('{framework} is not a valid framework.  WordPress updates aborted for {environ} environment of {name} site.', array(
        'framework' => $framework,
        'environ' => $environ,
        'name' => $name,
      ));
      return false;
    }

    $assoc_args = array(
      'site' => $name,
      'env'  => $environ,
    );
    $site = $this->sites->get(
      $this->input()->siteName(['args' => $assoc_args])
    );
    $env  = $site->environments->get(
      $this->input()->env(array('args' => $assoc_args, 'site' => $site))
    );
    $mode = $env->info('connection_mode');

    // Check for pending changes in sftp mode.
    if ($mode == 'sftp') {
      $diff = (array)$env->diffstat();
      if (!empty($diff)) {
        $this->log()->error('Unable to update {environ} environment of {name} site due to pending changes.  Commit changes and try again.', array(
          'environ' => $environ,
          'name' => $name,
        ));
        return false;
      }
    }

    // Prompt to confirm updates.
    if (!$report && $confirm) {
      $message = 'Apply plugins updates to %s environment of %s site ';
      $confirmed = $this->input()->confirm(
        array(
          'message' => $message,
          'context' => array(
            $environ,
            $name,
          ),
          'exit' => false,
        )
      );
      if (!$confirmed) {
        return true; // User says No.
      }
    }

    if (!$report) {
      // Beginning message.
      $this->log()->notice('==> Started plugins updates for {environ} environment of {name} site.\e[0m', array(
        'environ' => $environ,
        'name' => $name,
      ));
      // Set connection mode to sftp.
      // if ($mode == 'git') {
      //   $workflow = $env->changeConnectionMode('sftp');
      //   if (is_string($workflow)) {
      //     $this->log()->info($workflow);
      //   } else {
      //     $workflow->wait();
      //     $this->workflowOutput($workflow);
      //   }
      //   $mode == 'sftp';
      // }
    }

    $proceed = true;

    if (!$skip && !$report) {
      // Backup the site in case something goes awry.
      $args = array(
        'element' => 'all',
      );
      if ($proceed = $env->backups->create($args)) {
        if (is_string($proceed)) {
          $this->log()->info($proceed);
        } else {
          $proceed->wait();
          $this->workflowOutput($proceed);
        }
      } else {
        $this->log()->error('Backup failed. Wordpress Plugins updates aborted for {environ} environment of {name} site.', array(
          'environ' => $environ,
          'name' => $name,
        ));
        return false;
      }
    }

    // Doing upsteam-update
    if ( $this->isUpstreamUpdate() || $this->yamlGetSiteSetting( $name, 'upstream' ) ) {

      // Set connection to git
      $this->setSiteConnectionMode( $name, $environ, 'git' );

      // Perform upstream update
      $upstreamUpdate = $this->upstream_update( $name, 'dev', true );
      $proceed = $upstreamUpdate['state'];

      // Set connection back to sftp.
      $this->setSiteConnectionMode( $name, $environ, 'sftp' );

    }

    if ( $proceed ) {

      // Perform wordpress updates via wp-cli.
      $wp_options = trim("plugin update $plugins $all $dry_run");
      $tm_command = "terminus wp --site=$name --env=$environ \"$wp_options\"";

      // Doing update.
      $update_site_err = array(
        'message' => 'Unable to perform plugins updates for {environ} environment of {name} site.',
        'args'    => array(
          'environ' => $environ,
          'name'    => $name,
        ),
      );

      $update_site = $this->execute( $tm_command, false, true, $update_site_err );

      $proceed = $update_site['state'];

      // Reload the environment.
      $env  = $site->environments->get(
        $this->input()->env(array('args' => $assoc_args, 'site' => $site))
      );

    }

    if ( ! $report && $commit ) {
      // Determine if any updates were actually performed in the environment.
      $diff = (array)$env->diffstat();
      if (!empty($diff)) {
        // Auto-commit updates with a generic message.
        if ($workflow = $env->commitChanges('Updates applied by Mass Wordpress Update terminus plugin.')) {
          if (is_string($workflow)) {
            $this->log()->info($workflow);
          } else {
            $workflow->wait();
            $this->workflowOutput($workflow);
          }
        } else {
          $this->log()->error('Unable to perform automatic update commit for {environ} environment of {name} site.', array(
            'environ' => $environ,
            'name' => $name,
          ));
          return false;
        }
      }
    }

    if ( ! $report ) {
      // Set connection mode to git.
      $workflow = $env->changeConnectionMode('git');
      if (is_string($workflow)) {
        $this->log()->info($workflow);
      } else {
        $workflow->wait();
        $this->workflowOutput($workflow);
      }
      // Completion message.
      $this->log()->notice('Finished plugins updates for {environ} environment of {name} site.', array(
        'environ' => $environ,
        'name' => $name,
      ));
    }

    // Doing auto-deploy
    if ( $proceed && ( $this->isAutoDeploy() || $this->yamlGetSiteSetting( $name, 'auto-deploy' ) ) ) {

      $autoDeployTest = $this->auto_deploy( $name, 'test' );

      $autoDeployLive = $this->auto_deploy( $name, 'live' );

    }

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
    exec( $command, $update_array, $update_error );

    // Error or something happened
    if ( $onError && $update_error ) {

      $message = 'Command have error result: {error}".';

      $messageArgs = array(
        'error'     => ( is_array( $update_error ) ? implode( "\n", $update_error ) : $update_error ),
      );

      if ( ! is_bool( $onError ) && $onError ) {

        $message = ( isset( $onError['message'] ) ? $onError['message'] : $message );

        $messageArgs = ( isset( $onError['args'] ) ? $onError['args'] : $messageArgs );

      }

      $this->log()->error( $message, $messageArgs );

      $response['state'] = false;

      $response['data'] = $update_error;

      return $response;

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




  /* Filters */

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites An array of sites to filter by
   * @param string $regex Non-delimited PHP regex to filter site names by
   * @return Site[]
   */
  private function filterByName($sites, $regex = '(.*)') {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($regex) {
        preg_match("~$regex~", $site->get('name'), $matches);
        $is_match = !empty($matches);
        return $is_match;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites      An array of sites to filter by
   * @param string $owner_uuid UUID of the owning user to filter by
   * @return Site[]
   */
  private function filterByOwner($sites, $owner_uuid) {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($owner_uuid) {
        $is_owner = ($site->get('owner') == $owner_uuid);
        return $is_owner;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is an organizational member
   *
   * @param Site[] $sites  An array of sites to filter by
   * @param string $org_id ID of the organization to filter for
   * @return Site[]
   */
  private function filterByOrganizationalMembership($sites, $org_id = 'all') {
    $filtered_sites = array_filter(
      $sites,
      function($site) use ($org_id) {
        $memberships    = $site->get('memberships');
        foreach ($memberships as $membership) {
          if ((($org_id == 'all') && ($membership['type'] == 'organization'))
            || ($membership['id'] === $org_id)
          ) {
            return true;
          }
        }
        return false;
      }
    );
    return $filtered_sites;
  }

  /**
   * Filters an array of sites by whether the user is a team member
   *
   * @param Site[] $sites An array of sites to filter by
   * @return Site[]
   */
  private function filterByTeamMembership($sites) {
    $filtered_sites = array_filter(
      $sites,
      function($site) {
        $memberships    = $site->get('memberships');
        foreach ($memberships as $membership) {
          if ($membership['name'] == 'Team') {
            return true;
          }
        }
        return false;
      }
    );
    return $filtered_sites;
  }




  /* Helpers */

  /**
   * Change a site's connection mode
   * @param $siteName : name of the site
   * @param $siteEnv  : target environment of the site 
   * @param $siteCon  : target connection mode
   */
  private function setSiteConnectionMode( $siteName, $siteEnv, $siteCon ) {

    if ( $siteCon === 'git' || $siteCon === 'sftp' ) {

      $args = array(
        'site' => $siteName,
        'env'  => $siteEnv,
      );

      $site = $this->sites->get(
        $this->input()->siteName(['args' => $args])
      );

      $env  = $site->environments->get(
        $this->input()->env(array('args' => $args, 'site' => $site))
      );

      $mode = $env->info('connection_mode');

      // Return if already in target connection mode
      if ( $mode === $siteCon ) return true;

      // Changing connection mode
      $workflow = $env->changeConnectionMode( $siteCon );

      if ( is_string( $workflow ) ) {

        $this->log()->info( $workflow );

      } else {

        $workflow->wait();

        $this->workflowOutput($workflow);

      }

    }

    return false;

  }




  /* YAML configurations */ 

  private function parseYaml( $dir ){

    if ( ! file_exists( $dir ) ) {

      $this->failure( 'File ' . $dir . ' does not exists.' );

      return false;

    }

    require_once 'lib/Spyc.php';

    $yaml_settings = spyc_load_file( $dir );

    $yaml_settings = $this->validateYamlSettings( $yaml_settings );

    return $yaml_settings;

  }

  private function yamlGetSiteArgs( $name, $yaml ){

    if ( ! isset( $yaml['sites']['update'] ) ) return array();

    $args = false;

    foreach ($yaml['sites']['update'] as $key => $value) {

      if ( $value['name'] === $name ) {

        $args = $value;

        if ( isset( $yaml['sites']['settings'] ) ) {

          $args = array_merge( $yaml['sites']['settings'], $args );

        }

      }

    }

    return $args;

  }

  private function yamlGetSiteSetting( $name, $setting ){

    if ( ! $this->yaml_settings ) return false;

    $settings = $this->yamlGetSiteArgs( $name, $this->yaml_settings );

    foreach ($settings as $key => $value) {

      if ( $key === $setting ) {

        return $value;

      }

    }

    return false;

  }




  /* Validations */

  private function getSetting($name, $default_value = false) {
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

  private function isYamlUpdateSite( $siteName ){

    if ( ! isset( $this->yaml_settings['sites']['update'] ) ) return false;

    $sites = $this->yaml_settings['sites']['update'];

    foreach ($sites as $key => $value) {

      if ( $value['name'] === $siteName ) return true;

    }

    return false;

  }

  private function validateYamlSettings( $settings ){

    // we can add config validations here
    return $settings;

  }




}
