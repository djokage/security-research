# vhost-device-i2c: multi-message transfers bypass the configured address list

I found an address-checking bug in the I2C backend. The backend checks the first message in a transfer against the configured device list, but it does not check the addresses of the remaining messages.

A guest can put an allowed address in the first message and a different, unconfigured address in the next message. The backend forwards both messages to the same host I2C adapter.

## Where

Tested revision:

`0484a75df5c8e77a708af10b24dd34f596b25084`

Crate version: `vhost-device-i2c 0.1.0`

File: `vhost-device-i2c/src/i2c.rs`

The relevant code is:

```rust
pub fn transfer(&self, reqs: &mut [I2cReq]) -> Result<()> {
    let device = reqs[0].addr;

    let index = match self.device_map.get(&device) {
        Some(&index) => index,
        None => return Err(Error::ClientAddressInvalid),
    };

    let adapter = &self.adapters[index];
    adapter.set_device_addr(device as usize)?;
    adapter.transfer(reqs)
}
```

Only `reqs[0].addr` is checked. The full request array is then passed to the adapter.

In `PhysDevice::rdwr()`, the backend copies the address from every request into the Linux `i2c_msg` structure:

```rust
msgs.push(I2cMsg {
    addr: req.addr,
    flags: req.flags,
    len: req.len,
    buf: req.buf.as_mut_ptr(),
});
```

Those messages are submitted together through the Linux `I2C_RDWR` ioctl. Each `i2c_msg` carries its own slave address, so setting the file descriptor's address with `I2C_SLAVE` does not restrict the addresses in an `I2C_RDWR` transfer.

## Test

I tested the real `VhostUserI2cBackend::process_requests()` path with the production `I2cMap` and a dummy I2C device.

The configured address list contained only `0x04`. I sent a two-message guest batch with addresses `0x04` and `0x55`.

Output:

```text
I2C_REAL_PROCESS_REQUESTS_ALLOWLIST_BYPASS configured=[0x04] guest_batch=[0x04,0x55] backend_last_addr=0x55 status=OK
```

The backend accepted the batch and passed the second, unconfigured address to the adapter.

I also tested the production `PhysDevice::rdwr()` implementation with an ioctl interceptor. It captured the actual `I2C_RDWR` arguments constructed by the backend:

```text
I2C_RDWR_INTERCEPT nmsgs=2 addrs=0x04,0x55
```

The ioctl interceptor captured the arguments at the syscall boundary. The test confirms that the unconfigured address is included in the host ioctl request.

The Linux VirtIO-I2C frontend supports transfers containing multiple messages and encodes the address of each message in its request. This makes the address sequence reachable through the normal guest I2C interface.

## Impact

A guest with access to the VirtIO-I2C device can bypass the configured `--device-list` address restriction by placing an allowed address first and an unconfigured address later in the same transfer.

The backend sends the unconfigured address in the host's `I2C_RDWR` request on the selected adapter. This allows guest-originated I2C transactions to target slave addresses that the administrator did not configure for that guest. Depending on the devices connected to that adapter, this can expose peripheral data or allow unauthorized device operations.

This is an access-control bypass in the host-side I2C backend.

## Possible fix

Validate every request address against the configured address map before forwarding the batch. Also verify that all messages in a transfer belong to the same configured adapter.

## Test details

- Project: `rust-vmm/vhost-device`
- Crate: `vhost-device-i2c`
- Crate version: `0.1.0`
- Commit: `0484a75df5c8e77a708af10b24dd34f596b25084`
- Affected code: `vhost-device-i2c/src/i2c.rs`
- Backend path: `VhostUserI2cBackend::process_requests()` → `I2cMap::transfer()` → `PhysDevice::rdwr()`

## Links

- [Tested I2C address check](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-i2c/src/i2c.rs#L597-L613)
- [Tested I2C_RDWR implementation](https://github.com/rust-vmm/vhost-device/blob/0484a75df5c8e77a708af10b24dd34f596b25084/vhost-device-i2c/src/i2c.rs#L406-L429)
- [Linux VirtIO-I2C frontend](https://github.com/torvalds/linux/blob/master/drivers/i2c/busses/i2c-virtio.c)
- [Linux I2C userspace interface](https://docs.kernel.org/i2c/dev-interface.html)
- [vhost-device-i2c crate](https://github.com/rust-vmm/vhost-device/tree/main/vhost-device-i2c)
