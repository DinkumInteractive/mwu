<?php

namespace Terminus\Commands;

use Terminus\Commands\TerminusCommand;
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
class MassContribUpdateCommand extends TerminusCommand {
  public $sites;

  /**
   * Perform Drupal contrib mass updates on sites.
   *
   * @param array $options Options to construct the command object
   * @return MassContribUpdateCommand
   */
  public function __construct(array $options = []) {
    $options['require_login'] = true;
    parent::__construct($options);
    $this->sites = new Sites();
  }

  /**
   * Perform Drupal contrib mass updates on sites.
   * Note: because of the size of this call, it is cached
   *   and also is the basis for loading individual sites by name
   *
   * ## OPTIONS
   *
   * [--env=<env>]
   * : Filter sites by environment.  Default is 'dev'.
   *
   * [--report]
   * : Display the contrib modules or themes that need updated without actually performing the updates.
   *
   * [--auto-commit]
   * : Commit changes with a generic message and switch back to git mode after performing the updates on each site.
   *
   * [--confirm]
   * : Prompt to confirm before actually performing the updates on each site.
   *
   * [--skip-backup]
   * : Skip backup before performing the updates on each site.
   *
   * [--security-only]
   * : Apply security updates only to contrib modules or themes.
   *
   * [--projects]
   * : A comma separated list of specific contrib modules or themes to update.
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
   * [--name=<regex>]
   * : Filter sites you can access via name.
   *
   * [--cached]
   * : Causes the command to return cached sites list instead of retrieving anew.
   *
   * @subcommand mass-contrib-update
   * @alias mcu
   *
   * @param array $args Array of main arguments
   * @param array $assoc_args Array of associative arguments
   *
   */
  public function massContribUpdate($args, $assoc_args) {
    // Always fetch a fresh list of sites.
    if (!isset($assoc_args['cached'])) {
      $this->sites->rebuildCache();
    }
    $sites = $this->sites->all();

    if (isset($assoc_args['team'])) {
      $sites = $this->filterByTeamMembership($sites);
    }
    if (isset($assoc_args['org'])) {
      $org_id = $this->input()->orgId(
        [
          'allow_none' => true,
          'args'       => $assoc_args,
          'default'    => 'all',
        ]
      );
      $sites = $this->filterByOrganizationalMembership($sites, $org_id);
    }

    if (isset($assoc_args['name'])) {
      $sites = $this->filterByName($sites, $assoc_args['name']);
    }

    if (isset($assoc_args['owner'])) {
      $owner_uuid = $assoc_args['owner'];
      if ($owner_uuid == 'me') {
        $owner_uuid = Session::getData()->user_uuid;
      }
      $sites = $this->filterByOwner($sites, $owner_uuid);
    }

    if (count($sites) == 0) {
      $this->failure('You have no sites.');
    }

    // Validate the --env argument value, if needed.
    $env = isset($assoc_args['env']) ? $assoc_args['env'] : 'dev';
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
    foreach ($sites as $site) {
      $args = array(
        'name'      => $site->get('name'),
        'env'       => $env,
        'framework' => $site->attributes->framework,
      );
      $this->update($args, $assoc_args);
    }
  }

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

  /**
   * Perform the updates on a specific site and environment.
   *
   * @param array $args
   *   The site environment arguments.
   * @param array $assoc_args
   *   The site associative arguments.
   */
  private function update($args, $assoc_args) {
    $name = $args['name'];
    $environ = $args['env'];
    $framework = $args['framework'];

    $report = isset($assoc_args['report']) ? true : false;
    $confirm = isset($assoc_args['confirm']) ? true : false;
    $skip = isset($assoc_args['skip-backup']) ? true : false;
    $commit = isset($assoc_args['auto-commit']) ? true : false;
    $security = isset($assoc_args['security-only']) ? '--security-only' : '';
    $projects = isset($assoc_args['projects']) ? $assoc_args['projects'] : '';
    $yn = $report ? '-n' : '-y';

    // Check for valid frameworks.
    $valid_frameworks = array(
      'drupal',
      'drupal8',
    );
    if (!in_array($framework, $valid_frameworks)) {
      $this->log()->error('{framework} is not a valid framework.  Contrib updates aborted for {environ} environment of {name} site.', array(
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
      $message = 'Apply contrib updates to %s environment of %s site ';
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
      $this->log()->notice('Started contrib updates for {environ} environment of {name} site.', array(
        'environ' => $environ,
        'name' => $name,
      ));
      // Set connection mode to sftp.
      if ($mode == 'git') {
        $workflow = $env->changeConnectionMode('sftp');
        if (is_string($workflow)) {
          $this->log()->info($workflow);
        } else {
          $workflow->wait();
          $this->workflowOutput($workflow);
        }
      }
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
        $this->log()->error('Backup failed. Contrib updates aborted for {environ} environment of {name} site.', array(
          'environ' => $environ,
          'name' => $name,
        ));
        return false;
      }
    }

    if ($proceed) {
      // Perform contrib updates via drush.
      $drush_options = trim("pm-update $yn --no-core $security $projects");
      exec("terminus --site=$name --env=$environ drush '$drush_options'", $update_array, $update_error);
      if ($update_error) {
        $this->log()->error('Unable to perform contrib updates for {environ} environment of {name} site.', array(
          'environ' => $environ,
          'name' => $name,
        ));
        return false;
      }
      // Display output of update results.
      if (!empty($update_array)) {
        $message = implode("\n", $update_array);
        $this->log()->notice($message);
      }
      // Reload the environment.
      $env  = $site->environments->get(
        $this->input()->env(array('args' => $assoc_args, 'site' => $site))
      );
    }

    if (!$report && $commit) {
      // Determine if any updates were actually performed in the environment.
      $diff = (array)$env->diffstat();
      if (!empty($diff)) {
        // Auto-commit updates with a generic message.
        if ($workflow = $env->commitChanges('Updates applied by Mass Contrib Update terminus plugin.')) {
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

    if (!$report) {
      // Set connection mode to git.
      $workflow = $env->changeConnectionMode('git');
      if (is_string($workflow)) {
        $this->log()->info($workflow);
      } else {
        $workflow->wait();
        $this->workflowOutput($workflow);
      }
      // Completion message.
      $this->log()->notice('Finished contrib updates for {environ} environment of {name} site.', array(
        'environ' => $environ,
        'name' => $name,
      ));
    }
  }
}
