<?php

namespace Pantheon\MWU\Commands;

use Pantheon\Terminus\Commands\Site\SiteCommand;
use Pantheon\Terminus\Commands\TerminusCommand;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Pantheon\Terminus\Exceptions\TerminusException;

/**
 * Updates dev environment of a WordPress site.
 */
class MwuCommand extends SiteCommand
{

    /**
     * Default options made when no param declared.
     *
     * @since 1.0.0
     */
    const options_default = array(
        'name'              => '',
        'env'               => 'dev',
        'backup'            => '', // all|code|files|database|db
        'upstream'          => false,
        'update'            => true,
        'auto_commit'       => 'Site updated by MWU.',
        'auto_deploy'       => false,
        'major_update'      => false,
    );

    /**
     * Settings.
     *
     * @since 1.0.0
     */
    const keep_backup = 365;

    /**
     * Queue of sites to be updated.
     *
     * @since 1.0.0
     */
    public $queue = array();

    /**
     * Slack settings.
     *
     * @since 1.0.0
     */
    public $slack_settings;

    /**
     * Update dev environment.
     *
     * @authenticated
     * @authorize
     *
     * @command site:mwu
     *
     * @param string $sites_config Sites yml.
     *
     * @option      name             Site name.
     * @option      backup           Backup site at the start of the update process. Element to be backed up [all|code|files|database|db]
     * @option      upstream         Updates upstream.
     * @option      update           Update plugin.
     * @option      auto_commit      Auto commit changes made.
     * @option      auto_deploy      Auto deploy to said env. Separated by ','.
     *
     * @since 1.0.0
     */
    public function mwu($sites_config = false, $options = self::options_default)
    {

        // 	Load helpers.
        $this->load_helpers();

        //	Create queue with either yml config or command options.
        $this->queue = ( $sites_config ? $this->create_queue($sites_config) : $this->create_queue($options) );

        //	Process queue
        if ($this->queue) {
            echo "\nSites found:\n";

            foreach ($this->queue as $update_args) {
                $this->_e($update_args['name'], true);
            }


            foreach ($this->queue as $update_args) {
                echo "\n". 'Beginning updates on ' . $update_args['name'] . "\n";

                echo "- - - - - - - - - - - - - - - - - - - - - - - -\n";

                $update_report = $this->update_site($update_args);

                if ($this->slack_settings && $update_report) {
                    $this->respond('slack_sending');

                    $slack = $this->send_to_slack($update_args, $update_report);

                    if ($slack) {
                        $this->respond('slack_sent');
                    } else {
                        $this->respond('slack_not_sent');
                    }
                }
            }
        }
    }

    /**
     * Queue of sites to be updated.
     *
     * @return Sites queue.
     *
     * @since 1.0.0
     */
    public function create_queue($args)
    {

        $queue = array();

        if (is_array($args)) {
            $queue[] = array_merge(self::options_default, $args);
        } elseif (is_string($args)) {
            // 	Use yaml config
            $file = file_get_contents($args);

            if ($yaml = spyc_load($file)) {
                if ($sites_list = $yaml['sites']) {
                    $settings = $sites_list['settings'];

                    $sites = $sites_list['update'];

                    // 	Manage slack settings
                    if (isset($yaml['slack_settings'])) {
                        $this->slack_settings = $yaml['slack_settings'];
                    }

                    // 	Manage plugin update exclusion
                    if (isset($yaml['sites']['settings']['exclude'])) {
                        $this->exclude = $yaml['sites']['settings']['exclude'];
                    }

                    foreach ($sites as $site) {
                        $site_args = array_merge($settings, $site);

                        // 	Merge slack setting arguments
                        if (isset($this->slack_settings['notifications'])) {
                            $site_args['notifications'] = array_merge($this->slack_settings['notifications'], ( isset($site_args['notifications']) ? $site_args['notifications'] : array() ));
                        }

                        // 	Merge plugin update exclude arguments
                        if (isset($this->exclude)) {
                            $site_args['exclude'] = array_unique(array_merge($this->exclude, ( isset($site_args['exclude']) ? $site_args['exclude'] : array() )));
                        }

                        $queue[] = $site_args;
                    }
                }
            }
        }

        return $queue;
    }

