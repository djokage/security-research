# vhost-device-vsock sibling packet queue reproduction

## Result

The sibling-VM forwarding path stores guest-originated packets in the recipient backend's VecDeque<RawVsockPacket> without a queue length limit or backpressure check.

A focused test was applied to the production vhost-device-vsock source at:

0484a75df5c8e77a708af10b24dd34f596b25084

The test creates two configured VhostUserVsockBackend instances sharing the same CidMap, activates both backends, and sends valid 64 KiB VSOCK stream packets from the source backend to the recipient. Both backends use the default "default" group. The recipient does not drain its raw-packet queue.

Observed output:

    VSOCK_SIBLING_QUEUE packets=2048 payload_each=65536 queued_packets=2048 queued_payload_bytes=134217728 receiver_drained=false

The recipient retained 2,048 packets containing 134,217,728 bytes (128 MiB) of copied payload. This is twice the configured 1,024-entry virtqueue size. The queue itself is a separate VecDeque; it is not bounded by the virtqueue size.

## Test execution

GitHub Actions run:

https://github.com/djokage/security-research/actions/runs/43473423083

Results:

- Unmodified Unix-backend crate tests: 33 passed, 0 failed.
- Targeted sibling-queue reproduction: 1 passed.
- Full Unix-backend crate tests after adding the reproduction: 34 passed, 0 failed.
- The default backend_vsock feature was disabled for this CI run because two unrelated upstream tests require the unavailable vsock_loopback kernel module. The Unix-domain-socket backend used by this reproduction was tested.

## Relevant code path

- VsockThreadBackend::send_pkt() detects a destination CID belonging to a sibling VM, checks that the two VMs share a group, copies the packet with RawVsockPacket::from_vsock_packet(), and calls push_back() on the sibling's raw packet queue.
- RawPktsQ is a VecDeque<RawVsockPacket>. There is no maximum queue length or byte budget in this insertion path.
- VhostUserVsockThread::process_rx_queue() only calls recv_raw_pkt() after it obtains an available RX descriptor. If the recipient does not post RX descriptors, the queued packets are not popped.
- VhostUserVsockBackend::start_backend_servers() starts a backend thread per configured VM and shares one Arc<RwLock<CidMap>> between them. The default group name is "default".

The source files thread_backend.rs, vhu_vsock_thread.rs, vhu_vsock.rs, and main.rs have identical Git blob hashes at the tested revision and current upstream main.

## Impact assessment

A guest that can send sibling-vsock traffic to another VM in a shared group can cause the recipient's raw-packet queue to retain guest-controlled payload copies while that recipient does not provide RX buffers. Since the queue is unbounded, repeated traffic can grow memory use in the shared vhost-device-vsock process. In a deployment with multiple VM backends in that process, exhausting or terminating the process can disrupt the other VMs served by it.

This is a reproduced unbounded queue-growth and host-memory-consumption issue in the backend's sibling forwarding method. The test calls the production send_pkt() method on the configured source backend; it does not launch a guest VM or exercise the complete vhost-user socket and virtqueue transport. No host OOM kill or production outage was observed. Those are impact consequences to assess, not claimed test results.

## Suggested remediation

Apply a per-recipient packet and byte limit to the sibling raw-packet queue. When the queue is full, apply backpressure or drop packets according to the VSOCK transport's defined behavior. The limit should be enforced before copying the guest payload into a new RawVsockPacket, so rejected traffic does not incur the allocation cost.
