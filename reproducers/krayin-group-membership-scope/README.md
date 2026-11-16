# Krayin CRM v2.2.8 — Cross-group account provisioning reproduction

## Result

The reproduction passed 31 assertions against a file-backed SQLite database.

The database includes Sales-A and Sales-B, a group-scoped manager in Sales-A, a user and lead in Sales-B, and a newly provisioned active account. The manager's custom role includes user creation plus Lead view/edit/delete permissions.

The test reproduces this chain:

1. A submitted custom role is accepted because it grants no permission beyond the manager's own role.
2. `view_permission=group` is accepted because it is no broader than the manager's own scope.
3. A submitted existing group ID from Sales-B passes the `exists:groups,id` validation, without a check that the manager belongs to Sales-B.
4. The new account is inserted with a known bcrypt password, active status, and the actor as `created_by`; its `user_groups` pivot is synced to Sales-B.
5. The same group-membership join used by `UserRepository::getCurrentUserGroupsUserIds()` returns Sales-B members for the new account.
6. The Bouncer-derived user-ID set makes the Lead owner-scope query include Sales-B records.
7. The same owner-ID set passes the `Controller::preventUnauthorizedAccess()` gate used by `LeadController::update()` and `destroy()`. The test updates one victim-group lead and deletes another.

## Run

Requirements: Python 3 with its standard `sqlite3` module, and PHP CLI (used only for bcrypt hash generation/verification).

```bash
python3 krayin-group-scope-sqlite-repro.py
```

The script recreates `krayin-group-scope-local.sqlite` and writes `krayin-group-scope-sqlite-repro.log` beside itself.

## Evidence boundary

This is a real local relational-database test: SQLite executes the table constraints, inserts, pivot joins, owner-scope SELECTs, UPDATE, and DELETE. The schema is reduced to the relevant Krayin v2.2.8 tables and fields, based on the tagged migrations.

It does **not** boot Laravel, run the HTTP route/middleware, use Eloquent, or create a real authenticated session. The controller guard/validation decisions are represented using their source-observed logic; it must not be described as a full HTTP end-to-end exploit.

## Source anchors (v2.2.8)

- [UserController::store()](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Http/Controllers/Settings/UserController.php) — validates `groups.*` by existence, then syncs the supplied group IDs.
- [UserController scope/role guards](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Http/Controllers/Settings/UserController.php) — role permission subset and scope-rank checks.
- [Bouncer::getAuthorizedUserIds()](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Bouncer.php) — resolves group-scoped user IDs from group membership.
- [UserRepository::getCurrentUserGroupsUserIds()](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/User/src/Repositories/UserRepository.php) — user/group/pivot joins.
- [LeadDataGrid](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/DataGrids/Lead/LeadDataGrid.php) — filters leads by the authorized user-ID set.
- [Controller::preventUnauthorizedAccess()](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Http/Controllers/Controller.php) — owner authorization gate.
- [LeadController](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Http/Controllers/Lead/LeadController.php) — update and delete call the owner authorization gate before repository writes.
- [user_groups migration](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/User/src/Database/Migrations/2021_03_12_074867_create_user_groups_table.php) — group/user foreign keys.
- [users migration](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/User/src/Database/Migrations/2021_03_12_074857_create_users_table.php) and [leads migration](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Lead/src/Database/Migrations/2021_04_22_164215_create_leads_table.php).