    /**
     * Update a site.
     *
     * @since 1.0.0
     */
    public function update_site($args)
    {

        $name = $args['name'];
        $env = $args['env'];
        $upstream = $args['upstream'];
        $update = filter_var($args['update'], FILTER_VALIDATE_BOOLEAN);
        $exclude = ( isset($args['exclude']) ? $args['exclude'] : false );
        $auto_commit = $args['auto_commit'];
        $backup = $args['backup'];
        $auto_deploy = $args['auto_deploy'];
        $report = array( 'error' => false, 'data' => false );
        $major_update = ( isset($args['major_update']) ? $args['major_update'] : false );

        // 	Check site availability.
        if (! $this->sites->nameIsTaken($name)) {
            return $this->respond('invalid_site_id', false);
        }

        // 	Site and environment
        $site_env = "$name.$env";
        
        $site = $this->sites->get($name);

        $info = $site->serialize();

        // 	Check if site uses WordPress
        if ('wordpress' != $info['framework']) {
            return $this->respond('invalid_framework', false);
        }

        //	Check if site have pending changes.
        if ('sftp' == $this->get_connection_info($site_env)) {
            $diff = get_object_vars($this->get_diff($site_env));

            if (0 === count($diff)) {
                $this->set_connection($site_env, 'git');
            } else {
                $this->respond('uncommited_changes', $name);

                return false;
            }
        }

        /*	@TODO: Site backup. Wait for stable Terminus function.
		 */
        if ($backup) {
            $this->respond('backup_start');

            $backup_args = array(
                'element' => $backup,
                'keep-for' => self::keep_backup,
            );

            $bk_element = $backup;

            $keep = self::keep_backup;

            $this->backup($site_env, $backup_args);

            $this->respond('backup_finish', $backup_args);

            $report['data']['backup'] = "Site $bk_element backed up. Back up data will be kept for $keep days.";
        }

        //	Update site upstream.
        if ($upstream) {
            if ('sftp' == $this->get_connection_info($site_env)) {
                $this->set_connection($site_env, 'git');
            }

            $this->respond('upstream_start');

            $update_upstream = $this->update_upstream($site_env);

            if ($update_upstream) {
                $this->respond('upstream_stop');

                $report['data']['upstream'] = $update_upstream;
            } else {
                echo "\n";

                $this->respond('upstream_404');

                $report['data']['upstream'] = false;
            }
        }

        //	Update site plugins
        if (! $this->is_error($site_env)) {
            if ($this->has_update($site_env) && $update) {
                if ('git' == $this->get_connection_info($site_env)) {
                    $this->set_connection($site_env, 'sftp');
                }

                $this->respond('update_plugins_start');

                $update_plugins = $this->update_plugins($site_env, $exclude, $major_update);

                $report['data']['update_plugins'] = $update_plugins;

                if ($update_plugins) {
                    $this->respond('update_plugins_finished');
                } else {
                    $this->respond('update_plugins_failed');
                }
            } elseif (! $update) {
                $update_list = $this->get_update_list($site_env);

                $report['data']['update_list'] = $update_list;
            } else {
                $this->respond('update_plugins_404');

                $report['data']['update_list'] = false;
            }
        }

        //  Get major update info
        $report['data']['major_update'] = $this->get_major_update($site_env);

        // 	Get excluded plugin info
        if ($exclude) {
            $excluded_plugins = array();

            foreach ($exclude as $plugin_name) {
                $plugin_info = $this->get_plugin_info($site_env, $plugin_name);

                if ($plugin_info) {
                    $excluded_plugins[] = $plugin_info;
                }
            }

            $report['data']['excluded_plugins'] = $excluded_plugins;
        }

        //	Commit site changes
        if (! $this->is_error($site_env) && $auto_commit) {
            // 	Check for diff
            $diff = get_object_vars($this->get_diff("$name.dev"));

            if (count($diff) > 0) {
                $this->respond('commit_changes_start');

                $this->commit_changes($site_env, $auto_commit);

                $this->respond('commit_changes_finished');

                // 	Deploy site
                if ($auto_deploy) {
                    $this->respond('deploy_to_test');

                    $deploy = $this->deploy("$name.test", "Deploy to test - $auto_commit");

                    if ($deploy && ! $this->is_error("$name.test")) {
                        $this->respond('deployed_to_test');

                        /*	@TODO: There might be other alternatives to report.
						 */
                        $report['data']['deploy_to_test'] = 'Deployed to test environment.';

                        $this->respond('deploy_to_live');

                        $deploy = $this->deploy("$name.live", "Deploy to live - $auto_commit");

                        if ($deploy && ! $this->is_error("$name.live")) {
                            $this->respond('deployed_to_live');

                            /*	@TODO: There might be other alternatives to report.
							 */
                            $report['data']['deploy_to_live'] = 'Deployed to live environment.';
                        } else {
                            /*	@TODO: There might be other alternatives to report.
							 */
                            $report['data']['deploy_to_live'] = 'Deploy to live environment failed.';

                            $this->respond('deploy_to_live_failed');
                        }
                    } else {
                        $this->respond('deploy_to_test_failed');

                        /*	@TODO: There might be other alternatives to report.
						 */
                        $report['data']['deploy_to_test'] = 'Deploy to test canceled.';
                    }
                }
            } else {
                $this->respond('commit_changes_none');

                $report['data']['deploy_to_test'] = 'No changes detected. Nothing to deploy.';
            }
        }

        // 	Change connection to git
        $this->set_connection($site_env, 'git');

        /*	@TODO: checking
		var_dump($report);
		exit;
		 */

        return $report;
    }

