# vhost-device-i2c: guest descriptor lengths cause host memory amplification

## Summary

The I2C backend allocates a host buffer using the guest-controlled length of each data descriptor before converting that length to the 16-bit length used by its I2C request structure.

The descriptor length is a 32-bit VirtIO field, but the backend stores it in `I2cReq.len` by casting it to `u16`. The original, full-sized `Vec` is retained in the request list. A guest can therefore make each request allocate and copy far more data than the I2C operation will use.

The backend processes all available request chains into one `reqs` vector before calling the adapter. With a 1024-entry queue, 341 three-descriptor requests fit in one batch. Reusing the same valid guest buffer in all 341 requests caused about 342 MiB of resident host memory growth from a roughly 1 MiB guest buffer.

The daemon supports multiple guest sockets in one process. When configured with more than one socket, a guest that exhausts this shared process can also take the I2C backend service away from the other connected guests.

## Affected component

- Project: `rust-vmm/vhost-device`
- Component: `vhost-device-i2c`
- File: `vhost-device-i2c/src/vhu_i2c.rs`
- Function: `VhostUserI2cBackend::process_requests()`
- Tested revision: `0484a75df5c8e77a708af10b24dd34f596b25084`
- CWE: CWE-789 (Memory Allocation with Excessive Size); CWE-400 (Uncontrolled Resource Consumption)
- Impact: Host-side denial of service; cross-tenant impact when multiple guest sockets share one daemon process

The relevant I2C source files on current upstream `main` have the same Git blob hashes as the tested revision.

## Technical details

For every request with a data descriptor, `process_requests()`:

1. Reads the descriptor's 32-bit length.
2. Allocates `vec![0; len as usize]`.
3. Checks the descriptor direction and, for write requests, copies the entire descriptor payload from guest memory.
4. Creates an `I2cReq` with `len: len as u16`, while retaining the original full-sized buffer.
5. Pushes that request into `reqs`.

Only after all request chains have been processed does it call `self.i2c_map.transfer(&mut reqs)`. The per-request vectors therefore remain allocated together for the duration of the adapter operation.

- [Descriptor allocation and 16-bit truncation](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-i2c/src/vhu_i2c.rs#L183-L218)
- [Adapter call after all requests are built](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-i2c/src/vhu_i2c.rs#L221-L228)
- [Queue size is 1024 entries](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-i2c/src/vhu_i2c.rs#L38-L38)

For a descriptor length of 1,048,577 bytes, the buffer allocation and guest-memory copy still use all 1,048,577 bytes, but `len as u16` becomes 1. The backend retains the large buffer even though the I2C request length is only one byte.

## Reproduction

A focused test patch was applied to the tested upstream source. It constructs real `virtio_queue::Queue` descriptor chains backed by `GuestMemoryMmap` and calls the production `VhostUserI2cBackend::process_requests()` method.

The test submits 341 request chains. Each chain contains an 8-byte readable I2C header, a 1,048,577-byte readable data descriptor, and a one-byte writable status descriptor. Every data descriptor points to the same valid 1,048,577-byte guest-memory buffer.

All 341 chains fit in the 1024-entry queue because each uses three descriptor-table entries.

Observed output:

```text
I2C_REAL_PROCESS_REQUESTS requests=341 descriptor_len=1048577 i2c_req_len=1 buf_len=1048577 aggregate_buffer_bytes=357564757 vmhwm_before_kib=4984 vmhwm_after_kib=355284 vmhwm_delta_kib=350300
```

The test passed. It measured 357,564,757 bytes of aggregate request buffers and a 350,300 KiB increase in process high-water RSS. The request length passed to the adapter structure was 1 byte for each 1,048,577-byte buffer.

A second test ran two guest-like queues concurrently, each with 341 requests, using separate guest-memory maps and backend instances that shared the same `Arc<I2cMap>` as the daemon does:

```text
I2C_SHARED_DAEMON_GUEST guest=1 requests=341 descriptor_len=262145 i2c_req_len=1 buffer_bytes=89391445
I2C_SHARED_DAEMON_GUEST guest=0 requests=341 descriptor_len=262145 i2c_req_len=1 buffer_bytes=89391445
I2C_SHARED_DAEMON_CONCURRENCY guests=2 requests_per_guest=341 aggregate_request_buffer_bytes=178782890 vmhwm_before_kib=4040 vmhwm_after_kib=181192 vmhwm_delta_kib=177152
```

The same test patch also exercised the allocation-before-validation case. A request with a 4,294,967,286-byte data descriptor and invalid descriptor permissions attempted the allocation before the permission check. In a child process limited to 1 GiB of address space, the production path failed the allocation and aborted:

```text
memory allocation of 4294967286 bytes failed
I2C_MAX_DESCRIPTOR_CHILD status=None signal=Some(6)
```

The large valid-buffer test exercises the production request-processing method and real queue/guest-memory descriptor objects. The adapter in that test is a test double. The tests do not boot a complete VM or send descriptors over a live vhost-user socket.

## Shared-daemon impact

The daemon's `--socket-count` option supports multiple guest sockets. Its startup code creates one `Arc<I2cMap>` and clones it into the per-socket threads:

- [The socket-count option](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-i2c/src/main.rs#L58-L68)
- [One shared I2C map used by the guest socket threads](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-i2c/src/main.rs#L261-L293)

The default socket count is one. In a deployment configured for multiple guests, a malicious guest can repeatedly submit oversized descriptor batches and consume memory in the shared daemon. If the process is terminated by the OOM killer or allocation failure, all guest sockets served by that process lose their I2C backend.

## Expected behavior

The backend should reject data descriptor lengths that cannot be represented by the VirtIO-I2C request length before allocating or copying the payload. Allocation should also be bounded by an explicit protocol limit. A failed allocation should be handled as a request error rather than aborting the daemon.

## Test suite

The complete focused test suite used for this finding reported:

```text
test result: ok. 24 passed; 0 failed; 2 ignored; 0 measured; 1 filtered out
```

The two ignored ioctl-interposer tests were run separately when investigating the SMBus address race; they are not needed for this memory-amplification finding. Formatting checks also passed.

## Notes

This is a memory exhaustion issue in the I2C request parser. It is separate from the I2C address allowlist bypass already documented in this repository.
