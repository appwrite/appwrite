//! Builds PCRE2 10.44 with its JIT, from the sources PHP 8.5 bundles
//! (`ext/pcre/pcre2lib`, copied to `pcre2/`), the way PHP's
//! `ext/pcre/config0.m4` builds them: the same files, `PCRE2_CODE_UNIT_WIDTH`
//! 8 and the same `config.h` settings. `php_std::pcre` calls it as PHP's
//! `ext/pcre` does.

const SOURCES: &[&str] = &[
    "pcre2_auto_possess.c",
    "pcre2_chartables.c",
    "pcre2_chkdint.c",
    "pcre2_compile.c",
    "pcre2_config.c",
    "pcre2_context.c",
    "pcre2_convert.c",
    "pcre2_dfa_match.c",
    "pcre2_error.c",
    "pcre2_extuni.c",
    "pcre2_find_bracket.c",
    "pcre2_jit_compile.c",
    "pcre2_maketables.c",
    "pcre2_match_data.c",
    "pcre2_match.c",
    "pcre2_newline.c",
    "pcre2_ord2utf.c",
    "pcre2_pattern_info.c",
    "pcre2_script_run.c",
    "pcre2_serialize.c",
    "pcre2_string_utils.c",
    "pcre2_study.c",
    "pcre2_substitute.c",
    "pcre2_substring.c",
    "pcre2_tables.c",
    "pcre2_ucd.c",
    "pcre2_valid_utf.c",
    "pcre2_xclass.c",
    "php_std_glue.c",
];

fn main() {
    let dir = std::path::Path::new("pcre2");
    println!("cargo::rerun-if-changed=pcre2");
    let mut build = cc::Build::new();
    build
        .include(dir)
        .define("HAVE_CONFIG_H", None)
        .define("HAVE_MEMMOVE", None)
        .define("PCRE2_CODE_UNIT_WIDTH", "8")
        .define("PCRE2_STATIC", None)
        .warnings(false)
        .opt_level(2);
    for file in SOURCES {
        build.file(dir.join(file));
    }
    build.compile("pcre2-8");
}
