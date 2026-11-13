# SPI allocation validation results

Tested upstream revision: `rust-vmm/vhost-device@0484a75df5c8e77a708af10b24dd34f596b25084`

Research branch: `spi-allocation-validation`

## Backend-level reproduction

Successful backend-level run: https://github.com/djokage/security-research/actions/runs/43461679472

The workflow built `vhost-device-spi`, ran its existing tests, applied the local test patch, and ran three bounded tests against the actual SPI backend queue handler.

- Existing SPI backend tests: 9 passed, 0 failed.
- One 32 MiB transfer with invalid transfer parameters:
  - Expected TX + RX vector allocation: 67,108,864 bytes.
  - Observed `VmPeak` increase: 65,544 KiB.
  - The backend returned `ParamErr`.
- Four 16 MiB requests in one guest descriptor queue:
  - Expected combined TX + RX vector allocation: 134,217,728 bytes.
  - Observed `VmPeak` increase: 131,104 KiB.
  - All four requests returned `ParamErr`.
  - Observed `VmHWM` increase: 65,348 KiB.
- Two independently-created backend instances processed valid 32 MiB transfers concurrently:
  - Expected combined TX + RX vector allocation: 134,217,728 bytes.
  - Observed process `VmHWM` increase: 195,104 KiB.
  - Both requests returned `TransOk`.

These tests call `process_queue()` directly with constructed VirtIO descriptor chains. The memory figures are Linux process peak metrics; `VmPeak` is virtual memory peak and `VmHWM` is resident-set high-water mark. They are measurements from the test process, not a demonstration of a host OOM event.

The backend test patch is in [spi-allocation-order.patch](spi-allocation-order.patch).

## Real vhost-user socket attempt

Socket-level test patch: [spi-vhost-user-socket.patch](spi-vhost-user-socket.patch)

Latest socket-test workflow run: https://github.com/djokage/security-research/actions/runs/43465426954

The test starts a `VhostUserDaemon`, connects a vhost-user frontend over a Unix-domain socket, negotiates features, creates shared guest memory with a memfd, configures the virtqueue, and attempts to submit an SPI descriptor chain containing 16 MiB TX and RX buffers.

The test did **not** reach the SPI request handler. The daemon thread failed while handling the memory table with:

```text
HandleRequest(ReqHandlerError(Custom { kind: Other, error: MissingMemoryMapping }))
```

The test then timed out waiting for the used-ring index to advance; it remained zero. Consequently, there is no socket-level allocation measurement and no successful guest-to-daemon reproduction yet.

## Source path

In `vhost-device-spi/src/vhu_spi.rs`, `process_requests()` constructs TX and RX vectors from guest descriptor lengths before calling `check_trans_params()`. The parameter checks validate fields such as chip select, bits per word, transfer mode and bus widths, but do not impose a transfer-length limit.

In `vhost-device-spi/src/main.rs`, `socket_count` starts one thread and backend server per configured guest socket. These backend instances run within the same daemon process.

## What is established

- The request handler allocates TX/RX vectors based on descriptor lengths.
- Those allocations happen before SPI parameter validation.
- The direct backend-level tests measured large increases in process memory metrics for large descriptor lengths.
- Multiple requests and two backend instances can increase memory use concurrently in the test process.
- The multi-socket server code creates separate backend contexts in one process.

## What is not established

- The attempted vhost-user socket test did not successfully install the guest-memory mapping, so it did not exercise the request path through the socket.
- No real guest VM was started.
- No host memory exhaustion, OOM kill, unrelated service outage, or production cross-tenant impact was demonstrated.
- The bounded test sizes do not establish what every deployment will accept under its own memory and descriptor constraints.

## Assessment

The source defect and backend-level memory-consumption behavior are reproduced. This supports a host memory-exhaustion vulnerability candidate.

The end-to-end guest-to-daemon path remains unverified because the current socket test fails during guest-memory mapping setup. Cross-tenant denial of service and a specific production outage should not be claimed as demonstrated until that path is working and tested.
