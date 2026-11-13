# vhost-device-rtc: replacing alarms can leave old worker threads running

I found an issue in the RTC alarm handling. When the guest replaces an alarm, the old worker is asked to stop, but the cancellation does not wake it up.

The worker can already be sleeping until the original alarm time. It checks the cancellation flag only after that sleep finishes. The old thread handle is dropped without joining the worker, so the thread remains alive in the background.

A guest can repeat this by setting an alarm and then replacing it again.

## Affected code

The relevant implementation is in `vhost-device-rtc`:

- [Alarm worker creation and sleep](https://github.com/rust-vmm/vhost-device/blob/main/vhost-device-rtc/src/alarm.rs#L105-L123)
- [Cancellation handling](https://github.com/rust-vmm/vhost-device/blob/main/vhost-device-rtc/src/alarm.rs#L167-L178)
- [Guest SET_ALARM request handling](https://github.com/rust-vmm/vhost-device/blob/main/vhost-device-rtc/src/backend.rs#L284-L327)

The problem is that setting the cancellation flag does not interrupt the sleep. Replacing an alarm can therefore leave the previous worker running until its original deadline.

## Impact

A guest with access to the RTC device can repeatedly replace alarms and make the host process accumulate sleeping threads.

Each thread consumes host resources. If enough workers are left running, the process or host can reach task limits and fail to create new threads. This can disrupt the RTC service and other work handled by the same daemon process.

This is a resource-consumption issue (CWE-400).

## Testing

I reproduced the worker lifecycle in a Linux process using the same sleep, cancellation-flag and detached-thread behavior.

The workers were configured to sleep for 180 seconds. These were the observed thread counts:

| Alarm replacements | Live threads observed |
|---:|---:|
| 128 | 129 |
| 512 | 513 |
| 1,024 | 1,025 |

The extra thread is the main process thread.

This confirms that workers remain alive after cancellation while they are sleeping. The reproduction exercises the thread lifecycle; it is not a full vhost-user integration test.

A test patch for the actual RTC implementation was prepared separately, but the complete upstream Cargo test has not been run. I have kept that distinction here rather than presenting the standalone reproduction as an integration test.

## Suggested fix

Cancellation should wake the sleeping worker instead of making it wait until the original alarm deadline. An interruptible wait or a condition variable with notification on replacement would address this. The worker should also be joined or otherwise have its lifecycle managed so replaced alarms do not leave background threads behind.

## References

- Project: https://github.com/rust-vmm/vhost-device
- Alarm implementation: `vhost-device-rtc/src/alarm.rs`
- Request handling: `vhost-device-rtc/src/backend.rs`
