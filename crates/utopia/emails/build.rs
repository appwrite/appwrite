//! Builds the hash tables of the embedded domain lists at compile time: for each
//! of `data/*.txt`, an open-addressing table of its lines' byte offsets
//! (`src/table.rs`), written as a `u32` array. The arrays hold no pointers, so
//! they stay in the library's read-only pages, shared by every process that
//! loads it, and a lookup needs no work or allocation at run time.

use std::fmt::Write as _;
use std::{env, fs, path::Path};

#[allow(dead_code)]
mod table {
    include!("src/table.rs");
}

fn main() {
    println!("cargo:rerun-if-changed=src/table.rs");
    let mut out = String::new();
    for (name, file) in [("FREE", "data/free-domains.txt"), ("DISPOSABLE", "data/disposable-domains.txt")] {
        println!("cargo:rerun-if-changed={file}");
        let source = fs::read(file).unwrap_or_else(|error| panic!("{file}: {error}"));
        let (slots, entries) = table::build(&source);
        write!(
            out,
            "pub(crate) const {name}_ENTRIES: usize = {entries};\npub(crate) static {name}_TABLE: [u32; {}] = [",
            slots.len()
        )
        .expect("writing to a String");
        for slot in slots {
            write!(out, "{slot},").expect("writing to a String");
        }
        out.push_str("];\n");
    }
    fs::write(Path::new(&env::var("OUT_DIR").expect("OUT_DIR")).join("tables.rs"), out)
        .expect("writing the list tables");
}
