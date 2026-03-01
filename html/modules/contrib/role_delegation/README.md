# Role Delegation

This module allows site administrators to grant specific roles the authority to
assign selected roles to users, without them needing the 
`administer permissions` permission.

For each role, Role Delegation provides a new `assign ROLE role` permission to
allow the assignment of that role.

The module also adds an `assign all roles` permission. Enabling this permission
for a role is a convenient way to allow the assignment of any role without
having to check all the `assign ROLE role` permissions on the permissions page.

Users without the `administer users` permission can still assign roles by 
visiting the `/user/{user}/roles` route directly, provided they have the 
appropriate `assign ROLE role` permissions.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/admin_menu).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/admin_menu).

## Requirements

This module requires no modules outside of Drupal core.

## Installation

Install as you would normally install a contributed Drupal module. For further information, see [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

## Configuration

1. Go to `/admin/people/permissions` and notice that for each role,
   Role Delegation provides a new `assign ROLE role` permission to
   allow the assignment of that role.
2. If an administrator has one of the `assign ROLE role` permissions
   or the `assign all roles` permission, a role assignment widget gets displayed
   in the account creation or editing form, and bulk add/remove role operations
   become available on the user administration page.
