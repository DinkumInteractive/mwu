# Mass WordPress Update
Terminus plugin to perform WordPress plugin or theme updates on Pantheon sites

Base on: [https://github.com/uberhacker/mcu](https://github.com/uberhacker/mcu)

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
[--env=<env>]
: Filter sites by environment.  Default is 'dev'.

[--report]
: Display the plugins or themes that need updated without actually performing the updates.

[--auto-commit]
: Commit changes with a generic message and switch back to git mode after performing the updates on each site.

[--confirm]
: Prompt to confirm before actually performing the updates on each site.

[--skip-backup]
: Skip backup before performing the updates on each site.

[--security-only]
: Apply security updates only to plugins or themes.

[--projects]
: A comma separated list of specific plugins or themes to update.

[--team]
: Filter for sites you are a team member of.

[--owner]
: Filter for sites a specific user owns. Use "me" for your own user.

[--org=<id>]
: Filter sites you can access via the organization. Use 'all' to get all.

[--name=<regex>]
: Filter sites you can access via name.

[--cached]
: Causes the command to return cached sites list instead of retrieving anew.
```

## Examples:
Display plugins updates that would be applied to all dev environments without actually performing the updates:
```
$ terminus sites mass-wp-update --report
```
Apply plugins updates, auto-commit with a generic message and change to git connection mode on all dev environments:
```
$ terminus sites mwu --auto-commit
```
Apply plugins security updates only and skip the automatic backup on all dev environments:
```
$ terminus sites mwu --security-only --skip-backup
```
Apply plugins updates to all live environments and prompt to continue prior to performing the updates on each site:
```
$ terminus sites mwu --env=live --confirm
```
