# pvmemcontrol: guest can make Cloud Hypervisor's guest-memory mapping inaccessible

## Result

A guest-issued `PVMEMCONTROL_MPROTECT_NONE` request can change the host VMM process's own mapping of guest RAM to `PROT_NONE`. A subsequent read through the same `GuestMemoryMmap` mapping terminates the isolated process with `SIGSEGV`.

This is a new finding relative to the existing research reports in this repository. It is not the previously reported IOMMU unknown-endpoint issue or a disk-image allocation issue.

## Affected code

- Project: `cloud-hypervisor/cloud-hypervisor`
- Tested release: v54.0
- Tested commit: `038ce5a72d761380a02f714a4eb4ca63e0ea073d`
- Component: `devices::pvmemcontrol`
- File: `devices/src/pvmemcontrol.rs`

The relevant path is:

1. The guest writes a `PvmemcontrolReq` into its registered per-CPU request buffer.
2. The guest writes its connection command to the PCI BAR command register.
3. `PvmemcontrolBusDevice::handle_guest_write()` resolves the registered GPA and calls `handle_pvmemcontrol_request()`.
4. `process_request()` dispatches `FunctionCode::MprotectNone` to `mprotect(addr, length, PROT_NONE)`.
5. `operate_on_memory_range()` obtains a host pointer from the VMM's own `GuestMemoryMmap` using `memory.get_slice()`, then passes that pointer directly to `libc::mprotect()`.

There is no separate host-only alias used for this operation. The protection change is applied to the same process mapping used by VMM-side guest-memory access.

## Reproduction

A test-only patch was applied to the exact v54.0 source. It adds no production-code changes.

The test uses a real `PvmemcontrolBusDevice` and exercises its `BusDeviceSync::write()` PCI BAR path:

- reset the device;
- register a request buffer at guest physical address `0x1000`;
- transition the device to Ready;
- submit `MprotectNone` for the aligned guest page `[0x2000, 0x3000)`;
- attempt a VMM-side `GuestMemory::read_obj()` from GPA `0x2000`.

The child process is isolated because the expected outcome is a process-level segmentation fault. The parent test requires the child to terminate specifically with `SIGSEGV`; a normal exit or Rust panic fails the test.

Workflow:

https://github.com/djokage/security-research/actions/runs/43543867213

Observed test results:

- Baseline `devices` tests with the pvmemcontrol feature: 15 passed.
- Isolated guest-to-VMM reproduction: 1 passed. The parent test passed only after observing `SIGSEGV` from the child.
- Complete `devices` suite with the reproduction added: 16 passed.

## Impact and prerequisites

This is a guest-triggered VMM-process crash when all of the following apply:

- Cloud Hypervisor is built with the `pvmemcontrol` feature.
- The VM is configured with `--pvmemcontrol`.
- The guest can issue pvmemcontrol requests. The original pvmemcontrol interface description says its userspace ioctl requires `CAP_SYS_ADMIN`; kernel drivers can also issue requests.

A guest with the required privilege can protect a guest-physical page that the VMM later accesses through its guest-memory mapping. That access faults in the VMM process rather than being returned as a normal device error.

This is not a host escape or cross-tenant memory disclosure. The executed PoC demonstrates a crash of the process that owns the affected mapping. It does not demonstrate that one VM can crash a separate tenant's VMM process.

## Current upstream check

The current upstream `main` file still contains the same direct mapping path and `MprotectNone` dispatch:

- Current file blob: `78dbfd5dbe6b45bd8f69c5037e7cb199d228470f`
- `operate_on_memory_range()` still passes the pointer from `GuestMemoryMmap::get_slice()` to the supplied libc operation.
- `FunctionCode::MprotectNone` still dispatches to `mprotect(addr, length, PROT_NONE)`.

The executable reproduction was run against v54.0, not current `main`; current `main` was source-reviewed only.

## Suggested remediation direction

Do not apply guest-selected protection changes to the sole VMM userspace mapping of guest RAM. Use a separate mapping for the VMM's own reads/writes, or otherwise ensure that guest memory operations remain safely accessible to the VMM while host/KVM mapping permissions are updated. A failed or inaccessible guest page should be handled as a device error, not as a process-level SIGSEGV.

## Evidence files

- Reproduction patch: `tests/pvmemcontrol-mprotect-vmm-fault.patch`
- Workflow: `.github/workflows/pvmemcontrol-vmm-memory-protection-validation.yml`
