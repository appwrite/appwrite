//! json_encode (flags) and json_decode (validity, depth, objects vs arrays, error codes).
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `json.*`).
