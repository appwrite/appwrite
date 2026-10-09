<?php

// encoding.*: URL, query, base64, hex, HTML entity, slash and
// quoted-printable encodings (ext/standard). The arguments are the
// function's named parameters, passed straight through.

return [
    'encoding.urlencode' => fn (array $a) => urlencode(...$a),
    'encoding.rawurlencode' => fn (array $a) => rawurlencode(...$a),
    'encoding.urldecode' => fn (array $a) => urldecode(...$a),
    'encoding.rawurldecode' => fn (array $a) => rawurldecode(...$a),
    'encoding.http_build_query' => fn (array $a) => http_build_query(...$a),
    'encoding.base64_encode' => fn (array $a) => base64_encode(...$a),
    'encoding.base64_decode' => fn (array $a) => base64_decode(...$a),
    'encoding.bin2hex' => fn (array $a) => bin2hex(...$a),
    'encoding.hex2bin' => fn (array $a) => hex2bin(...$a),
    'encoding.htmlspecialchars' => fn (array $a) => htmlspecialchars(...$a),
    'encoding.htmlentities' => fn (array $a) => htmlentities(...$a),
    'encoding.htmlspecialchars_decode' => fn (array $a) => htmlspecialchars_decode(...$a),
    'encoding.html_entity_decode' => fn (array $a) => html_entity_decode(...$a),
    'encoding.addslashes' => fn (array $a) => addslashes(...$a),
    'encoding.stripslashes' => fn (array $a) => stripslashes(...$a),
    'encoding.quoted_printable_encode' => fn (array $a) => quoted_printable_encode(...$a),
    'encoding.quoted_printable_decode' => fn (array $a) => quoted_printable_decode(...$a),
];
