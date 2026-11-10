# vhost-device-gpu: bad SUBMIT_3D fence count can crash the backend

I was looking at the SUBMIT_3D command handling in vhost-device-gpu and found that the backend uses the guest-provided `num_in_fences` value as a vector capacity before checking whether the descriptor contains the fence IDs.

Setting the value to `0xffffffff` makes the code request 34,359,738,360 bytes for the fence vector (about 32 GiB).

When the allocation fails, Rust aborts the process. A malformed GPU command can therefore terminate the host-side vhost-device-gpu process.

## Where

Tested revision:

`0484a75df5c8e77a708af10b24dd34f596b25084`

File: `vhost-device-gpu/src/protocol.rs`

The allocation is here:

```rust
let mut fence_ids: Vec<u64> =
    Vec::with_capacity(<Le32 as Into<u32>>::into(info.num_in_fences) as usize);

for _ in 0..info.num_in_fences.into() {
    let fence_id = reader
        .read_obj::<u64>()
        .map_err(|_| Error::DescriptorReadFailed)?;
    fence_ids.push(fence_id);
}
```

The code reserves memory first and only then tries to read the fence IDs. It doesnt validate the count before allocating.

## Test

I added a test using `GuestMemoryMmap` and `MockSplitQueue`. It builds a 32-byte descriptor containing:

- a 24-byte GPU control header for `VIRTIO_GPU_CMD_SUBMIT_3D`;
- the 8-byte submit header;
- `size = 0`;
- `num_in_fences = 0xffffffff`;
- no fence IDs after the header.

The test calls the real `GpuCommand::decode()` function and exercises the production decoder.

I ran the crash case in a child process with `RLIMIT_AS=512 MiB` to capture the abort without terminating the main test run.

Output:

```text
running 1 test
memory allocation of 34359738360 bytes failed
CHILD_EXIT_STATUS=134
ISOLATED_CRASH_TEST=EXPECTED_ABORT
```

The normal GPU crate tests also completed:

```text
test result: ok. 23 passed; 0 failed; 1 ignored
```

The ignored test is the one that intentionally requests the oversized allocation. The separate child-process run records the expected abort.

## Impact

This is a denial of service in the GPU backend. The allocation is host system memory used by the CPU-side process, not GPU VRAM.

The reproduction confirms that the malformed SUBMIT_3D command causes the backend process to abort under the test's 512 MiB address-space limit.

`device.rs` calls `GpuCommand::decode()` while processing the control queue. This puts the decoder on the backend's guest-request path.

## Possible fix

Validate the fence count against the remaining descriptor data before allocating. Use checked arithmetic when calculating the required size and handle allocation failure as an error instead of allowing the process to abort.

## Test details

- Project: `rust-vmm/vhost-device`
- Crate: `vhost-device-gpu`
- Commit: `0484a75df5c8e77a708af10b24dd34f596b25084`
- Rust/Cargo: 1.91.0
- Backend: null backend (`--no-default-features`)
- Physical GPU: not needed

## Links

- [Tested source](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-gpu/src/protocol.rs)
- [Current upstream source](https://github.com/rust-vmm/vhost-device/blob/main/vhost-device-gpu/src/protocol.rs)
- [GPU backend documentation](https://github.com/rust-vmm/vhost-device/tree/main/vhost-device-gpu)
