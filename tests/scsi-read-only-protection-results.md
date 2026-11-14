# SCSI read-only write-protection reproduction results

## Source and environment

- Repository: `rust-vmm/vhost-device`
- Revision: `0484a75df5c8e77a708af10b24dd34f596b25084`
- Crate: `vhost-device-scsi` (`0.1.0`)
- Test runner: GitHub Actions, Ubuntu
- Workflow run: https://github.com/djokage/security-research/actions/runs/43539799154
- Workflow head commit: `e98e13358f2db2f304a8dd54bc605ff406a4f414`

## Baseline

Before adding the reproduction tests:

- Library tests: 47 passed, 0 failed.
- Binary tests: 2 passed, 0 failed.

## Reproduction tests

The test-only patch was applied successfully to the exact upstream revision. It modifies only:

`vhost-device-scsi/src/scsi/emulation/tests/mod.rs`

### WRITE(10)

Test: `test_write_10_ignores_write_protected`

- Constructs a production `BlockDevice` with `write_protected = true`.
- Sends a SCSI WRITE(10) command through `EmulatedTarget::execute_command()`.
- Reads the affected block back from the shared in-memory `TestBackend`.

Observed:

```text
test scsi::emulation::tests::test_write_10_ignores_write_protected ... ok
test result: ok. 1 passed; 0 failed
```

The backing block matched the guest-supplied 512-byte payload.

### WRITE SAME(16)

Test: `test_write_same_16_ignores_write_protected`

- Constructs a production `BlockDevice` with `write_protected = true`.
- Sends a SCSI WRITE SAME(16) command through `EmulatedTarget::execute_command()`.
- Reads the two affected blocks back from the shared in-memory `TestBackend`.

Observed:

```text
test scsi::emulation::tests::test_write_same_16_ignores_write_protected ... ok
test result: ok. 1 passed; 0 failed
```

Both blocks contained the guest-supplied pattern.

## Full test suite after adding reproductions

```text
running 49 tests
test result: ok. 49 passed; 0 failed; 0 ignored

running 2 tests
test result: ok. 2 passed; 0 failed; 0 ignored
```

## Interpretation

The production SCSI command implementation accepts WRITE(10) and WRITE SAME(16) and modifies the backing storage even after `BlockDevice::set_write_protected(true)` has been called.

The CLI configuration path in `vhost-device-scsi/src/main.rs` calls this setter when `--read-only` is selected. The setting is also reflected in the SCSI MODE SENSE WP bit, but the tested write-command handlers do not enforce it.

## Scope and limitations

- The tests invoke the production `EmulatedTarget::execute_command()` and `BlockDevice` command handlers.
- The backing store in these tests is the crate's in-memory `TestBackend`, not a physical disk or a file opened by the daemon.
- This was not a complete VM boot or live guest-to-vhost-user socket test.
- No real host image or physical disk was modified.
- No production source code was changed by the reproduction patch.

The reproduction confirms the missing write-protection check in both command handlers. A follow-up daemon-level test using a temporary file-backed image would provide additional end-to-end evidence.
