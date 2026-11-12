# vhost-device-sound: RX descriptor length underflow can crash the ALSA capture worker

## Summary

The RX PCM request path in `vhost-device-sound` does not validate that the guest-provided writable descripter contains the full `VirtioSoundPcmStatus` structure before subtracting its size from the available descriptor length.

In `vhost-device-sound/src/device.rs`, the code calculates the RX payload length as:

```rust
writer.available_bytes() - size_of::<VirtioSoundPcmStatus>()
```

The status structure is 8 bytes. A guest can provide a writable descriptor with only 4 bytes availble.

On a 64-bit release build with overflow checks disabled, the subtraction wraps to `18446744073709551612`. This value is stored as the PCM request length and is later used by the ALSA capture backend to allocate an intermediate buffer.

The resulting allocation panics and terminates the capture worker. A subsequent RX request can then panic in the request-handling path when the backend sends work through the channel whose receiver belonged to the terminated worker.

## Affected component

- Project: `rust-vmm/vhost-device`
- Component: `vhost-device-sound`
- Version at the tested revision: `0.3.0`
- Tested revision: `0484a75df5c8e77a708af10b24dd34f596b25084`

## Technical details

### 1. RX descriptor length is not validated

In `device.rs`, `process_io()` reads the guest's `VirtioSoundPcmXfer` header and calculates the writable payload length for RX requests by subtracting the size of `VirtioSoundPcmStatus` from `writer.available_bytes()`.

There is no check that the writable descriptor contains at least 8 bytes before the subtraction.

A guest can submit a valid 4-byte `VirtioSoundPcmXfer` header with a writable response area of only 4 bytes. The calculation is then:

```rust
4usize - 8usize
```

In a debug build this causes an integer-underflow panic.

In an optimized 64-bit release build with overflow checks disabled, it wraps to:

```text
18446744073709551612
```

The wrapped value is passed to `Request::new()` and retained as the request length.

### 2. The ALSA capture worker panics on the oversized length

In `audio_backends/alsa.rs`, the capture implementation allocates an intermediate buffer using the remaining request length:

```rust
let mut intermediate_buf = vec![0; request.len() - request.pos];
```

The wrapped length is far beyond the maximum supported vector capacity. The allocation expression panics, terminating the ALSA worker thread.

The worker processes capture requests only when the stream is in the `PCMState::Start` state. The guest therefore configures and starts the capture stream before submitting the malformed RX request. Capture is enabled by default unless the backend is started with `--no-capture`.

### 3. A later request can panic the sound request handler

The ALSA backend's `read()` method sends work to the capture worker using:

```rust
self.senders[stream_id as usize].send(true).unwrap();
```

When the worker has panicked, its receiver is dropped. A subsequent RX request reaches this send operation, which panics because the channel is disconnected.

With the default single-threaded queue arrangement, the sound device's Control, Event, TX and RX queues are handled by the same device thread. A panic in that request-handling thread stops processing for the virtual sound device.

## Impact

A guest with access to the VirtIO sound device can trigger a denial of service against the host-side `vhost-device-sound` backend.

The malformed RX request crashes the ALSA capture worker. A subsequent request can also crash the device queue-handling thread, making the virtual sound device unusable, including its playback and capture request processing.

The affected scope is the backend process handling that virtual sound device. If a deployment shares one backend process between multiple VMs, every VM relying on that process may be affected.

## Reproduction

The issue was reproduced with standalone Rust tests:

- In a debug build, subtracting the 8-byte status structure size from a 4-byte writable descriptor triggers an integer-underflow panic.
- In a release build with overflow checks disabled, the calculation produces `18446744073709551612`.
- Attempting to allocate the corresponding ALSA-style capture buffer panics.
- The reproduction also exercises the worker failure and a subsequent send through the disconnected channel.

The evidence package includes the reproduction source, debug and release logs, and a test patch targeting the actual `VhostUserSoundThread::process_io()` implementation. The complete workspace Cargo test suite was not run.

## Relevant source

- [device.rs — RX request length calculation](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-sound/src/device.rs)
- [alsa.rs — capture buffer allocation and worker communication](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-sound/src/audio_backends/alsa.rs)
- [lib.rs — writing the PCM status response](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-sound/src/lib.rs)
- [main.rs — default capture configuration](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-sound/src/main.rs)

## Suggested fix

Validate the writable descriptor length before subtracting the status structure size. Reject an RX request when the available writable space is smaller than `size_of::<VirtioSoundPcmStatus>()`.

The ALSA capture path should also enforce a reasonable bound on request lengths before allocating memory. Finally, worker-channel disconnection should be handled as an error instead of calling `unwrap()`, so a terminated worker does not cause another panic in the request-handling thread.

## Classification

- CWE-191: Integer Underflow (Wrap or Wraparound)
- CWE-789: Memory Allocation with Excessive Size Value
- Impact: Denial of Service