    /**
     * Get a site's environment.
     *
     * @since 1.0.0
     */
    private function get_site_environment($site_env)
    {

        list(, $env) = $this->getSiteEnv($site_env);

        return $env;
    }

    /**
     * Get connection info.
     *
     * @since 1.0.0
     */
    public function get_connection_info($site_env)
    {

        $env = $this->get_site_environment($site_env);

        return $env->serialize()['connection_mode'];
    }

    /**
     * Set site environment connection mode.
     *
     * @since 1.0.0
     */
    public function set_connection($site_env, $mode)
    {

        $env = $this->get_site_environment($site_env);

        return $env->changeConnectionMode($mode);
    }

    /**
     * Get site uncommited changes.
     *
     * @since 1.0.0
     */
    public function get_diff($site_env)
    {

        $env = $this->get_site_environment($site_env);

        return $env->diffstat();
    }

    /**
     * Get site dashboard url.
     *
     * @since 1.0.0
     */
    public function get_dashboard_url($site_env)
    {

        $env = $this->get_site_environment($site_env);

        return $env->dashboardUrl();
    }

    /**
     * Get site dashboard url.
     *
     * @since 1.0.0
     */
    public function get_screenshot_url($name, $env)
    {

        $env = $this->get_site_environment("$name.$env");
        
        $env->clearCache();

        $site = $this->sites->get($name);

        $url = 'https://dsvtten8xijg8.cloudfront.net/dev/'. $site->id . '.jpg';

        return $url;
    }

    /**
     * Get site uncommited changes.
     *
     * @since 1.0.0
     */
    public function backup($site_env, $options = array( 'element' => 'all', 'keep-for' => 365 ))
    {

        $env = $this->get_site_environment($site_env);

        $workflow = $env->getBackups()->create($options);

        while (! $workflow->checkProgress()) {
            echo '.';
        }

        echo "\n";
    }

    /**
     * Get site uncommited changes.
     *
     * @since 1.0.0
     */
    public function update_upstream($site_name)
    {

        $env = $this->get_site_environment($site_name);

        $updates = $env->getUpstreamStatus()->getUpdates();

        $logs = property_exists($updates, 'update_log') ? (array)$updates->update_log : array();

        $count = count($logs);

        $updated = false;

        if ($count) {
            try {
                $workflow = $env->applyUpstreamUpdates(false, true);

                while (! $workflow->checkProgress()) {
                    echo '.';
                }

                echo "\n";

                $updated = array();

                foreach ($logs as $log) {
                    $updated[] = $log;
                }
            } catch (TerminusException $e) {
                $updated = false;
            }
        }

        return $updated;
    }

    /**
     * Check update is available.
     *
     * @since 1.0.0
     */
    public function has_update($site_env)
    {

        $mwu_cli = new \MWU_WPCommand($this->get_site_environment($site_env));

        return $mwu_cli->has_update();
    }

    /**
     * Check if site is error.
     *
     * @since 1.0.0
     */
    public function is_error($site_env)
    {

        /*	@TODO: find a way to debug error.
		 */
        return false;

        $mwu_cli = new \MWU_WPCommand($this->get_site_environment($site_env));

        return $mwu_cli->is_error();
    }

