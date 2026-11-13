# SPI allocation validation results

Tested upstream revision: `rust-vmm/vhost-device@0484a75df5c8e77a708af10b24dd34f596b25084`

Research branch: `spi-allocation-validation`

Successful GitHub Actions run: https://github.com/djokage/security-research/actions/runs/43461679472

## Runtime results

The workflow built `vhost-device-spi`, ran its existing unit tests, applied the local test patch, and ran three bounded reproductions.

- Existing SPI backend tests: 9 passed, 0 failed.
- One 32 MiB transfer with invalid transfer parameters:
  - Expected TX + RX allocation: 67,108,864 bytes.
  - Observed `VmPeak` increase: 65,544 KiB.
  - The request returned `ParamErr`.
- Four 16 MiB requests in one guest descriptor queue:
  - Expected TX + RX allocation: 134,217,728 bytes.
  - Observed `VmPeak` increase: 131,104 KiB.
  - All four requests returned `ParamErr`.
  - Observed `VmHWM` increase: 65,504 KiB. This is resident high-water memory, not the total virtual allocation.
- Two independently-created backend instances processed valid 32 MiB transfers concurrently:
  - Expected combined TX + RX allocation: 134,217,728 bytes.
  - Observed `VmHWM` increase: 193,928 KiB.
  - Both requests completed with `TransOk`.

The test patch is in [spi-allocation-order.patch](spi-allocation-order.patch).

## Source path

In `vhost-device-spi/src/vhu_spi.rs`, the request handler constructs TX and RX vectors from guest descriptor lengths before `check_trans_params()` is called. `check_trans_params()` validates SPI parameters but does not impose a maximum transfer length.

In `vhost-device-spi/src/main.rs`, `socket_count` creates one thread per configured guest socket. Each thread calls `start_backend_server()`, which opens a device, creates a controller and backend, and serves a vhost-user daemon. Thus the implementation creates multiple backend contexts inside the same host process when multiple sockets are configured.

## What this establishes

The tests demonstrate that guest descriptor lengths drive large host allocations before SPI parameter validation, that multiple requests in one queue scale those allocations, and that two independent backend contexts can hold large allocations concurrently in the same test process.

## What this does not establish

The test does not start real guest VMs or exchange descriptors over actual vhost-user Unix sockets. The two-context test calls the backend queue handler directly in two threads, using a synchronized dummy SPI device. It therefore demonstrates concurrent backend memory consumption, not a complete deployed cross-tenant exploit.

It also does not exhaust host memory, measure an OOM kill, or demonstrate effects on unrelated host processes. The 32 MiB and 16 MiB sizes are bounded test inputs, not a claim that every deployment accepts those exact sizes under every memory configuration.

## Assessment

This is a runtime-confirmed guest-descriptor-controlled allocation defect in the tested backend path, with a demonstrated memory-consumption impact. The multi-socket source structure and concurrent backend test support a shared-process resource-exhaustion concern. A full socket/VM-level test is still needed before claiming end-to-end cross-tenant exploitation or a specific production denial-of-service outcome.
