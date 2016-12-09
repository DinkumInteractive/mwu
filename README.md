# Mass WordPress Update
Terminus plugin to perform WordPress upstream update, plugin update, site backup, and deployment on Pantheon sites.

Based on: [https://github.com/uberhacker/mcu](https://github.com/uberhacker/mcu).

## Installation:
Refer to the [Terminus Wiki](https://github.com/pantheon-systems/terminus/wiki/Plugins).

## Usage:
```
$ terminus sites mass-wp-update
```

## Alias:
```
$ terminus sites mwu
```

## Help:
```
$ terminus help sites mwu
```

## Options:
```
[--upstream]
: Apply upstream updates to site and check if it caused any error.

[--auto-commit]
: Commit changes with a generic message and switch back to git mode after performing the updates on each site.

[--auto-deploy]
: Deploy changes to test and live with a generic message.

[--skip-backup]
: Skip backup before performing the updates on each site.

[--name=<regex>]
: Filter sites you can access via name.

[--env=<env>]
: Filter sites by environment.  Default is 'dev'.

[--team]
: Filter for sites you are a team member of.

[--owner]
: Filter for sites a specific user owns. Use "me" for your own user.

[--org=<id>]
: Filter sites you can access via the organization. Use 'all' to get all.

[--config-file=<path/to/config/file>]
: Run updates with yaml settings.

```

## Examples:
* Apply plugins updates, auto-commit with a generic message and change to git connection mode on all dev environments:
```
$ terminus sites mwu --auto-commit
```

* Apply plugins updates and upstream updates then auto-commit if no error was found after update:
```
$ terminus sites mwu --name=your-site-name --upstream --auto-commit
```

* Run updates with default settings (you need to edit your web site lists):
```
$ terminus sites mwu
```

* Run updates with external yaml file:
```
$ terminus sites mwu --config-file=path/to/your/yaml/file.yml
```

* With slack settings applied in yml config file, update report message can be sent to a channel of your choice. Please visit our wiki for more information. https://github.com/DinkumInteractive/mwu/wiki/Slack-Message


