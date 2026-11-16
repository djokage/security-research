# Krayin CRM: group membership assignment is not restricted to the acting user's groups

**Product:** Krayin CRM  
**Version examined:** `v2.2.8`  
**Affected area:** Admin user creation and user update  
**Suggested classification:** CWE-862 — Missing Authorization  
**Research status:** Independent source review and local reproduction; no vendor report is claimed

## Summary

Krayin checks that group IDs submitted when creating or updating a user exist, but it does not check whether the acting user is authorized to assign those groups.

This affects both `UserController::store()` and `UserController::update()`. In each method, the validated group IDs are passed to the target user's `groups()->sync()` relationship without an authorization check against the acting user's own group memberships.

For a group-scoped account, Krayin derives the account's record visibility from its group memberships. A delegated user who can create an account can therefore create an active account in a different group, using a role whose permissions are no greater than the creator's. If that role includes lead permissions, the newly created account can be placed inside the other group's data scope. The same missing check in the update path allows an eligible delegated user to assign another manageable account to a group outside the acting user's own groups.

The issue is not the ability to create or edit accounts. Those are explicit permissions. The issue is that the group relationship being assigned is not itself authorized.

## Affected version

- Confirmed by source review and reproduction against Krayin CRM `v2.2.8`.
- Other versions have not been assessed.

## Preconditions

### User creation path

The actor must be authenticated and have the `settings.user.users.create` permission. To demonstrate the lead impact, the actor's delegated role must also include the corresponding lead permissions, such as `leads.view`, `leads.edit`, or `leads.delete`. The actor is configured with `view_permission=group` and belongs to Group A, not Group B.

The actor can assign a non-administrator role whose permissions are a subset of the actor's own permissions, set the new account to `view_permission=group`, choose an active status, set a password known to the actor, and submit Group B's existing ID.

### User update path

The actor must additionally have the user-edit permission and be allowed by Krayin's existing user-management checks to edit the target account. For a non-administrator, `canManageUser()` restricts the target to an account whose role and data-scope level are not above the actor's, and whose user ID or `created_by` value is inside the actor's authorized user-ID set. That limits which account can be edited, but the submitted group IDs are still not checked against the actor's authorized groups before they are synced.

## Reproduction outline — user creation

1. Create two groups, Group A and Group B.
2. Put a delegated user in Group A only. Give this user a custom role containing `settings.user.users.create` and the lead permissions needed for the impact being tested. Set the user's data scope to `group`.
3. As the delegated user, create a second active account. Assign a role whose permissions are a subset of the creator's permissions, set `view_permission=group`, and submit Group B's existing ID in `groups`.
4. The `store()` method accepts the group ID after the existence validation and syncs it to the new account.
5. Once that account is authenticated, its group-scoped user-ID set is derived from Group B. The lead grid's owner filter and the lead update/delete owner check use that authorized user-ID set.

Example fields (replace the IDs and account details with values from a disposable test installation):

```json
{
  "name": "Group B account",
  "email": "group-b-account@example.test",
  "password": "known-test-password",
  "confirm_password": "known-test-password",
  "role_id": "<delegated-role-id>",
  "status": 1,
  "view_permission": "group",
  "groups": ["<group-B-id>"]
}
```

## Reproduction outline — user update

The `update()` method uses the same `groups.* => integer|exists:groups,id` validation and later calls `groups()->sync(request('groups') ?? [])`. It checks whether the actor may manage the target account and constrains the target's role and data scope, but does not verify that the submitted groups are within the actor's group memberships.

A delegated account with the user-edit permission that is allowed to manage the target can therefore submit an existing group ID outside its own group and have that membership applied to the target account. This path is confirmed by source inspection; it was not included in the 29-assertion dynamic controller test.

## Expected result

A delegated group-scoped user should only be able to assign or change group memberships that the user is authorized to manage. Submitting an existing group ID outside that set should be rejected. The existence of a group must not be treated as authorization to assign it.

## Actual result

Both user-management methods validate group IDs for existence and then sync the submitted IDs. There is no corresponding check that a non-administrator actor is authorized to assign those groups.

For the creation path, the new account's `view_permission=group` scope is then evaluated using the newly assigned group membership. This changes the set of owner IDs used by the normal CRM authorization logic. With the relevant lead permissions, that set includes owners from the other group.

## Technical details

### 1. Group assignment in user creation

`UserController::store()` validates `groups.*` using `integer|exists:groups,id`, applies the existing role and scope guards, builds the account data and calls `$admin->groups()->sync($data['groups'] ?? [])`. There is no check that each submitted group belongs to the acting user's authorized group set.

