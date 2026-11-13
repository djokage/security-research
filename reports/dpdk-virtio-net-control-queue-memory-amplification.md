# DPDK vhost: virtio-net control requests have no aggregate size limit

## Summary

The vhost virtio-net control queue parser allocates a buffer based on the sum of the lengths of all readable descriptors in a guest-supplied descriptor chain. There is no upper bound on that aggregate length before the allocation and copy take place.

A guest can make multiple readable descriptors refer to the same valid guest-memory buffer. The parser counts every descriptor length separately and copies the referenced bytes for every descriptor, even when the descriptors all point to the same memory. This allows a relatively small guest-memory region to trigger a much larger host allocation and copy in one control request.

In a deployment where one DPDK vhost process serves multiple guests, memory exhaustion in that process can affect all guests connected to it.

## Affected component

- Project: DPDK
- Component: vhost virtio-net control queue
- File: `lib/vhost/virtio_net_ctrl.c`
- Function: `virtio_net_ctrl_pop()`
- CWE: CWE-789 (Memory Allocation with Excessive Size); CWE-400 (Uncontrolled Resource Consumption)
- Impact: Denial of service; cross-tenant impact is conditional on multiple guests sharing the same DPDK vhost process

## Technical details

The parser walks the readable descriptors and adds each descriptor length to `data_len`. It then checks that the request is large enough to contain the control class and command bytes, allocates `data_len` bytes, and performs a second pass that copies the contents of each readable descriptor into that allocation.

The copy-length guard added to prevent a copied-length overrun checks that the copy does not exceed the previously calculated `data_len`. It does not impose a maximum request size. As a result, a descriptor chain whose aggregate length is very large still passes this check and can cause a correspondingly large allocation.

- [`virtio_net_ctrl_pop()` in DPDK](https://github.com/DPDK/dpdk/blob/main/lib/vhost/virtio_net_ctrl.c)
- [Guest physical address to host virtual address mapping](https://github.com/DPDK/dpdk/blob/main/lib/vhost/rte_vhost.h)

The address mapping validates that the guest address belongs to a mapped guest-memory region and limits the mapped length to that region. It does not prevent multiple descriptors from referring to the same valid address. Repeated references therefore still contribute their full lengths to the aggregate and are copied repeatedly.

A common QEMU virtio-net configuration creates a control virtqueue with 64 entries:

- [QEMU virtio-net control queue setup](https://github.com/qemu/qemu/blob/54e42cfec9d75f05ef035e24b6da2c7e5411d362/hw/net/virtio-net.c#L3102-L3103)

That is sufficient for the following descriptor chain: 63 readable descriptors followed by the required one-byte writable acknowledgement descriptor.

## Reproduction

A standalone C reproduction of the descriptor aggregation and copy logic was run. It used 64 queue entries:

- 63 readable descriptors, each with a length of 1 MiB
- All 63 readable descriptors pointed to the same valid 1 MiB buffer
- One final writable acknowledgement descriptor of length 1 byte

Observed output:

```
control queue entries: 64
readable descriptors:  63
unique guest buffer:    1048576 bytes
summed data_len:        66060288 bytes (63 MiB)
peak RSS before alloc:  1664 KiB
copied bytes:           66060288
copy verification:      passed for all 63 descriptors
peak RSS after copy:    66048 KiB
host copy amplification: 63x
```

The test confirms that this descriptor pattern produces a 63 MiB allocation and copy from a single 1 MiB guest buffer when the parser's aggregation and copy logic is applied.

This was a standalone reproduction of the relevant parser algorithm. It did not execute DPDK's actual `virtio_net_ctrl_pop()` function or instantiate a complete vhost-user device. An upstream integration test is still needed to verify the complete descriptor and guest-memory mapping path.

## Attack scenario

1. A guest is connected to a DPDK vhost-user backend.
2. The guest submits a virtio-net control request with many readable descriptors.
3. The descriptors repeatedly reference the same valid guest-memory buffer, while each descriptor advertises the buffer length.
4. The parser adds every descriptor length to `data_len`, allocates the aggregate size, and copies the data once for each descriptor.
5. The guest repeats the request to increase memory pressure in the DPDK process.

The control request is validated after the aggregate allocation and copy, so an invalid or unsupported command does not avoid the resource cost of processing the descriptor payload.

## Impact

A malicious guest can cause host-side memory allocation and copying far beyond the size of the unique guest buffer it supplies. With 63 descriptors each advertising 256 MiB, and a guest-memory mapping large enough to cover that range, the aggregate allocation would be 15.75 GiB for one request.

The practical impact depends on the guest-memory mapping, available host memory, and the DPDK deployment. In a shared vhost process serving multiple guests, sufficiently large or repeated requests can exhaust process or host memory, stall the backend, or cause it to be terminated by the OOM killer. Other guests attached to that process can consequently lose their backend service. This is a conditional cross-tenant denial of service, not an assertion that every DPDK deployment shares one process between tenants.

## Expected behavior

The backend should reject control requests whose aggregate readable payload exceeds a small protocol-appropriate maximum. The limit should be checked before allocating the aggregate buffer or copying descriptor contents. Request lengths should also be validated against the specific control command rather than accepting arbitrary trailing payload.

## Suggested fix

Add a strict maximum aggregate size for virtio-net control requests and reject requests that exceed it before calling `malloc()`. Where possible, validate the expected length for each supported control command before allocating and copying the request. Keep the existing copied-length check as a separate bounds check; it is not a substitute for a resource limit.
