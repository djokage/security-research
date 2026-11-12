# vhost-device-media: malformed SG lengths cause oversized allocation and a panic

## Summary

The V4L2 proxy in vhost-device-media does not properly validate the scatter-gather (SG) lengths supplied for V4L2 extended-control payloads.

The virtio-media parser reads SG entries until their accumulated length reaches the payload size declared by the control. It does not reject an entry that makes the accumulated length exceed that size. The vhost-device-media mapping code then sums the SG lengths and allocates a buffer of that size before it checks whether the guest-memory ranges are valid.

When a guest-memory read fails, the V4L2 proxy caller unwraps the error. A malformed request can therefore panic the media request-handling path. An SG entry can also make the backend attempt an allocation of almost 4 GiB for a control that declared a one-byte payload.

## Affected component

- Project: rust-vmm/vhost-device
- Component: vhost-device-media
- Affected code revision: 0484a75df5c8e77a708af10b24dd34f596b25084
- Relevant dependency: virtio-media 0.0.8
- Runtime configuration: --backend v4l2-proxy

The crate enables the v4l2-proxy feature by default at build time. The runtime backend defaults to null, so the vulnerable path is reached when the V4L2 proxy backend is selected.

## Technical details

The virtio-media parser in device/src/ioctl.rs, function get_userptr_regions(), takes the declared payload size and reads SG entries while bytes_taken is less than that size. For each entry it adds sg_entry.len to bytes_taken. There is no check that the new total is still within the declared size.

A single SG entry can therefore overshoot the declared payload length. The SG length is a u32, so one entry can report 4,294,967,295 bytes even when the associated control declares only one byte.

The vhost-device-media mapping implementation in vhost-device-media/src/vhu_adapters.rs then calculates total_size by summing the SG lengths and allocates a Vec of that size. Only after allocating does it call guest memory read for each range.

The V4L2 proxy's perform_ext_ctrls_ioctl() in virtio-media/src/devices/v4l2_device_proxy.rs collects the mapping results with unwrap(). An invalid guest-memory range makes the mapping return an error, which reaches this unwrap and panics.

The extended-control parser is used by VIDIOC_G_EXT_CTRLS, VIDIOC_S_EXT_CTRLS and VIDIOC_TRY_EXT_CTRLS.

## Reproduction

The standalone Rust reproduction in the evidence directory mirrors the relevant parser, mapping allocation order and unwrap behaviour.

The test cases produced these results:

- A one-byte declared payload was accepted with one SG entry whose length was 4,294,967,295.
- An invalid guest-memory range caused the mapping error to reach the unwrap panic.
- A separate child process, limited to 256 MiB of address space, failed when attempting the 4,294,967,295-byte allocation.

The reproduction is intentionally isolated from the full project build. It is not an upstream Cargo integration test. The source, run instructions and captured result are included with this report.

## Impact

A guest that can submit crafted VirtIO-media queue descriptors can make the configured V4L2 proxy attempt an extremely large allocation for a much smaller declared payload. Invalid guest-memory ranges also reach an unchecked unwrap and panic the request-handling path.

This can make the media backend unavailable. The large allocation attempt can additionally cause allocation failure or memory/address-space pressure, depending on the host's allocator and memory configuration.

## Suggested fix

Reject SG entries that exceed the remaining declared payload size, and require the accumulated SG length to match the expected payload size. Validate the guest-memory ranges and impose a sensible upper bound before allocating the aggregate buffer. Propagate mapping errors back to the guest instead of unwrapping them.

## Source references

- virtio-media SG parser: https://github.com/chromeos/virtio-media/blob/ed3a56fd39a74bd8117a3cc9c5b8ec8803df19cd/device/src/ioctl.rs#L69-L80
- vhost-device-media guest-memory allocation: https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-media/src/vhu_adapters.rs#L164-L177
- V4L2 proxy mapping and unwrap: https://github.com/chromeos/virtio-media/blob/ed3a56fd39a74bd8117a3cc9c5b8ec8803df19cd/device/src/devices/v4l2_device_proxy.rs#L171-L205
- vhost-device-media backend selection: https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-media/src/args.rs#L35-L46

The guest-controlled SG length is not bounded against the declared payload size before the allocation. This is the core enforcment issue.
