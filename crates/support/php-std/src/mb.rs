//! Multibyte (mbstring) functions on UTF-8: mb_strlen, mb_substr, mb_strtolower/upper, mb_str_split, mb_check_encoding, mb_convert_case.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `mb.*`).
