//! PCRE emulation: preg_match/preg_match_all/preg_replace/preg_split/preg_quote with PHP pattern syntax, delimiters and modifiers.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `pcre.*`).
