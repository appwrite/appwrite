vcl 4.1;

backend exc1 {
    .host = "exc1";
    .port = "80";
    .first_byte_timeout = 10s;
}

sub vcl_recv {
    set req.backend_hint = exc1;
}