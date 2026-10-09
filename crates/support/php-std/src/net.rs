//! Network helpers: ip2long/long2ip, inet_pton/inet_ntop, idn_to_ascii/idn_to_utf8, checkdate-free host helpers.
//!
//! Every function here is checked against the real PHP function by
//! `bin/compat fuzz php-std` (operations `net.*`).
