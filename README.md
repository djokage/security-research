# Security research

I publish security issues found while reviewing open-source projects.

The reports include the affected code, reproduction details and observed impact.

## Reports

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
