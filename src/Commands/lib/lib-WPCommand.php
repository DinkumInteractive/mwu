<?php
if (class_exists('MWU_WPCommand')) {
    return;
}

class MWU_WPCommand
{

    protected $env;

    public function __construct($env)
    {

        $this->env = $env;
    }

    private function run($command, $return_raw = false)
    {

        $result = $this->env->sendCommandViaSsh($command);

        return ( $return_raw ? $result : $this->parse($result) );
    }

    private function parse($str)
    {

        return json_decode($str['output']);
    }

    public function get_plugin_list()
    {

        $fields = array(
            'name',
            'status',
            'version',
            'update',
            'update_version',
            'update_package',
        );

        $command = 'wp plugin list --format=json --fields=' . implode(',', $fields);

        return $this->run($command);
    }

    public function has_update($plugin_name = false)
    {

        $has_update = false;

        $plugins = $this->get_plugin_list();

        if ($plugins) {
            foreach ($plugins as $plugin) {
                if ($plugin_name) {
                    if ('available' === $plugin->update &&
                        $plugin->update_package &&
                        'none' != $plugin->update_package &&
                        'active' === $plugin->status &&
                        $plugin_name === $plugin->name
                    ) {
                        return true;
                    }
                } else {
                    if ('available' === $plugin->update &&
                        $plugin->update_package &&
                        'none' != $plugin->update_package &&
                        'active' === $plugin->status
                    ) {
                        $has_update = true;

                        break;
                    }
                }
            }
        }

        return $has_update;
    }

    public function get_update_list()
    {

        $update_list = array();

        $plugins = $this->get_plugin_list();

        if ($plugins) {
            foreach ($plugins as $plugin) {
                if ('available' === $plugin->update) {
                    $update_list[] = $plugin;
                }
            }
        }

        return $update_list;
    }

    public function is_error()
    {

        $command = "wp option get siteurl";

        $result = $this->run($command, true);
    }

    public function update_plugins($exclude = false, $major = false)
    {

        $exclude = ( $exclude ? '--exclude=' . implode(',', $exclude) : '' );

        $major = ( $major ? '' : '--minor' );

        $command = "wp plugin update --all --format=json $exclude $major";

        $result = $this->run($command, true);

        $response = array();

        if (isset($result['output'])) {
            $response = json_decode($result['output']);
        }

        return $response;
    }

    public function is_timed_out_error($update_response)
    {

        if ($update_response) {
            $error_exist = false;

            // 	Check for error in update;
            foreach ($update_response as $index => $update) {
                if ('Error' === $update->status && $this->has_update($update->name)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function get_plugin_info($plugin_name)
    {

        $command = "wp plugin get $plugin_name --format=json";

        $result = $this->run($command, true);

        $response = array();

        if (isset($result['output'])) {
            $response = json_decode($result['output']);
        }

        return $response;
    }

    public function get_major_update()
    {

        $major_update_list = array();

        $update_list = $this->get_update_list();

        if ($update_list) {
            foreach ($update_list as $update) {
                $version = $update->version;
                $update_version = $update->update_version;
                $major_update = $this->major_version_compare($version, $update_version);

                if ($major_update) {
                    $major_update_list[] = $update;
                }
            }
        }

        return $major_update_list;
    }

    public function major_version_compare($old_version, $new_version)
    {
        $old_version = intval(( strpos($old_version, '.') === false ? $old_version : strstr($old_version, '.', true) ));
        $new_version = intval(( strpos($new_version, '.') === false ? $new_version : strstr($new_version, '.', true) ));
        return ( $new_version > $old_version ? true : false );
    }
}
