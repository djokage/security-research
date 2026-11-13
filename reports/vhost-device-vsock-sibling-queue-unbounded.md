# vhost-device-vsock: sibling VM forwarding queue has no memory limit

## Summary

The Unix-domain-socket backend can retain guest-controlled VSOCK payloads indefinitely in a sibling VM's receive queue when that VM does not provide RX buffers.

The guest TX path parses packets from the TX virtqueue and passes them to `VsockThreadBackend::send_pkt()`. When the destination CID belongs to another configured VM in a shared group, the backend copies the packet payload into a `RawVsockPacket` and appends it to the recipient's `VecDeque`. There is no packet-count limit, byte limit, or backpressure check on this queue.

The TX descriptor is still completed after the packet has been accepted into the sibling queue. The recipient only removes queued packets while processing RX descriptors. If it does not post RX buffers, the queue remains populated while the sender can continue submitting TX packets.

## Affected component

- Project: `rust-vmm/vhost-device`
- Component: `vhost-device-vsock`
- Cargo package version at tested revision: `0.3.0`
- Analyzed revision: `0484a75df5c8e77a708af10b24dd34f596b25084`
- Backend: Unix domain socket
- Configuration: two or more VM backends in the same daemon process and in a shared VSOCK group
- CWE: CWE-770 (Allocation of Resources Without Limits or Throttling)

The four relevant source files have identical Git blob hashes at the tested revision and current upstream `main`.

## Technical details

In `VhostUserVsockThread::process_tx_queue()`, the backend reads each guest TX descriptor chain with `VsockPacket::from_tx_virtq_chain()`, then passes the packet to `self.thread_backend.send_pkt()`. Once accepted, the TX descriptor is sent for completion:

- [TX virtqueue processing](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-vsock/src/vhu_vsock_thread.rs#L738-L799)

For a destination CID belonging to a sibling VM, `VsockThreadBackend::send_pkt()` checks whether the source and destination groups overlap. If they do, it copies the packet into the destination's raw-packet queue using `RawVsockPacket::from_vsock_packet(pkt)` and `push_back()`. There is no queue capacity or byte-budget check before the copy:

- [Sibling forwarding and queue insertion](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-vsock/src/thread_backend.rs#L323-L380)
- [RawVsockPacket payload copy](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-vsock/src/thread_backend.rs#L35-L52)

The recipient consumes raw packets from this queue when it has available RX descriptors. With no RX descriptors, the queue is not drained. The default group name is `default`, so deployments that leave multiple VM backends in the default group permit this sibling forwarding relationship:

- [Default group and queue configuration](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-vsock/src/main.rs#L81-L84)

## Attack scenario

1. A guest runs on a VM whose VSOCK backend is served by a `vhost-device-vsock` daemon.
2. Another VM is configured in the same daemon process and shares a VSOCK group with the source VM.
3. The receiving VM does not provide RX buffers, so its sibling raw-packet queue is not drained.
4. The source guest submits VSOCK TX descriptor chains addressed to the receiving VM's CID.
5. The daemon copies each payload into the recipient's unbounded queue and completes the source TX descriptors.
6. Repeated submissions grow memory retained by the shared daemon process.

## Reproduction evidence

A validation patch was applied to the pinned upstream source in GitHub Actions. It includes three distinct reproductions.

The guest TX virtqueue unit test builds guest memory and 511 valid two-descriptor VSOCK packets, then calls the production `process_tx()` routine. The receiver has no RX buffers.

Observed output:

```
VSOCK_GUEST_TX_QUEUE packets=511 payload_each=65536 queued_packets=511 queued_payload_bytes=33488896 tx_used_index=511 receiver_drained=false
```

The receiver queue retained 33,488,896 bytes (31.94 MiB) of copied payload.

A direct stress test of the production sibling forwarding method sends 2,048 packets of 64 KiB each:

```
VSOCK_SIBLING_QUEUE packets=2048 payload_each=65536 queued_packets=2048 queued_payload_bytes=134217728 receiver_drained=false
```

The receiver queue retained 134,217,728 bytes (128 MiB) of copied payload. This is the sum of queued payload lengths, not a measured process RSS increase.

The full vhost-user integration test starts one real `vhost-device-vsock` daemon process with two VM configurations, connects two independent vhost-user frontend sockets, shares file-backed guest RAM, negotiates features, configures all three virtqueues for each device, and uses the real kick eventfds. The source TX queue is made available while the target RX queue has no available buffers. All TX descriptors complete while target RX used index stays at zero. The test then publishes the target RX buffers and verifies every payload after the daemon delivers it.

Observed output:

```
VSOCK_VHOST_USER_E2E packets=511 payload_each=65536 verified_payload_bytes=33488896 tx_used_index=511 rx_used_index=511 receiver_initial_rx_buffers=0 delayed_rx_delivery=verified
```

All 511 TX descriptors and all 511 RX descriptors completed. The test verified all 33,488,896 bytes received through the target frontend.

[Successful GitHub Actions run and logs](https://github.com/djokage/security-research/actions/runs/43501555395)

The integration test exercises the actual daemon and complete vhost-user control/data transport using constructed guest memory and virtqueue descriptors. It does not boot a guest operating system or launch a hypervisor. No host OOM kill or production outage was observed.
  
## Impact

A guest able to submit VSOCK traffic to a sibling VM in the same configured group can cause unbounded memory retention in the shared `vhost-device-vsock` process when the receiving VM does not consume packets. Sustained traffic can exhaust memory available to the daemon and cause its termination. If the daemon serves multiple VMs, this can disrupt the VSOCK devices of the other VMs hosted by that process.

This is a denial-of-service condition against the shared backend process. It is not a claim that a guest can affect VMs served by separate daemon processes or isolated into non-overlapping VSOCK groups.

## Expected behavior

The sibling receive queue should have a strict resource bound. A receiver that does not post RX buffers must not allow guest traffic to consume unbounded host memory.

## Suggested fix

Enforce per-recipient packet and byte limits before copying payload data into a new `RawVsockPacket`. When the limit is reached, apply a defined backpressure or packet-drop policy consistent with the VSOCK transport behavior. The limit should cover both packet count and aggregate payload bytes.