Source: [UserController.php — store()](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Http/Controllers/Settings/UserController.php)

### 2. Group assignment in user update

`UserController::update()` uses the same group-existence validation. After checking the target user's role and data scope, it calls `$admin->groups()->sync(request('groups') ?? [])`. The method does not check whether the acting user is authorized to assign the submitted group IDs.

Source: [UserController.php — update()](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Http/Controllers/Settings/UserController.php)

### 3. How group membership affects record scope

For a user with `view_permission=group`, `Bouncer::getAuthorizedUserIds()` returns the current user's ID together with the IDs returned by `UserRepository::getCurrentUserGroupsUserIds()`. That repository query uses the current user's group memberships to resolve users in those groups.

Sources:

- [Bouncer.php](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Bouncer.php)
- [UserRepository.php](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/User/src/Repositories/UserRepository.php)

The lead data grid filters leads using this authorized user-ID set. `Controller::preventUnauthorizedAccess()` uses the same set to check the owner ID before lead updates and deletions.

Sources:

- [LeadDataGrid.php](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/DataGrids/Lead/LeadDataGrid.php)
- [Controller.php](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Http/Controllers/Controller.php)
- [LeadController.php](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Http/Controllers/Lead/LeadController.php)

### 4. Existing protections that do not address this issue

The controller checks that a non-administrator cannot assign the administrator role or a role with permissions beyond their own. It also prevents the actor from assigning a broader data-scope level than permitted. Those checks constrain the role and the scope level; they do not constrain which specific group IDs may be assigned.

The ACL maps the user creation and user edit routes to separate permissions: `settings.user.users.create` and `settings.user.users.edit`.

Source: [acl.php](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Config/acl.php)

## Impact

A delegated user can cross the intended group boundary by assigning a user account to a group the actor does not belong to. Depending on the permissions of the affected account, this can expose leads owned by the other group and permit unauthorized modification or deletion of those leads.

The demonstrated creation scenario does not require an administrator role or global data scope. The impact is bounded by the permissions granted to the account and the group membership assigned to it.

This finding does not demonstrate unauthenticated access, remote code execution, administrator privilege escalation, or access to every tenant or every record in an installation. The number of affected deployments is unknown; package installation counts should not be interpreted as counts of vulnerable live applications.

## Verification performed

The provided Krayin `v2.2.8` source archive contained 1,008 PHP files; all passed `php -l`. The archive did not include `vendor/` dependencies.

A source-method reproduction loaded and invoked the actual Krayin methods `UserController::store()`, `Bouncer::hasPermission()`, `Bouncer::getAuthorizedUserIds()`, `Controller::preventUnauthorizedAccess()`, `LeadController::update()`, and `LeadController::destroy()`. It passed 29 assertions with no failures. The framework, request validation, authentication/session state, Eloquent relationships, repositories, and persistence boundaries were represented by test doubles. The Laravel validator itself was not run; the harness read the validation rules from the production controller and evaluated them with a local validation double.

A separate file-backed SQLite reproduction passed 31 assertions. SQLite executed inserts, foreign-key checks, group-membership joins, owner-scope queries, a lead update, and a lead delete against a reduced schema based on the v2.2.8 migrations. It did not boot Laravel, run the actual migrations, use Eloquent, or authenticate through a real session.

The `UserController::update()` group-assignment path was identified by reviewing the tagged source but was not dynamically exercised by the 29-assertion harness. A full Laravel HTTP/session/database end-to-end test has not been run.

## Suggested fix

Before calling `groups()->sync()` in both `store()` and `update()`, authorize every submitted group ID against the acting user's permitted group set. Non-administrators should not be able to assign or move accounts into groups they are not authorized to manage. Keep the administrator path explicit.

Add regression tests for:

- a delegated group-scoped user assigning an account to their own group;
- the same user attempting to assign an account to a different existing group;
- user creation with an outside group ID;
- user update with an outside group ID;
- an invalid group ID;
- an administrator managing memberships across groups; and
- confirmation that the affected account's record scope does not expand beyond the groups the actor is authorized to assign.

## Scope and disclosure note

This report is based on the `v2.2.8` source and the local reproductions described above. Other supported releases have not been tested. No private vulnerability report to Krayin is claimed as of publication. Krayin's `SECURITY.md` requests private reporting and coordinated disclosure; this publication records the researcher's independent findings and is not a claim that the vendor has confirmed or fixed the issue.