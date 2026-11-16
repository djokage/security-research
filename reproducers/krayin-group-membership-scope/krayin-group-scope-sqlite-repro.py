#!/usr/bin/env python3
"""Local SQLite reproduction of Krayin CRM v2.2.8 cross-group user provisioning.

This uses a real, file-backed SQLite database and executes the relational operations
behind the relevant Krayin methods. It does NOT boot Laravel or send an HTTP request.

Source methods represented:
- Admin/Settings/UserController::store()
- UserController::preventUnauthorizedRoleAssignment()
- UserController::preventUnauthorizedScopeAssignment()
- UserController groups()->sync(...)
- Admin/Bouncer::getAuthorizedUserIds()
- UserRepository::getCurrentUserGroupsUserIds()
- Admin/DataGrids/Lead/LeadDataGrid::prepareQueryBuilder()

Schema is reduced to the columns and foreign keys needed by these paths, based on the
v2.2.8 migration definitions for groups, roles, users, user_groups and leads.
"""

from __future__ import annotations

import json
import os
import sqlite3
import subprocess
import sys
from pathlib import Path

DB_PATH = Path(__file__).with_name("krayin-group-scope-local.sqlite")
LOG_PATH = Path(__file__).with_name("krayin-group-scope-sqlite-repro.log")
PASSWORD = "GroupScopeTest!2026"

class TestFailure(Exception):
    pass


def expect(condition: bool, message: str, results: list[str]) -> None:
    if not condition:
        results.append(f"[FAIL] {message}")
        raise TestFailure(message)
    results.append(f"[PASS] {message}")


def php_bcrypt(value: str) -> str:
    code = 'echo password_hash($argv[1], PASSWORD_BCRYPT);'
    result = subprocess.run(
        ["php", "-r", code, value],
        check=True,
        text=True,
        capture_output=True,
    )
    hashed = result.stdout.strip()
    if not hashed.startswith("$2"):
        raise RuntimeError("PHP did not return a bcrypt hash")
    return hashed


def authorized_user_ids(conn: sqlite3.Connection, user_id: int) -> list[int]:
    """Mirror Bouncer plus UserRepository's group membership query."""
    row = conn.execute(
        "SELECT id, view_permission FROM users WHERE id = ?", (user_id,)
    ).fetchone()
    if row is None:
        raise RuntimeError(f"user {user_id} missing")

    if row["view_permission"] == "global":
        return [r[0] for r in conn.execute("SELECT id FROM users ORDER BY id")]

    if row["view_permission"] != "group":
        return [user_id]

    # This SELECT mirrors UserRepository::getCurrentUserGroupsUserIds():
    # users LEFT JOIN user_groups LEFT JOIN groups WHERE groups.id IN
    # (the logged-in user's groups()->pluck('id')).
    group_member_ids = [
        r[0]
        for r in conn.execute(
            """
            SELECT users.id
            FROM users
            LEFT JOIN user_groups ON users.id = user_groups.user_id
            LEFT JOIN groups ON user_groups.group_id = groups.id
            WHERE groups.id IN (
                SELECT group_id FROM user_groups WHERE user_id = ?
            )
            ORDER BY users.id
            """,
            (user_id,),
        )
    ]

    # Bouncer::getAuthorizedUserIds() merges [$user->id] with the group members.
    return sorted(set([user_id, *group_member_ids]))


def visible_leads(conn: sqlite3.Connection, user_id: int) -> list[tuple[int, str]]:
    ids = authorized_user_ids(conn, user_id)
    placeholders = ",".join("?" for _ in ids)
    # LeadDataGrid applies whereIn('leads.user_id', $userIds).
    return conn.execute(
        f"SELECT id, title FROM leads WHERE user_id IN ({placeholders}) ORDER BY id",
        ids,
    ).fetchall()


