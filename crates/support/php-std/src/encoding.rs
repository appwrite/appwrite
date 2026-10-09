//! Encodings: urlencode/rawurlencode and decoding, http_build_query, base64, hex, htmlspecialchars/html_entity_decode, addslashes.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `encoding.*`).
