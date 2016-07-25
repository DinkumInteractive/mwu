# Mass Contrib Update
Terminus plugin to perform Drupal contrib module or theme updates on Pantheon sites

## Installation:
Refer to the [Terminus Wiki](https://github.com/pantheon-systems/terminus/wiki/Plugins).

## Usage:
```
$ terminus sites mass-contrib-update
```

## Alias:
```
$ terminus sites mcu
```

## Help:
```
$ terminus help sites mcu
```

## Options:
```
[--env=<env>]
: Filter sites by environment.  Default is 'dev'.

[--report]
: Display the contrib modules or themes that need updated without actually performing the updates.

[--auto-commit]
: Commit changes with a generic message and switch back to git mode after performing the updates on each site.

[--confirm]
: Prompt to confirm before actually performing the updates on each site.

[--skip-backup]
: Skip backup before performing the updates on each site.

[--security-only]
: Apply security updates only to contrib modules or themes.

[--projects]
: A comma separated list of specific contrib modules or themes to update.

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
Display contrib updates that would be applied to all dev environments without actually performing the updates:
```
$ terminus sites mass-contrib-update --report
```
Apply contrib updates, auto-commit with a generic message and change to git connection mode on all dev environments:
```
$ terminus sites mcu --auto-commit
```
Apply contrib security updates only and skip the automatic backup on all dev environments:
```
$ terminus sites mcu --security-only --skip-backup
```
Apply contrib updates to all live environments and prompt to continue prior to performing the updates on each site:
```
$ terminus sites mcu --env=live --confirm
```
