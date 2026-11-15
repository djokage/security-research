# Krayin CRM: CSV imports bypass individual record ownership restrictions

**Product:** Krayin CRM  
**Version tested:** `v2.2.8`  
**Affected feature:** Data Transfer CSV imports — Leads and Persons  
**Classification:** Missing object-level authorization (CWE-862)

## Summary

Krayin supports individual record visibility through the user's `view_permission` setting. A user configured with individual visibility should only be able to operate on records assigned to that user.

The Data Transfer import path does not enforce that ownership boundary. An authenticated account with the Data Transfer upload and import permissions can submit CSV rows that modify another user's lead, modify another user's contact, reassign those records to itself, or delete another user's contact. The import validation checks that supplied IDs, emails, and owner IDs exist, but does not verify that the target record is within the importing user's authorized data scope.

The upload and execution permissions are separate from the user's record visibility setting. This is therefore a cross-owner authorization flaw in the importer, not an unauthenticated endpoint or a general permission bypass.

## Security impact

A user with the required import permissions and individual data scope can:

- Change fields on a lead owned by another user and reassign that lead to the importing user.
- Change a contact owned by another user and reassign the contact to the importing user.
- Delete a contact owned by another user by importing its email address in a delete operation.

These operations compromise CRM data integrity and availability. The contact deletion path can remove business contact records, while unauthorized reassignment can move leads and contacts out of their owner's workload. This finding does not demonstrate administrator privilege escalation, unauthenticated access, or general read access to other users' records. The attacker must know the target lead ID or contact email.

## Preconditions

1. The attacker has an authenticated CRM account.
2. The account has both the Data Transfer create/upload permission and the Data Transfer import permission.
3. The account is configured with `view_permission=individual`.
4. The attacker knows the target lead ID or contact email.

An administrator can grant the Data Transfer permissions while configuring the account for individual visibility; the two controls are configured independently in the user-management code.

## Reproduction

The following examples use the IDs and fixture data from the attached reproduction harness. In a live test installation, use valid related record IDs and an existing contact email.

### 1. Modify and reassign a lead outside the user's scope

Create a lead with ID `101` owned by user `22`. Log in as user `11`, whose individual scope resolves to `[11]`, and import this row using the Leads importer:

```csv
id,title,description,lead_value,status,user_id,person_id,lead_source_id,lead_type_id,lead_pipeline_id,lead_pipeline_stage_id
101,Victim Deal,modified by restricted user,100,1,11,51,1,1,1,1
```

**Expected:** The row is rejected because lead `101` is owned by user `22`, outside the importing user's scope.

**Actual:** The row is accepted. The description is changed and the lead owner is changed from user `22` to user `11`.

### 2. Modify and reassign a contact outside the user's scope

Create a contact owned by user `22` with the email `victim.contact@example.test`. Import the following row using the Persons importer:

```csv
name,emails,contact_numbers,job_title,organization_id,user_id
Modified Contact,"[{""label"":""work"",""value"":""victim.contact@example.test""}]","[{""label"":""work"",""value"":""5550000999""}]",Changed by CSV import,8,11
```

**Expected:** The row is rejected because the matching contact belongs to user `22`.

**Actual:** The importer matches the existing contact by email, updates its fields, and changes its owner to user `11`. The cross-owner mutation occurred while the actor's authorized user IDs remained `[11]`.

### 3. Delete a contact outside the user's scope

Create a contact owned by user `22` with the email `delete.victim@example.test`. Select the Persons delete action and import:

```csv
emails,contact_numbers
"[{""label"":""work"",""value"":""delete.victim@example.test""}]","[{""label"":""work"",""value"":""5550000333""}]"
```

**Expected:** The delete row is rejected because the contact is outside the importing user's scope.

**Actual:** The row is accepted and the contact is deleted.

## Technical cause

`Bouncer::getAuthorizedUserIds()` returns only the current user's ID for individual visibility. `Bouncer::scopeToAuthorizedUsers()` is an explicit query helper, not a model-wide scope, so code paths must apply it when resolving records.

