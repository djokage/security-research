# I2C allowlist regression test results

Source: `rust-vmm/vhost-device`
Revision: `0484a75df5c8e77a708af10b24dd34f596b25084`
Crate: `vhost-device-i2c 0.1.0`
Toolchain: Rust 1.91.0 / Cargo 1.91.0
Build: offline with vendored dependencies

The tests submit real VirtIO-I2C descriptor chains through `VhostUserI2cBackend::process_requests()`. A recording test device counts calls to `slave()` and `rdwr()`.

## Cases

| Configuration | Guest addresses | Expected |
|---|---|---|
| `1:4` | `0x04, 0x55` | Reject |
| `1:4` | `0x55, 0x04` | Reject |
| `1:4:5` | `0x04, 0x05` | Accept |
| `1:4,2:32` | `0x04, 0x20` | Reject |

## Vulnerable revision

Command:

```sh
CARGO_NET_OFFLINE=true cargo test -p vhost-device-i2c process_requests_allowlist_ -- --nocapture --test-threads=1
```

Observed output:

```text
[0x04, 0x05] statuses=[0, 0] slave_calls=1 rdwr_calls=1 rdwr_messages=2
[0x04, 0x20] statuses=[0, 0] slave_calls=1 rdwr_calls=1 rdwr_messages=2
[0x04, 0x55] statuses=[0, 0] slave_calls=1 rdwr_calls=1 rdwr_messages=2
[0x55, 0x04] statuses=[1, 1] slave_calls=0 rdwr_calls=0 rdwr_messages=0

2 passed; 2 failed
```

The two failed assertions are the expected regression failures. The vulnerable code accepts the batch with an unconfigured second address and the batch whose addresses map to different adapters. Both messages are sent through the adapter selected by the first address.

## With the validation change

Observed output:

```text
[0x04, 0x05] statuses=[0, 0] slave_calls=1 rdwr_calls=1 rdwr_messages=2
[0x04, 0x20] statuses=[1, 1] slave_calls=0 rdwr_calls=0 rdwr_messages=0
[0x04, 0x55] statuses=[1, 1] slave_calls=0 rdwr_calls=0 rdwr_messages=0
[0x55, 0x04] statuses=[1, 1] slave_calls=0 rdwr_calls=0 rdwr_messages=0

4 passed; 0 failed
```

Rejected batches do not reach `slave()` or `rdwr()`. The valid same-adapter batch still succeeds.

## Additional checks

```text
Fixed vhost-device-i2c package suite: 21 passed; 0 failed; 1 filtered out
cargo fmt -p vhost-device-i2c -- --check: passed
Deep test patch: 24 passed; 0 failed; 2 ignored; 1 filtered out
Ignored ioctl-interposer tests run separately: 2 passed
```

The package-suite run filtered out the existing `test_phys_device_failure`, which expects `/sys/bus/i2c/devices` to exist. That path is absent in the test container.

The ioctl interceptor captured the production `I2C_RDWR` message vector before the syscall reached the kernel:

```text
I2C_RDWR_INTERCEPT nmsgs=2 addrs=0x04,0x55
```

The separate SMBus interposer test recorded:

```text
I2C_SMBUS_INTERCEPT requested=0x04 effective=0x55 response_byte=0x55
I2C_SMBUS_INTERCEPT requested=0x55 effective=0x55 response_byte=0x55
```

The request-processing tests use a recording test device. The ioctl interceptor returns before the syscall reaches a physical I2C bus.
