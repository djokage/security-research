# Krayin CRM importer reproduction

This harness exercises the production CSV source parser, `AbstractImporter` validation and batching path, and the concrete Leads and Persons importer and storage methods from Krayin CRM `v2.2.8`.

## Run

Extract the `v2.2.8` source archive, then run from the root of this repository:

```bash
KRAYIN_ROOT=/path/to/krayin-crm-v2.2.8 bash reproducers/krayin-importer-scope/run.sh
```

Requirements: PHP CLI and the extracted Krayin source tree. The harness supplies in-memory doubles for framework services, validation, authentication, repositories, and persistence. It does not boot Laravel's HTTP router or execute a real database transaction.

## Coverage

- Update and reassign a lead owned by another user.
- Update and reassign a contact owned by another user.
- Delete a contact owned by another user.
- Check individual-scope resolution, authentication middleware, and Data Transfer ACL mapping.

## Result

The captured output is in `reports/evidence/krayin-importer-scope-30-assertions.txt`: **30/30 assertions passed**. This is a production-source method-level reproduction with mocked framework and persistence boundaries, not a full HTTP-to-database integration test.
