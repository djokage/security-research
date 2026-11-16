# Security research

The reports include the affected code, reproduction details and observed impact.

## Reports

### [Krayin CRM: group membership assignment is not restricted to the acting user's groups](reports/krayin-group-membership-scope-bypass.md)

A delegated group-scoped user can assign a newly created account, or an eligible existing account, to a group outside the actor's own group memberships. The resulting account can gain access to leads owned by users in that group, subject to the account's role permissions.

- Project: [krayin/laravel-crm](https://github.com/krayin/laravel-crm)
- Version examined: Krayin CRM `v2.2.8`
- Area: Admin user creation and update
- Classification: CWE-862 — Missing Authorization
- Evidence: 31-assertion file-backed SQLite reproduction; separate 29-assertion source-method test is documented in the report
- Reproducer: [group membership scope tests](reproducers/krayin-group-membership-scope/)

### [Krayin CRM: CSV imports bypass individual record ownership checks](reports/krayin-importer-ownership-scope-bypass.md)

An authenticated account with individual record visibility and the Data Transfer import permissions can use CSV imports to modify and reassign another user's leads and contacts, and delete another user's contact.

- Project: [krayin/laravel-crm](https://github.com/krayin/laravel-crm)
- Tested: Krayin CRM `v2.2.8`
- Area: PHP, Laravel, Data Transfer importer authorization
- Evidence: [30-assertion reproduction output](reports/evidence/krayin-importer-scope-30-assertions.txt)
- Reproducer: [CSV importer harness](reproducers/krayin-importer-scope/)

### [Livewire: `#[Json]` methods run before global authorization hooks](reports/livewire-global-hook-authorization-order.md)

A global `ComponentHook::call()` can reject a JSON action after its method has already run. The database-backed test also shows an authenticated non-admin modifying an administrator-owned record before the request returns HTTP 403.

- Project: [livewire/livewire](https://github.com/livewire/livewire)
- Area: PHP, Laravel, Livewire component authorization
- Tested: Livewire 4.4.8, Laravel 13.42.0, PHP 8.4.24
- Evidence: [Cross-user SQLite test output](reports/evidence/livewire-cross-user-db-proof.txt)

### [vhost-device-gpu: bad SUBMIT_3D fence count can crash the backend](reports/vhost-device-gpu-guest-dos.md)

A guest-controlled fence count causes a large host-memory allocation. The reproduction triggers a process abort in the GPU backend.

- Project: [rust-vmm/vhost-device](https://github.com/rust-vmm/vhost-device)
- Area: Rust, virtio, GPU virtualization
- Impact: denial of service in the host-side GPU backend

### [vhost-device-i2c: multi-message transfers bypass the configured address list](reports/vhost-device-i2c-allowlist-bypass.md)

The backend checks only the first I2C message address and forwards later unconfigured addresses in the same host transfer.

- Project: [rust-vmm/vhost-device](https://github.com/rust-vmm/vhost-device)
- Area: Rust, virtio, I2C virtualization
- Impact: guest access to unconfigured I2C slave addresses on the selected host adapter


A standalone reproduction fixture is available at [reproducers/livewire-global-hook-json-order](reproducers/livewire-global-hook-json-order/). It includes a minimal Laravel test harness for the global-hook ordering case and authorization controls.
