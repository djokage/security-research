# vhost-device-vsock sibling packet queue reproduction

## Result

The sibling-VM forwarding path stores guest-originated packets in the recipient backend's VecDeque<RawVsockPacket> without a queue length limit or backpressure check.

A focused test was applied to the production vhost-device-vsock source at:

0484a75df5c8e77a708af10b24dd34f596b25084

The tests run against the pinned upstream source. The unit-level guest-TX test builds guest memory and a split TX virtqueue with 511 readable two-descriptor VSOCK packet chains, then invokes the production process_tx() routine. The receiver has no RX buffers and does not drain its raw-packet queue. A second test invokes the production sibling send_pkt() path for 2,048 maximum-sized packets. A separate integration test starts one real vhost-device-vsock daemon process with two --vm configurations, connects two independent vhost-user frontends, shares file-backed guest memory with each backend, negotiates features and configures all three virtqueues per device.

Observed output:

    VSOCK_GUEST_TX_QUEUE packets=511 payload_each=65536 queued_packets=511 queued_payload_bytes=33488896 tx_used_index=511 receiver_drained=false
    VSOCK_SIBLING_QUEUE packets=2048 payload_each=65536 queued_packets=2048 queued_payload_bytes=134217728 receiver_drained=false

The guest-TX path accepted and completed all 511 TX descriptor chains (used index 511) and retained 33,488,896 bytes (31.94 MiB) of payload in the receiver queue. The direct production-method stress test retained 2,048 packets containing 134,217,728 bytes (128 MiB) of copied payload. This is twice the configured 1,024-entry virtqueue size. The recipient queue is a separate VecDeque; it is not bounded by the virtqueue size.

## Full vhost-user socket integration

The integration test holds both the source TX and destination RX available indices at zero while the two devices are configured. It then publishes the 511 source TX chains and kicks queue 1 through the source vhost-user frontend. All 511 TX descriptors complete while the destination RX used index remains zero. The test then publishes 511 destination RX chains and kicks queue 0 through the destination frontend. All 511 RX descriptors complete, and the test verifies the contents of all 511 received 64 KiB payloads.

Observed output:

    VSOCK_VHOST_USER_E2E packets=511 payload_each=65536 verified_payload_bytes=33488896 tx_used_index=511 rx_used_index=511 receiver_initial_rx_buffers=0 delayed_rx_delivery=verified

This exercises the daemon process, both actual Unix vhost-user control sockets, shared guest RAM file descriptors, negotiated features, vring configuration, kick eventfds, TX processing, sibling forwarding, delayed RX posting, and RX delivery. It is a complete vhost-user transport integration test with emulated guest memory and virtqueues; it does not boot a guest operating system or launch a hypervisor.

## Test execution

Successful GitHub Actions run:

https://github.com/djokage/security-research/actions/runs/43501555395

Results:

- Unmodified Unix-backend crate tests: 33 passed, 0 failed.
- Guest TX virtqueue reproduction: 1 passed; 511 TX descriptor chains completed and 33,488,896 queued payload bytes observed.
- Direct sibling-queue stress reproduction: 1 passed; 2,048 packets and 134,217,728 queued payload bytes observed.
- Full vhost-user socket integration: 1 passed; all 511 TX descriptors completed, all 511 RX descriptors completed after RX buffers were delayed, and all 33,488,896 received payload bytes were verified.
- Full Unix-backend crate test suite: 35 passed, 0 failed. The integration test was also run separately with output capture enabled.
- The default backend_vsock feature was disabled because two unrelated upstream tests require the unavailable vsock_loopback kernel module. The Unix-domain-socket backend used by this reproduction was tested.

## Relevant code path

- VsockThreadBackend::send_pkt() detects a destination CID belonging to a sibling VM, checks that the two VMs share a group, copies the packet with RawVsockPacket::from_vsock_packet(), and calls push_back() on the sibling's raw packet queue.
- RawPktsQ is a VecDeque<RawVsockPacket>. There is no maximum queue length or byte budget in this insertion path.
- VhostUserVsockThread::process_rx_queue() only calls recv_raw_pkt() after it obtains an available RX descriptor. If the recipient does not post RX descriptors, the queued packets are not popped.
- VhostUserVsockBackend::start_backend_servers() starts a backend thread per configured VM and shares one Arc<RwLock<CidMap>> between them. The default group name is "default".

The source files thread_backend.rs, vhu_vsock_thread.rs, vhu_vsock.rs, and main.rs have identical Git blob hashes at the tested revision and current upstream main.

## Impact assessment

A guest that can send sibling-vsock traffic to another VM in a shared group can cause the recipient's raw-packet queue to retain guest-controlled payload copies while that recipient does not provide RX buffers. Since the queue is unbounded, repeated traffic can grow memory use in the shared vhost-device-vsock process. In a deployment with multiple VM backends in that process, exhausting or terminating the process can disrupt the other VMs served by it.

This is a reproduced unbounded queue-growth and host-memory-consumption issue reachable through the production guest TX virtqueue processing routine using constructed guest memory and descriptors, as well as through the underlying production send_pkt() method. The test does not launch a guest VM or exercise a complete vhost-user socket session. No host OOM kill or production outage was observed. The demonstrated impact is retained guest-controlled payload memory in a shared backend process; termination of that process could disrupt the other VM backends hosted by it.

## Suggested remediation

Apply a per-recipient packet and byte limit to the sibling raw-packet queue. When the queue is full, apply backpressure or drop packets according to the VSOCK transport's defined behavior. The limit should be enforced before copying the guest payload into a new RawVsockPacket, so rejected traffic does not incur the allocation cost.
