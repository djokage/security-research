# vhost-device-media SG-length amplification reproduction

This directory contains a standalone reproduction of the relevant request parsing, allocation ordering and error handling.

## Build and run

On Linux with Rust installed:

    rustc --edition=2021 repro.rs -o repro
    ./repro

The allocation case runs in a child process with RLIMIT_AS set to 256 MiB. This keeps the reproduction from requesting a multi-gigabyte allocation in the parent process.

## What it demonstrates

1. A declared one-byte payload is accepted with an SG entry length of 4,294,967,295.
2. An invalid guest-memory range produces an error that reaches the caller's unwrap.
3. The attempt to allocate 4,294,967,295 bytes fails in the constrained child process.

This is a standalone model of the relevant production flow, not a Cargo test run against the complete upstream workspace. The production source references and affected call path are included in the accompanying report.