    /**
     * Update all plugins in a site.
     *
     * @since 1.0.0
     */
    public function get_major_update($site_env)
    {

        $mwu_cli = new \MWU_WPCommand($this->get_site_environment($site_env));

        return $mwu_cli->get_major_update();
    }

    /**
     * Update all plugins in a site.
     *
     * @since 1.0.0
     */
    public function update_plugins($site_env, $exclude = false, $major_update = false)
    {

        $mwu_cli = new \MWU_WPCommand($this->get_site_environment($site_env));

        $retry_times = 5;

        do {
            $update_response = $mwu_cli->update_plugins($exclude, $major_update);

            $retry_times--;
        } while ($mwu_cli->is_timed_out_error($update_response) && $retry_times > 0);

        return $update_response;
    }

    /**
     * Update all plugins in a site.
     *
     * @since 1.0.0
     */
    public function get_plugin_info($site_env, $plugin_name)
    {

        $mwu_cli = new \MWU_WPCommand($this->get_site_environment($site_env));

        return $mwu_cli->get_plugin_info($plugin_name);
    }

    /**
     * Get all available plugin update in a site.
     *
     * @since 1.0.0
     */
    public function get_update_list($site_env)
    {

        $mwu_cli = new \MWU_WPCommand($this->get_site_environment($site_env));

        return $mwu_cli->get_update_list();
    }

    /**
     * Commit all changes.
     *
     * @since 1.0.0
     */
    public function commit_changes($site_env, $commit_message)
    {

        $env = $this->get_site_environment($site_env);

        $workflow = $env->commitChanges($commit_message);

        while (! $workflow->checkProgress()) {
            echo '.';
        }

        echo "\n";

        return $workflow->isSuccessful();
    }

    /**
     * Deploy to other envs.
     *
     * @since 1.0.0
     */
    public function deploy($site_env, $commit_message)
    {

        $env = $this->get_site_environment($site_env);

        if ($env->hasDeployableCode()) {
            $params = array(
                'updatedb'    => 1,
                'clear_cache' => 1,
                'annotation'  => $commit_message,
            );

            $workflow = $env->deploy($params);

            while (! $workflow->checkProgress()) {
                echo '.';
            }

            echo "\n";

            return $workflow->isSuccessful();
        }

        $this->respond('deploy_failed_none');

        return false;
    }

    /**
     * Get site env urls.
     *
     * @since 1.0.0
     */
    public function get_env_urls($site_name)
    {

        $environments = array( 'dev', 'test', 'live' );

        $urls = array();

        foreach ($environments as $environment) {
            $env = $this->get_site_environment("$site_name.$environment");

            $urls[$environment] = $env->serialize()['domain'];
        }

        return $urls;
    }

