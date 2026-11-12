Standalone reproduction output (Rust 1.91.0, Linux; allocation child limited to 256 MiB):

    PARSER declared_size=1 parsed_sg_count=1 parsed_sg_len=4294967295
    INVALID_RANGE unwrap_panicked=true
    OVERSIZED_ALLOCATION child_status=None success=false
    CHILD_STDERR=memory allocation of 4294967295 bytes failed

The parent reproduction process completed. The child allocation process did not return a normal exit code because the allocation failure aborted it.

The source is a standalone reproduction of the parser/mapping sequence. It does not execute the full upstream Cargo workspace.
