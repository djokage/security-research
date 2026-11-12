use std::io::{self, Read};
use std::process::Command;

#[derive(Clone, Copy, Debug)]
struct SgEntry { start: u64, len: u32 }

// Mirrors virtio-media 0.0.8 device/src/ioctl.rs::get_userptr_regions.
fn get_userptr_regions<R: Read>(r: &mut R, size: usize) -> io::Result<Vec<SgEntry>> {
    let mut bytes_taken = 0usize;
    let mut res = Vec::new();
    while bytes_taken < size {
        let mut raw = [0u8; 16];
        r.read_exact(&mut raw)?;
        let sg = SgEntry {
            start: u64::from_le_bytes(raw[0..8].try_into().unwrap()),
            len: u32::from_le_bytes(raw[8..12].try_into().unwrap()),
        };
        bytes_taken += sg.len as usize;
        res.push(sg);
    }
    Ok(res)
}

// Mirrors vhost-device-media GuestMemoryMapping::new allocation order:
// sum SG lengths, allocate, THEN validate/read each guest range.
fn guest_memory_mapping_new(sgs: &[SgEntry], guest_mem_len: u64) -> io::Result<Vec<u8>> {
    let total_size = sgs.iter().fold(0usize, |total, sg| total + sg.len as usize);
    let data = vec![0u8; total_size];
    let mut pos = 0usize;
    for sg in sgs {
        let end = sg.start.checked_add(sg.len as u64).ok_or_else(|| io::Error::other("address overflow"))?;
        if end > guest_mem_len {
            return Err(io::Error::other("guest memory range is invalid"));
        }
        pos += sg.len as usize;
    }
    assert_eq!(pos, data.len());
    Ok(data)
}

// Mirrors perform_ext_ctrls_ioctl's collect(...).unwrap() around new_mapping.
fn perform_ext_ctrls_mapping(sgs: &[SgEntry], guest_mem_len: u64) {
    let result: io::Result<Vec<Vec<u8>>> = sgs.iter()
        .map(|sg| guest_memory_mapping_new(std::slice::from_ref(sg), guest_mem_len))
        .collect();
    let _payloads = result.unwrap();
}

#[repr(C)]
struct Rlimit { cur: u64, max: u64 }
unsafe extern "C" { fn setrlimit(resource: i32, limit: *const Rlimit) -> i32; }

fn main() {
    if std::env::args().nth(1).as_deref() == Some("child-alloc") {
        // RLIMIT_AS is 9 on Linux. Keep the child below 256 MiB so this cannot
        // consume large host memory when reproducing the oversized allocation.
        let lim = Rlimit { cur: 256 * 1024 * 1024, max: 256 * 1024 * 1024 };
        let rc = unsafe { setrlimit(9, &lim) };
        if rc != 0 { panic!("setrlimit failed: {rc}"); }
        let sg = SgEntry { start: 0, len: u32::MAX };
        // Allocation occurs before the invalid guest range is checked.
        let _ = guest_memory_mapping_new(&[sg], 0x1000);
        eprintln!("unexpectedly returned without allocation failure");
        std::process::exit(0);
    }

    // A one-byte declared payload is accepted with a maximum u32 SG length.
    let mut bytes = Vec::new();
    bytes.extend_from_slice(&0u64.to_le_bytes());
    bytes.extend_from_slice(&u32::MAX.to_le_bytes());
    bytes.extend_from_slice(&0u32.to_le_bytes());
    let parsed = get_userptr_regions(&mut bytes.as_slice(), 1).unwrap();
    println!("PARSER declared_size=1 parsed_sg_count={} parsed_sg_len={}", parsed.len(), parsed[0].len);

    // An invalid guest-memory range reaches the production caller's unwrap.
    let invalid = [SgEntry { start: 0x2000, len: 1 }];
    let panic = std::panic::catch_unwind(|| perform_ext_ctrls_mapping(&invalid, 0x1000));
    println!("INVALID_RANGE unwrap_panicked={}", panic.is_err());

    // Isolate the maximum-u32 allocation attempt under a 256 MiB address-space cap.
    let exe = std::env::current_exe().unwrap();
    let out = Command::new(exe).arg("child-alloc").output().unwrap();
    println!("OVERSIZED_ALLOCATION child_status={:?} success={}", out.status.code(), out.status.success());
    if !out.stderr.is_empty() {
        println!("CHILD_STDERR={}", String::from_utf8_lossy(&out.stderr).trim());
    }
}