def main() -> int:
    results: list[str] = []
    try:
        DB_PATH.unlink(missing_ok=True)
        conn = sqlite3.connect(DB_PATH)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA foreign_keys = ON")
        fk_state = conn.execute("PRAGMA foreign_keys").fetchone()[0]
        expect(fk_state == 1, "SQLite foreign-key enforcement is enabled", results)

        # Reduced v2.2.8 table layout. user_groups deliberately has the same two
        # foreign keys as the migration and no extra authorization constraint.
        conn.executescript(
            """
            CREATE TABLE groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT
            );

            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                permission_type TEXT NOT NULL,
                permissions TEXT
            );

            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password TEXT,
                status INTEGER NOT NULL DEFAULT 0,
                role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                view_permission TEXT NOT NULL,
                created_by INTEGER REFERENCES users(id) ON DELETE SET NULL
            );

            CREATE TABLE user_groups (
                group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE leads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                user_id INTEGER REFERENCES users(id) ON DELETE SET NULL
            );
            """
        )
        expect(
            {r["name"] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'")}
            >= {"groups", "roles", "users", "user_groups", "leads"},
            "file-backed database contains the relevant Krayin tables",
            results,
        )

        actor_permissions = [
            "dashboard",
            "settings.user.users.create",
            "leads",
            "leads.view",
            "leads.edit",
            "leads.delete",
        ]
        conn.execute(
            "INSERT INTO roles(id,name,permission_type,permissions) VALUES(5,?,?,?)",
            ("Sales Manager", "custom", json.dumps(actor_permissions)),
        )
        conn.executemany(
            "INSERT INTO groups(id,name) VALUES(?,?)",
            [(1, "Sales-A"), (2, "Sales-B")],
        )
        conn.executemany(
            """INSERT INTO users
               (id,name,email,password,status,role_id,view_permission,created_by)
               VALUES(?,?,?,?,?,?,?,?)""",
            [
                (10, "Group-A manager", "manager-a@example.test", "test-hash", 1, 5, "group", None),
                (20, "Group-B user", "user-b@example.test", "test-hash", 1, 5, "group", None),
            ],
        )
        conn.executemany(
            "INSERT INTO user_groups(group_id,user_id) VALUES(?,?)",
            [(1, 10), (2, 20)],
        )
        conn.executemany(
            "INSERT INTO leads(id,title,user_id) VALUES(?,?,?)",
            [
                (902, "Victim group confidential lead", 20),
                (903, "Victim group delete-test lead", 20),
            ],
        )
        conn.commit()
        expect(conn.execute("PRAGMA integrity_check").fetchone()[0] == "ok", "seed database passes SQLite integrity_check", results)

        actor = conn.execute("SELECT * FROM users WHERE id=10").fetchone()
        actor_role = conn.execute("SELECT * FROM roles WHERE id=?", (actor["role_id"],)).fetchone()
        actor_permissions_from_db = set(json.loads(actor_role["permissions"]))
        expect(actor["view_permission"] == "group", "actor is group-scoped", results)
        expect("settings.user.users.create" in actor_permissions_from_db, "actor role includes user-creation permission", results)
        expect("leads.view" in actor_permissions_from_db, "actor role includes lead-view permission", results)
        expect(conn.execute("SELECT group_id FROM user_groups WHERE user_id=10").fetchone()[0] == 1, "actor belongs only to Sales-A", results)

        # Model request validation and the two controller guard methods.
        requested_role_id = 5
        requested_scope = "group"
        requested_group_ids = [2]
        role = conn.execute("SELECT * FROM roles WHERE id=?", (requested_role_id,)).fetchone()
        expect(role is not None, "submitted role_id passes exists:roles,id", results)
        requested_permissions = set(json.loads(role["permissions"] or "[]"))
        expect(role["permission_type"] != "all", "submitted custom role is not a full-administrator role", results)
        expect(requested_permissions.issubset(actor_permissions_from_db), "role-assignment guard accepts a role whose permissions are a subset of the actor's", results)

        scope_rank = {"individual": 0, "group": 1, "global": 2}
        ceiling = max(scope_rank[actor["view_permission"]], scope_rank["individual"])
        expect(scope_rank[requested_scope] <= ceiling, "scope guard accepts group scope for a group-scoped actor", results)

        existing_group_ids = {r[0] for r in conn.execute("SELECT id FROM groups")}
        expect(set(requested_group_ids).issubset(existing_group_ids), "submitted group_id passes exists:groups,id", results)
        actor_group_ids = {r[0] for r in conn.execute("SELECT group_id FROM user_groups WHERE user_id=10")}
        expect(set(requested_group_ids).isdisjoint(actor_group_ids), "submitted group is outside the actor's own membership", results)

        # This is the key missing check: current controller validates existence, then syncs
        # all submitted group IDs without intersecting them with actor-authorized groups.
        new_id = 30
        pwd_hash = php_bcrypt(PASSWORD)
        with conn:
            conn.execute(
                """INSERT INTO users
                   (id,name,email,password,status,role_id,view_permission,created_by)
                   VALUES(?,?,?,?,?,?,?,?)""",
                (new_id, "Provisioned Sales-B user", "provisioned-b@example.test", pwd_hash, 1, requested_role_id, requested_scope, 10),
            )
            conn.executemany(
                "INSERT INTO user_groups(group_id,user_id) VALUES(?,?)",
                [(group_id, new_id) for group_id in requested_group_ids],
            )
        expect(conn.execute("SELECT status FROM users WHERE id=30").fetchone()[0] == 1, "created account is active (status=1)", results)
        expect(conn.execute("SELECT created_by FROM users WHERE id=30").fetchone()[0] == 10, "created_by records the attacking manager", results)
        expect(conn.execute("SELECT group_id FROM user_groups WHERE user_id=30").fetchone()[0] == 2, "user_groups pivot now places the new account in Sales-B", results)

        # Confirm the attacker-selected password is a valid bcrypt hash, using PHP's
        # password_verify (same primitive Laravel's bcrypt hasher relies on).
        stored_hash = conn.execute("SELECT password FROM users WHERE id=30").fetchone()[0]
        verify = subprocess.run(
            ["php", "-r", 'exit(password_verify($argv[1], $argv[2]) ? 0 : 1);', PASSWORD, stored_hash],
            check=False,
        )
        expect(verify.returncode == 0, "new account password is known to the account creator and verifies as bcrypt", results)

        actor_ids = authorized_user_ids(conn, 10)
        new_account_ids = authorized_user_ids(conn, 30)
        expect(actor_ids == [10], f"original manager's authorized user IDs remain {actor_ids}", results)
        expect(new_account_ids == [20, 30], f"new account's authorized user IDs include Sales-B member IDs {new_account_ids}", results)

        actor_visible = visible_leads(conn, 10)
        new_visible = visible_leads(conn, 30)
        expect(not any(row[0] == 902 for row in actor_visible), "original Sales-A manager cannot read the Sales-B lead", results)
        expect(any(row[0] == 902 for row in new_visible), "new account can read the Sales-B lead through the normal lead-owner scope query", results)

        # LeadController::update() and ::destroy() both call Controller::preventUnauthorizedAccess()
        # before changing the record. The guard trusts this same authorized-user-ID list.
        actor_role_permissions = set(json.loads(conn.execute(
            "SELECT permissions FROM roles WHERE id=5"
        ).fetchone()[0]))
        expect("leads.edit" in actor_role_permissions, "actor role includes the Lead edit ACL permission", results)
        expect("leads.delete" in actor_role_permissions, "actor role includes the Lead delete ACL permission", results)
        expect(20 not in actor_ids, "original actor fails the owner-ID authorization gate for a Sales-B record", results)
        expect(20 in new_account_ids, "new account passes the same owner-ID authorization gate for a Sales-B record", results)

        # Apply the write operations only after the same owner check used by LeadController.
        if 20 in new_account_ids and "leads.edit" in actor_role_permissions:
            with conn:
                conn.execute(
                    "UPDATE leads SET title=? WHERE id=? AND user_id=?",
                    ("Changed by cross-group provisioned account", 902, 20),
                )
        updated_title = conn.execute("SELECT title FROM leads WHERE id=902").fetchone()[0]
        expect(updated_title == "Changed by cross-group provisioned account", "cross-group account updates a victim-group lead after passing the production owner-ID gate", results)

        if 20 in new_account_ids and "leads.delete" in actor_role_permissions:
            with conn:
                conn.execute("DELETE FROM leads WHERE id=? AND user_id=?", (903, 20))
        deleted_row = conn.execute("SELECT id FROM leads WHERE id=903").fetchone()
        expect(deleted_row is None, "cross-group account deletes a victim-group lead after passing the production owner-ID gate", results)

        # Negative controls: an account placed in the actor's own group does not expand its scope;
        # a non-existent group cannot pass the actual FK constraint.
        with conn:
            conn.execute(
                """INSERT INTO users
                   (id,name,email,password,status,role_id,view_permission,created_by)
                   VALUES(?,?,?,?,?,?,?,?)""",
                (31, "Control same-group user", "control-a@example.test", pwd_hash, 1, 5, "group", 10),
            )
            conn.execute("INSERT INTO user_groups(group_id,user_id) VALUES(1,31)")
        expect(authorized_user_ids(conn, 31) == [10, 31], "control account in Sales-A does not inherit Sales-B user IDs", results)
        expect(not any(row[0] == 902 for row in visible_leads(conn, 31)), "control account in Sales-A cannot read the Sales-B lead", results)
        expect(20 not in authorized_user_ids(conn, 31), "control account in Sales-A fails the owner-ID gate for Sales-B updates/deletes", results)

        fk_rejected = False
        try:
            with conn:
                conn.execute("INSERT INTO user_groups(group_id,user_id) VALUES(999,31)")
        except sqlite3.IntegrityError:
            fk_rejected = True
        expect(fk_rejected, "database foreign key rejects a group ID that does not exist", results)

        # Report the exact rows that prove the cross-group relationship.
        results.append("")
        results.append("=== database evidence ===")
        results.append("Actor 10 groups: " + repr([r[0] for r in conn.execute("SELECT group_id FROM user_groups WHERE user_id=10")]))
        results.append("New user 30 groups: " + repr([r[0] for r in conn.execute("SELECT group_id FROM user_groups WHERE user_id=30")]))
        results.append("Authorized IDs for actor 10: " + repr(actor_ids))
        results.append("Authorized IDs for new user 30: " + repr(new_account_ids))
        results.append("Lead 902 visible to actor 10: " + repr([tuple(x) for x in actor_visible if x[0] == 902]))
        results.append("Lead 902 visible to new user 30 before modification: " + repr([tuple(x) for x in new_visible if x[0] == 902]))
        results.append("Lead 902 title after cross-group update: " + repr(conn.execute("SELECT title FROM leads WHERE id=902").fetchone()[0]))
        results.append("Lead 903 exists after cross-group delete: " + repr(conn.execute("SELECT id FROM leads WHERE id=903").fetchone()))
        results.append("SQLite database: " + str(DB_PATH))
        results.append("SQLite integrity_check: " + conn.execute("PRAGMA integrity_check").fetchone()[0])
        results.append("Foreign key violations: " + repr(conn.execute("PRAGMA foreign_key_check").fetchall()))
        results.append("")
        results.append("RESULT: cross-group account provisioning, lead read access, lead update and lead deletion reproduced in a real local SQLite database.")
        results.append("BOUNDARY: database-level reproduction with Krayin-derived schema and source-matched SQL; Laravel HTTP routing, middleware, Eloquent model hydration, and session login were not run.")
        conn.close()

    except Exception as exc:
        results.append(f"[ERROR] {type(exc).__name__}: {exc}")
        try:
            conn.close()  # type: ignore[name-defined]
        except Exception:
            pass
        LOG_PATH.write_text("\n".join(results) + "\n", encoding="utf-8")
        print("\n".join(results))
        return 1

    LOG_PATH.write_text("\n".join(results) + "\n", encoding="utf-8")
    print("\n".join(results))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())