    /**
     * Print response and return data.
     *
     * @since 1.0.0
     */
    public function respond($name, $data = null)
    {

        switch ($name) {
            case 'invalid_site_id':
                $this->_e('Cannot detect a site with said id.', true);
                break;

            case 'invalid_framework':
                $this->_e('Site is not using WordPress framework.', true);
                break;

            case 'uncommited_changes':
                $this->_e("unable to update $data due to pending changes. Commit changes and try again.", true);
                break;

            case 'backup_start':
                $this->_e("Starting to backup site.", false);
                break;

            case 'backup_finish':
                $this->_e("Backup finished.", true);
                break;

            case 'upstream_start':
                $this->_e("Starting to start upstream update.", false);
                break;

            case 'upstream_finish':
                $this->_e("Upstream update finished.", true);
                break;

            case 'upstream_404':
                $this->_e("Upstream update is not available.", true);
                break;

            case 'slack_sending':
                $this->_e("Sending message to Slack.", true);
                break;

            case 'slack_sent':
                $this->_e("Slack message sent.", true);
                break;

            case 'slack_not_sent':
                $this->_e("Slack message not sent.", true);
                break;

            case 'update_plugins_start':
                $this->_e("Updating site plugins.", true);
                break;
                
            case 'update_plugins_finished':
                $this->_e("Site plugins updated.", true);
                break;
                
            case 'update_plugins_failed':
                $this->_e("Failed to update site plugins.", true);
                break;
                
            case 'update_plugins_404':
                $this->_e("No available updates found.", true);
                break;
                
            case 'commit_changes_start':
                $this->_e("Commiting changes on site.", false);
                break;
                
            case 'commit_changes_finished':
                $this->_e("Changes commited.", true);
                break;
                
            case 'commit_changes_none':
                $this->_e("No changes detected.", true);
                break;
                
            case 'deploy_failed_none':
                $this->_e("Deploy canceled. No codes to deploy.", true);
                break;

            case 'deploy_to_test':
                $this->_e("Deploying changes to test env.", false);
                break;

            case 'deploy_to_live':
                $this->_e("Deploying changes to live env.", false);
                break;

            case 'deployed_to_test':
                $this->_e("Deployed changes to test env.", true);
                break;

            case 'deployed_to_live':
                $this->_e("Deployed changes to live env.", true);
                break;

            case 'deploy_to_test_failed':
                $this->_e("Failed to deploy changes to test env.", true);
                break;

            case 'deploy_to_live_failed':
                $this->_e("Failed to deploy changes to live env.", true);
                break;

            case 'message_report':
                $new_data = "";
                if ($data) {
                    $new_data = "```\n";
                    foreach ($data as $type => $value) {
                        switch ($type) {
                            case 'backup':
                                $new_data .= "=> $value\n";
                                break;
                            case 'update_plugins':
                                if ($value) {
                                    $new_data .= "=> Minor plugin updates applied:\n";
                                    foreach ($value as $key => $updated) {
                                        $new_data .= '   [ ' . $this->format_version($updated->old_version) . ' ] -> [ ' . $this->format_version($updated->new_version) . ' ]    ' . $updated->name;
                                        if ('Error' === $updated->status) {
                                            $new_data .=  '    ERROR';
                                        }
                                        $new_data .= "\n";
                                    }
                                } else {
                                    $new_data .= "=> No minor plugin updates available.\n";
                                }
                                break;
                            case 'update_list':
                                if ($value) {
                                    $new_data .= "=> Available minor plugin updates:\n";
                                    foreach ($value as $key => $plugin) {
                                        $new_data .= '   [ ' . $this->format_version($plugin->version) . ' ] -> [ ' . $this->format_version($plugin->update_version) . ' ]    ' . $plugin->name . ' | Package: ' . ( $plugin->update_package ? 'available' : 'not available' );
                                        $new_data .= "\n";
                                    }
                                } else {
                                    $new_data .= "=> No minor plugin updates available.\n";
                                }
                                break;
                            case 'major_update':
                                if ($value) {
                                    $new_data .= "=> Skipped major plugin updates: To apply them, run MWU with parameter --major_update\n";
                                    foreach ($value as $key => $plugin) {
                                        $new_data .= '   [ ' . $this->format_version($plugin->version) . ' ] -> [ ' . $this->format_version($plugin->update_version) . ' ]    ' . $plugin->name;
                                        $new_data .= "\n";
                                    }
                                } else {
                                    $new_data .= "=> No major plugin updates available.\n";
                                }
                                break;
                            case 'excluded_plugins':
                                if ($value) {
                                    $new_data .= "=> Excluded plugin updates:\n";
                                    foreach ($value as $key => $plugin) {
                                        $new_data .= '   [ ' . $this->format_version($plugin->version) . ' ]    ' . $plugin->name;
                                        $new_data .= "\n";
                                    }
                                } else {
                                    $new_data .= "=> No available plugin updates.\n";
                                }
                                break;
                            case 'upstream':
                                if ($value && is_array($value)) {
                                    $new_data .= "=> Updated site upstream.\n";
                                    foreach ($value as $log) {
                                        $new_data .= '   [ ' . $log->author . ' ] ' . $log->message;
                                        $new_data .= "\n";
                                    }
                                } else {
                                    $new_data .= "=> Upstream update is not available.\n";
                                }
                                break;
                            case 'deploy_to_test':
                                $new_data .= "=> $value\n";
                                break;
                            case 'deploy_to_live':
                                $new_data .= "=> $value\n";
                                break;
                        }
                    }
                    $new_data .= "```";
                }
                $data = $new_data;
                break;
        }

        return $data;
    }

    /**
     * Print message.
     *
     * @since 1.0.0
     */
    public function _e($message, $newline)
    {

        $_message = '=> ';

        $_message .= $message;

        $_message .= ( $newline ? "\n" : '' );

        echo $_message;
    }