The importer paths do not apply that check:

- The Leads importer validates the supplied lead ID and verifies that the submitted `user_id` exists. It does not compare the existing lead's owner with the importing user's authorized IDs before updating it.
- The Persons importer resolves existing contacts by email and accepts an existing `user_id`, but does not compare the matched contact's owner with the importing user's scope before updating it.
- The Persons delete path resolves contact IDs from submitted email addresses and deletes the matches without checking their owners.
- The import controller does not add a record-owner check before the validation and execution paths.

The relevant source locations are listed below.

## Validation

The reproduction harness completed **30/30 assertions** against the `v2.2.8` source. It loads and exercises Krayin's production Bouncer, CSV parser, `AbstractImporter` validation/batching pipeline, concrete Leads and Persons importer classes, and their storage classes. It successfully reproduces all three cross-owner effects described above.

The harness uses in-memory framework, validation, authentication, repository, and persistence doubles because the source archive did not include `vendor/` and the test environment had no database driver. It inspects the actual route and ACL configuration, but does not boot Laravel's HTTP router or execute a real SQL transaction. The result is a production-source method-level reproduction, rather than a full browser-to-database demonstration.

Run it against an extracted `v2.2.8` source tree:

```bash
KRAYIN_ROOT=/path/to/laravel-crm-v2.2.8 bash reproducers/krayin-importer-scope/run.sh
```

The exact 30-assertion output is included at [`evidence/krayin-importer-scope-30-assertions.txt`](evidence/krayin-importer-scope-30-assertions.txt). The standalone harness and run instructions are in [`../reproducers/krayin-importer-scope/`](../reproducers/krayin-importer-scope/).

The finding was tested against Krayin CRM `v2.2.8`. Other releases have not been assessed.

## Recommended fix

Apply record-level authorization to every import operation that resolves an existing record:

1. Before updating a lead or person, verify that the existing record's owner is within the importing user's authorized data scope.
2. Treat the submitted `user_id` as data, not as proof of authorization. If reassignment is supported, separately authorize both the existing record and the requested new owner.
3. Before deleting a person, verify the owner of every matched contact against the same scope.
4. Add regression tests for individual, group, and global visibility covering lead update, person update, and person deletion through CSV imports.

## Source references

All references below point to the tested `v2.2.8` tag.

- [Bouncer.php — individual scope and explicit scope helper](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Bouncer.php#L49-L95)
- [AdminServiceProvider.php — authenticated admin route middleware](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Providers/AdminServiceProvider.php#L42-L44)
- [settings-routes.php — Data Transfer import routes](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Routes/Admin/settings-routes.php#L355-L388)
- [acl.php — separate Data Transfer create and import permissions](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Config/acl.php#L544-L575)
- [ImportController.php — upload and validation](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Http/Controllers/Settings/DataTransfer/ImportController.php#L60-L105) and [import execution](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/Admin/src/Http/Controllers/Settings/DataTransfer/ImportController.php#L238-L280)
- [Leads Importer.php — lead validation](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/DataTransfer/src/Helpers/Importers/Leads/Importer.php#L170-L210) and [lead update](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/DataTransfer/src/Helpers/Importers/Leads/Importer.php#L475-L505)
- [Persons Importer.php — person validation, update, and delete](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/DataTransfer/src/Helpers/Importers/Persons/Importer.php#L155-L225)
- [Persons Importer.php — delete operation](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/DataTransfer/src/Helpers/Importers/Persons/Importer.php#L312-L348)
- [Lead and Person storage — records loaded without applying the owner scope](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/DataTransfer/src/Helpers/Importers/Leads/Storage.php#L39-L53) and [Persons Storage.php](https://github.com/krayin/laravel-crm/blob/v2.2.8/packages/Webkul/DataTransfer/src/Helpers/Importers/Persons/Storage.php#L42-L59)