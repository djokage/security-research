# vhost-device-scsi: --read-only still allows the guest to write to the disk

I found that the `--read-only` option in `vhost-device-scsi` does not actually stop the guest from writing to the disk image.

The option sets the device's write-protected flag, and the device reports this state through SCSI MODE SENSE. But the actual write handlers never check that flag before writing to the backing image.

This means a guest can still modify the disk even when the adminstrator starts the device with `--read-only`.

## Affected component

- Project: rust-vmm/vhost-device
- Component: `vhost-device-scsi`
- Revision checked: `0484a75df5c8e77a708af10b24dd34f596b25084`

## What is happening

In `vhost-device-scsi/src/main.rs`, the disk image is opened with both read and write access. The `--read-only` argument is then passed to `set_write_protected()`.

The flag is stored by the block device and used when reporting the write-protect bit in MODE SENSE responses.

The problem is that the write handlers don't use this flag at all.

I checked the implemented write commands and found that both `WRITE(10)` and `WRITE SAME(16)` can modify the backing image without checking whether write protection is enabled.

So the device can tell the guest that the disk is write-protected, while still accepting commands that change its contents.

## Tests

I made two Rust reproductions covering the affected write handlers and ran them with `write_protected = true`.

**WRITE(10)**

I enabled write protection, executed a write operation and checked the backing image afterwards. The image contents had changed.

**WRITE SAME(16)**

I repeated the test with WRITE SAME(16). The operation modified both affected blocks even though write protection was enabled.

Both tests passed and reproduced the issue.

These were executable standalone reproductions of the vulnerable handler logic, rather than tests run through the complete upstream Cargo workspace.

## Impact

A guest with access to the SCSI device can modify a disk image that was configured as read-only by the host administrator.

This can cause data corruption and allows guests to change storage that was intended to be protected. It is also a problem when disk images are shared between VMs or used as base images and templates that should not be modified.

The read-only setting is an explicit storage access policy. In the current implementation, that policy is not enforced by the write-command handlers.

## Expected behaviour

When `--read-only` is enabled, write commands should be rejected and the backing image should remain unchanged.

The write-protection check needs to happen in the command execution path, before any data is written to the image.

## Relevant code

- `vhost-device-scsi/src/main.rs` — opens the backing image and configures write protection.
- `vhost-device-scsi/src/scsi/emulation/block_device.rs` — stores the write-protected flag and implements the write handlers.
- `vhost-device-scsi/src/vhu_scsi.rs` — processes guest SCSI requests.

## Conclusion

The `--read-only` option currently does not prevent guest writes. Both `WRITE(10)` and `WRITE SAME(16)` can modify the backing disk image while the write-protected flag is enabled.

The device needs to enforce write protection when executing these commands, not just report it through MODE SENSE.
