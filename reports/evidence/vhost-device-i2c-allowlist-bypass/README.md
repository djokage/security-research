# I2C allowlist regression tests

These tests exercise `VhostUserI2cBackend::process_requests()` with a recording device. No I2C hardware is needed.

Source revision: `0484a75df5c8e77a708af10b24dd34f596b25084`
Crate: `vhost-device-i2c 0.1.0`
Toolchain: Rust 1.91.0 / Cargo 1.91.0
Dependencies: vendored, offline

## Run

From the `rust-vmm/vhost-device` source tree:

```sh
git checkout 0484a75df5c8e77a708af10b24dd34f596b25084
curl -L https://raw.githubusercontent.com/djokage/security-research/main/reports/evidence/vhost-device-i2c-allowlist-bypass/regression-tests.patch -o /tmp/i2c-regression-tests.patch
git apply /tmp/i2c-regression-tests.patch
CARGO_NET_OFFLINE=true cargo test -p vhost-device-i2c process_requests_allowlist_ -- --nocapture --test-threads=1
```

The tests cover an allowed address followed by an unconfigured address, the reversed order, a valid same-adapter transfer, and two configured addresses mapped to different adapters.

The test patch adds test-only instrumentation and request-processing tests. It does not change production code.