    /**
     * Send report to Slack.
     *
     * @since 1.0.0
     */
    public function send_to_slack($queue, $report)
    {

        $payload = array();

        // 	send message
        $message_report = $this->respond('message_report', $report['data']);
        $title = "Terminus update report on " . $queue['name'];
        $message = $message_report;
        $color1 = ( $report['error'] ? '#dd0d0d' : '#117bf3' );
        $color2 = 'ffb305';
        $name = $queue['name'];
        $date = new \DateTime();
        $env_urls = $this->get_env_urls($name);

        $payload = array(
            'username'      => $this->slack_settings['username'],
            'icon_emoji'    => ':' . $this->slack_settings['icon_emoji'] . ':',
            // 'text'          => "*$title*\n" . $this->get_screenshot_url($name,'dev'),
            'text'          => "*$title*\n",
            'mrkdwn'        => true,
            'attachments'   => array(
                array(
                    'color'         => $color2,
                    // 'author_name'	=> 'pantheon site - ' . $name,
                    'fields'        => array(
                            array(
                                'title'     => 'Dashboard',
                                'value'     => '<' . $this->get_dashboard_url("$name.dev") . '|dashboard.pantheonsite.io>',
                                'short'     => true
                            ),
                            // array(
                            //     'title'     => 'Dev',
                            //     'value'     => ( isset($env_urls['dev']) ? $env_urls['dev'] : 'undefined' ),
                            //     'short'     => true
                            // ),
                            // array(
                            //     'title'     => 'Test',
                            //     'value'     => ( isset($env_urls['test']) ? $env_urls['test'] : 'undefined' ),
                            //     'short'     => true
                            // ),
                            array(
                                'title'     => 'Live',
                                'value'     => ( isset($env_urls['live']) ? $env_urls['live'] : 'undefined' ),
                                'short'     => true
                            ),
                    ),
                    // 'footer'        => 'Update time: ',
                    // 'footer_icon'   => 'https://platform.slack-edge.com/img/default_application_icon.png',
                    // 'ts'            => $date->getTimestamp()
                ),
                array(
                    'color'         => $color1,
                    // 'author_name'   => 'terminus mwu - ' . $name,
                    'title'         => 'Updates Log',
                    'text'          => $message,
                    'mrkdwn_in'     => array( 'text' ),
                    'footer'        => 'Update time: ',
                    'footer_icon'   => 'https://platform.slack-edge.com/img/default_application_icon.png',
                    'ts'            => $date->getTimestamp(),
                    'image_url'     => $this->get_screenshot_url($name, 'dev'),
                ),
            ),
        );

        //	Send to specific user
        if (isset($queue['notifications'])) {
            if ($report['error'] && isset($queue['notifications']['error'])) {
                foreach ($queue['notifications']['error'] as $username) {
                    $payload_error = $payload;

                    $payload_error['channel'] = "@$username";

                    $slack = simple_slack($this->slack_settings['url'], $payload_error);

                    $slack->send();
                }
            }

            if (isset($queue['notifications']['updated'])) {
                foreach ($queue['notifications']['updated'] as $username) {
                    $payload_updated = $payload;

                    $payload_updated['channel'] = "@$username";

                    $slack = simple_slack($this->slack_settings['url'], $payload_updated);

                    $slack->send();
                }
            }

            if (isset($queue['notifications']['report'])) {
                foreach ($queue['notifications']['report'] as $username) {
                    $payload_report = $payload;

                    $payload_report['channel'] = "@$username";

                    $slack = simple_slack($this->slack_settings['url'], $payload_report);

                    $slack->send();
                }
            }
        }

        //	Send to channel
        $slack = simple_slack($this->slack_settings['url'], $payload);

        return $slack->send();
    }

    /**
     * Reformat version.
     *
     * @since 1.0.0
     */
    function format_version($version)
    {

        $trimmed = substr($version, 0, 5);

        $count = strlen($trimmed);

        if ($count < 5) {
            $trimmed .= '.0';
        }

        return $trimmed;
    }

    /**
     * Load supporting files.
     *
     * @since 1.0.0
     */
    public function load_helpers()
    {

        require('lib/lib-Spyc.php');
        require('lib/lib-Simple_Slack.php');
        require('lib/lib-WPCommand.php');
    }
}
