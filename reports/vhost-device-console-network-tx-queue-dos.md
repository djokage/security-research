# vhost-device-console: Network backend can retain guest TX data without a TCP client

## Summary

The Network backend in `vhost-device-console` can accumulate guest console output in host memory without a limit when no TCP client is connected.

When the guest submits data on the VirtIO console TX queue, `process_tx_requests()` copies the descriptor payload into `output_queue`. It then calls `write_tcp_stream()`. That function only removes queued data when `self.stream` is present. Before a TCP client has connected, `self.stream` is `None`, so the queued data is left in memory.

The TX descriptors are returned to the guest after processing, allowing the guest to submit more data. There is no queue-size limit, byte limit, or backpressure in this path.

## Affected component

- Project: `rust-vmm/vhost-device`
- Component: `vhost-device-console`
- Cargo package version: `0.1.0`
- Analyzed revision: `0484a75df5c8e77a708af10b24dd34f596b25084`
- Backend: Network
- CWE: CWE-770 (Allocation of Resources Without Limits or Throttling)

## Technical details

In `process_tx_requests()`, guest TX data is copied into a `Vec<u8>` and appended to `output_queue`. In Network mode, the code then calls `write_tcp_stream()`:

- [`process_tx_requests()`](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-console/src/vhu_console.rs#L241-L280)

The queue is drained only inside the `if let Some(stream) = self.stream.as_mut()` branch. If there is no connected TCP client, that branch is skipped and the queued buffers remain retained:

- [`write_tcp_stream()`](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-console/src/vhu_console.rs#L582-L590)

The Network listener can be created before a client is accepted. The active stream is populated later, after the accept operation:

- [`assign_input_method()` and Network listener setup](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-console/src/vhu_console.rs#L185-L200)
- [TCP accept and stream setup](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-console/src/vhu_console.rs#L550-L580)

After the payload is queued, the TX descriptor is added to the used ring. The guest can therefore reuse descriptors and continue submitting data rather than being held back by the missing TCP consumer.

## Attack scenario

1. A VM is attached to a `vhost-device-console` instance configured with the Network backend.
2. No host-side TCP client connects to the console listener.
3. The guest continuously writes data to the VirtIO console TX queue.
4. The backend copies each payload into `output_queue`, but does not drain the queue because no TCP stream exists.
5. The backend returns the TX descriptors to the guest, so the guest can continue sending data.

## Impact

A guest with access to this console can cause unbounded host-side memory growth in the `vhost-device-console` process. Sustained input can consume the memory available to the daemon and lead to an out-of-memory condition or process termination. Where one daemon process serves multiple console connections, memory exhaustion can affect the other connections handled by that process as well.

## Reproduction evidence

A standalone Rust reproduction of the queue handling behavior was run with no connected stream. It repeatedly appended guest-sized payloads to the queue and exercised the same drain condition used by `write_tcp_stream()`.

Test parameters and observed result:

- 128 payloads per cycle
- 256 KiB per payload
- 16 cycles
- 2,048 queued entries
- 536,870,912 bytes retained (512 MiB)
- `VmHWM` increased from 992 KiB to 533,660 KiB, a 532,668 KiB increase
- `stream_present=false` at the end of the test

The reproduction demonstrates the unbounded queue growth and corresponding process memory increase. It is a standalone reproduction of the queue operations; it does not instantiate the complete vhost-user backend.

## Expected behavior

Guest output should not be retained without a resource limit while no TCP client is connected. The backend should apply a bounded buffering policy, backpressure, or an explicit drop policy.

## Suggested fix

Apply a strict byte limit to `output_queue` and define what happens when the limit is reached. Depending on the intended console semantics, the backend can drop excess output while no client is connected, or stop accepting additional TX data until queued output can be drained. The limit should be enforced before allocating or retaining additional payloads.
