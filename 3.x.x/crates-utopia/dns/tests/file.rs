//! Port of `tests/unit/DNS/Zone/FileTest.php`.

mod common;

use common::{find_record, import, import_origin, rec, resource, soa};
use utopia_dns::error::Error;
use utopia_dns::message::Record;
use utopia_dns::zone::File;
use utopia_dns::Zone;

const DEFAULT_SOA: &str =
    "@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800";

fn zone_with(body: &str) -> String {
    format!("$ORIGIN example.com.\n{DEFAULT_SOA}\n{body}")
}

#[test]
fn example_com_zone_file() {
    let zone = import(&resource("zone-valid-example.com.txt"));
    assert_eq!(zone.name, "example.com");
    assert!(!zone.records.is_empty());
}

#[test]
fn redhat_zone_file() {
    let zone = import(&resource("zone-valid-redhat.txt"));
    assert_eq!(zone.name, "example.com");
    assert!(!zone.records.is_empty());
}

#[test]
fn oracle1_zone_file() {
    let zone = import_origin(&resource("zone-valid-oracle1.txt"), "example.com");
    assert_eq!(zone.name, "example.com");
    assert!(!zone.records.is_empty());
}

#[test]
fn oracle2_zone_file() {
    let zone = import(&resource("zone-valid-oracle2.txt"));
    assert_eq!(zone.name, "example.com");
    assert!(!zone.records.is_empty());
}

#[test]
fn localhost_zone_file() {
    let zone = import(&resource("zone-valid-localhost.txt"));
    assert_eq!(zone.name, "localhost");
    assert!(!zone.records.is_empty());
}

#[test]
fn import_valid_zone_with_directives() {
    let contents = format!(
        "$ORIGIN example.com.\n$TTL 1800\n{DEFAULT_SOA}\n\nwww IN A 192.168.1.10\nmail 300 IN MX 10 mail\n_sip._tcp 600 IN SRV 5 10 5060 sip\n"
    );
    let zone = import(&contents);
    assert_eq!(zone.soa.ttl, 1800);
    assert_eq!(zone.records.len(), 3);
    let www = &zone.records[0];
    assert_eq!(www.name, "www.example.com");
    assert_eq!(www.ttl, 1800);
    assert_eq!(www.type_code, Record::TYPE_A);
    assert_eq!(www.rdata, "192.168.1.10");
    let mx = find_record(&zone.records, Record::TYPE_MX).unwrap();
    assert_eq!(mx.ttl, 300);
    assert_eq!(mx.rdata, "mail.example.com");
    assert_eq!(mx.priority, Some(10));
    let srv = find_record(&zone.records, Record::TYPE_SRV).unwrap();
    assert_eq!(srv.name, "_sip._tcp.example.com");
    assert_eq!(srv.priority, Some(5));
    assert_eq!(srv.weight, Some(10));
    assert_eq!(srv.port, Some(5060));
    assert_eq!(srv.rdata, "sip.example.com");
}

#[test]
fn import_fails_with_unsupported_directive() {
    let err = File::import("$ORIGIN example.com.\n$INCLUDE other.zone\n", None, 3600).unwrap_err();
    assert!(matches!(err, Error::Import { .. }));
    assert!(err
        .to_string()
        .contains("$INCLUDE directive is not supported"));
}

#[test]
fn import_fails_with_unknown_record_type() {
    let err = File::import(&zone_with("www 300 IN BADTYPE data\n"), None, 3600).unwrap_err();
    assert!(err
        .to_string()
        .contains("Invalid record type 'BADTYPE' (line 3)."));
}

#[test]
fn import_fails_when_mx_priority_missing() {
    let err = File::import(
        &zone_with("mail 3600 IN MX mail.example.com.\n"),
        None,
        3600,
    )
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("MX requires numeric priority and exchange"));
}

#[test]
fn import_fails_when_srv_fields_missing() {
    let err = File::import(&zone_with("_sip._tcp 600 IN SRV 5 10 5060\n"), None, 3600).unwrap_err();
    assert!(err
        .to_string()
        .contains("SRV requires priority, weight, port, target"));
}

#[test]
fn import_handles_blank_lines_and_comments() {
    let contents =
        format!("$ORIGIN example.com.\n{DEFAULT_SOA}\n\n; comment line\n\n@ 3600 IN A 127.0.0.1\n");
    let zone = import(&contents);
    assert_eq!(zone.records.len(), 1);
    assert_eq!(zone.records[0].name, "example.com");
}

#[test]
fn import_allows_zero_ttl() {
    let zone = import(&zone_with("@ 0 IN A 127.0.0.1\n"));
    assert_eq!(zone.records[0].ttl, 0);
}

#[test]
fn import_uses_default_origin_when_directive_missing() {
    let contents = "@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800\nwww 600 IN A 192.0.2.10\n";
    let zone = import_origin(contents, "example.com");
    assert_eq!(zone.name, "example.com");
    assert_eq!(zone.records[0].name, "www.example.com");
}

#[test]
fn import_allows_email_address_soa_rname_to_encode() {
    let contents = "@ IN SOA ns1.example.com. first.last@example.com. 2025011801 7200 3600 1209600 1800\nwww 600 IN A 192.0.2.10\n";
    let zone = import_origin(contents, "example.com");
    let encoded = zone.soa.encode().unwrap();
    let needle = b"\x0Afirst.last\x07example\x03com\x00";
    assert!(encoded.windows(needle.len()).any(|w| w == needle));
}

#[test]
fn import_fails_when_soa_data_missing() {
    let err = File::import(
        "@ IN SOA ns1.example.com. admin.example.com.",
        Some("example.com"),
        3600,
    )
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("SOA requires MNAME, RNAME, SERIAL, REFRESH, RETRY, EXPIRE, MINIMUM"));
}

#[test]
fn import_with_relative_names_expands_to_zone() {
    let contents =
        zone_with("www     IN  A   192.0.2.10\nmail    IN  MX  10 mail\nalias   IN  CNAME   www\n");
    let zone = import(&contents);
    assert_eq!(zone.soa.name, "example.com");
    let mx = find_record(&zone.records, Record::TYPE_MX).unwrap();
    assert_eq!(mx.rdata, "mail.example.com");
    let cname = find_record(&zone.records, Record::TYPE_CNAME).unwrap();
    assert_eq!(cname.rdata, "www.example.com");
}

#[test]
fn import_handles_class_before_ttl() {
    let zone = import(&zone_with("@ IN 3600 A 192.0.2.10\n"));
    let record = &zone.records[0];
    assert_eq!(record.class, Record::CLASS_IN);
    assert_eq!(record.ttl, 3600);
}

#[test]
fn import_defaults_class_to_in() {
    let zone = import(&zone_with("@ 600 A 192.0.2.11\n"));
    assert_eq!(zone.records[0].class, Record::CLASS_IN);
}

#[test]
fn import_collapses_parenthesized_txt_records() {
    let zone = import(&zone_with(
        "multiline 600 IN TXT (\n    \"foo\"\n    \"bar\"\n)\n",
    ));
    let txt = find_record(&zone.records, Record::TYPE_TXT).unwrap();
    assert_eq!(txt.rdata, "foobar");
}

#[test]
fn import_decodes_decimal_escapes_in_txt() {
    let zone = import(&zone_with("escaped 600 IN TXT \"foo\\010bar\"\n"));
    let txt = find_record(&zone.records, Record::TYPE_TXT).unwrap();
    assert_eq!(txt.rdata, "foo\nbar");
}

#[test]
fn import_ignores_unknown_directive() {
    let contents =
        format!("$ORIGIN example.com.\n{DEFAULT_SOA}\n$FOO bar\nwww IN A 192.168.1.10\n");
    let zone = import(&contents);
    assert_eq!(zone.records.len(), 1);
    assert_eq!(zone.records[0].name, "www.example.com");
}

#[test]
fn import_txt_with_special_chars() {
    let zone = import(&zone_with(
        "@ 3600 IN TXT \"v=DMARC1; p=none; rua=mailto:jon@snow.got; ruf=mailto:jon@snow.got; fo=1;\"\n",
    ));
    let record = find_record(&zone.records, Record::TYPE_TXT).unwrap();
    assert_eq!(
        record.rdata,
        "v=DMARC1; p=none; rua=mailto:jon@snow.got; ruf=mailto:jon@snow.got; fo=1;"
    );
}

#[test]
fn export_txt_with_special_chars() {
    let zone = Zone::new(
        "example.com",
        vec![Record::new("example.com", Record::TYPE_TXT)
            .class(Record::CLASS_IN)
            .ttl(3600)
            .rdata("v=DMARC1; text=\"quoted\"; backslash=\\")],
        Record::new("example.com", Record::TYPE_SOA)
            .class(Record::CLASS_IN)
            .ttl(3600)
            .rdata("ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800"),
    )
    .unwrap();
    let expected = "$ORIGIN example.com.\n$TTL 3600\n\n@\t3600\tIN\tSOA\tns1 admin (\n\t\t\t\t2025011801\t; serial\n\t\t\t\t7200\t; refresh\n\t\t\t\t3600\t; retry\n\t\t\t\t1209600\t; expire\n\t\t\t\t1800 )\t; minimum\n\n@\t3600\tIN\tTXT\t\"v=DMARC1; text=\\\"quoted\\\"; backslash=\\\\\"\n";
    let exported = File::export(&zone, false);
    assert_eq!(exported, expected);
    let round_trip = import(&exported);
    let txt = find_record(&round_trip.records, Record::TYPE_TXT).unwrap();
    assert_eq!(txt.rdata, zone.records[0].rdata);
}

#[test]
fn import_export_round_trip() {
    let contents = "$ORIGIN example.com.\n$TTL 1200\n@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800\nwww IN A 192.168.1.10\nmail 600 IN MX 10 mail\n";
    let zone = import(contents);
    assert_eq!(zone.records.len(), 2);
    let exported = File::export(&zone, false);
    let round_trip = import(&exported);
    assert_eq!(zone.name, round_trip.name);
    assert_eq!(zone.soa.rdata, round_trip.soa.rdata);
    assert_eq!(zone.records.len(), round_trip.records.len());
    assert_eq!(zone.records[1].rdata, round_trip.records[1].rdata);
}

#[test]
fn export_basic_zone() {
    let zone = Zone::new(
        "example.com",
        vec![
            Record::new("example.com", Record::TYPE_NS)
                .class(Record::CLASS_IN)
                .ttl(3600)
                .rdata("ns1.example.com"),
            Record::new("www.example.com", Record::TYPE_A)
                .class(Record::CLASS_IN)
                .ttl(1800)
                .rdata("192.168.1.10"),
            Record::new("mail.example.com", Record::TYPE_MX)
                .class(Record::CLASS_IN)
                .ttl(300)
                .rdata("mail.example.com")
                .priority(10),
        ],
        Record::new("example.com", Record::TYPE_SOA)
            .class(Record::CLASS_IN)
            .ttl(1800)
            .rdata("ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800"),
    )
    .unwrap();
    let expected = "$ORIGIN example.com.\n$TTL 1800\n\n@\t1800\tIN\tSOA\tns1 admin (\n\t\t\t\t2025011801\t; serial\n\t\t\t\t7200\t; refresh\n\t\t\t\t3600\t; retry\n\t\t\t\t1209600\t; expire\n\t\t\t\t1800 )\t; minimum\n\n@\t3600\tIN\tNS\tns1\n\nwww\t1800\tIN\tA\t192.168.1.10\n\nmail\t300\tIN\tMX\t10 mail\n";
    let output = File::export(&zone, false);
    assert_eq!(output, expected);
    let round_trip = import(&output);
    assert_eq!(round_trip.records.len(), 3);
    assert_eq!(round_trip.records[2].name, "mail.example.com");
}

#[test]
fn import_supports_ptr_records() {
    let zone = import(&zone_with("1 3600 IN PTR host.example.com.\n"));
    let ptr = find_record(&zone.records, Record::TYPE_PTR).unwrap();
    assert_eq!(ptr.name, "1.example.com");
    assert_eq!(ptr.rdata, "host.example.com");
}

#[test]
fn import_supports_multiline_soa() {
    let contents = "$ORIGIN example.com.\n@ 3600 IN SOA (\n    ns1.example.com.\n    admin.example.com.\n    2025011801\n    7200\n    3600\n    1209600\n    1800\n)\nwww 1800 IN A 192.0.2.10\n";
    let zone = import(contents);
    assert_eq!(
        zone.soa.rdata,
        "ns1.example.com admin.example.com 2025011801 7200 3600 1209600 1800"
    );
    assert_eq!(zone.records[0].name, "www.example.com");
}

#[test]
fn import_handles_multiple_origins() {
    let contents = format!(
        "$ORIGIN example.com.\n{DEFAULT_SOA}\nwww IN A 192.0.2.10\n$ORIGIN sub.example.com.\n@ 600 IN AAAA 2001:db8::1\n$ORIGIN example.com.\napi IN CNAME www\n"
    );
    let zone = import(&contents);
    assert_eq!(zone.records[0].name, "www.example.com");
    assert_eq!(zone.records[1].name, "sub.example.com");
    assert_eq!(zone.records[2].name, "api.example.com");
    assert_eq!(zone.records[2].rdata, "www.example.com");
}

#[test]
fn import_allows_owner_omission_with_previous_owner() {
    let contents = zone_with("www IN A 192.0.2.10\n    IN AAAA 2001:db8::1\n");
    let zone = import(&contents);
    assert_eq!(zone.records[0].name, "www.example.com");
    assert_eq!(zone.records[1].name, "www.example.com");
    assert_eq!(zone.records[1].type_code, Record::TYPE_AAAA);
}

#[test]
fn import_txt_with_escaped_semicolon() {
    let zone = import(&zone_with("@ 3600 IN TXT \"foo\\;bar\"\n"));
    let record = find_record(&zone.records, Record::TYPE_TXT).unwrap();
    assert_eq!(record.rdata, "foo;bar");
}

#[test]
fn import_txt_with_semicolon_in_quotes() {
    let zone = import(&zone_with("@ 3600 IN TXT \"not a comment; still text\"\n"));
    let record = find_record(&zone.records, Record::TYPE_TXT).unwrap();
    assert_eq!(record.rdata, "not a comment; still text");
}

#[test]
fn import_export_round_trip_for_aaaa() {
    let zone = import(&zone_with("www 600 IN AAAA 2001:db8::1\n"));
    assert_eq!(zone.records[0].type_code, Record::TYPE_AAAA);
    let exported = File::export(&zone, false);
    let round_trip = import(&exported);
    assert_eq!(zone.records[0].rdata, round_trip.records[0].rdata);
}

#[test]
fn can_export_zone_with_template_records() {
    let zone = Zone::new(
        "example.com",
        vec![
            rec("api.example.com", Record::TYPE_A, 120, "a.a.a.a"),
            rec("api.example.com", Record::TYPE_A, 120, "b:b::b:b:b"),
        ],
        soa(
            "example.com",
            "ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300",
            3600,
        ),
    )
    .unwrap();
    let contents = File::export(&zone, true);
    assert!(contents.contains("a.a.a.a"));
    assert!(contents.contains("b:b::b:b:b"));
}

#[test]
fn can_import_zone_with_template_records() {
    let zone = import(&zone_with("www 600 IN AAAA b:b::b:b:b\n"));
    assert_eq!(zone.records.len(), 1);
    assert_eq!(zone.records[0].name, "www.example.com");
    assert_eq!(zone.records[0].rdata, "b:b::b:b:b");
}

#[test]
fn import_export_round_trip_for_caa() {
    let zone = import(&zone_with("@ 3600 IN CAA 0 issue \"letsencrypt.org\"\n"));
    let record = find_record(&zone.records, Record::TYPE_CAA).unwrap();
    assert_eq!(record.rdata, "0 issue \"letsencrypt.org\"");
    let exported = File::export(&zone, false);
    let round_trip = import(&exported);
    let caa = find_record(&round_trip.records, Record::TYPE_CAA).unwrap();
    assert_eq!(record.rdata, caa.rdata);
}

#[test]
fn import_caa_missing_quoted_value_fails() {
    let err = File::import(
        &zone_with("@ 3600 IN CAA 0 issue letsencrypt.org\n"),
        None,
        3600,
    )
    .unwrap_err();
    assert!(err.to_string().contains("CAA value must be quoted"));
}

#[test]
fn import_ptr_with_reverse_origin() {
    let contents = "$ORIGIN 2.0.192.in-addr.arpa.\n@ 3600 IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800\n1 3600 IN PTR host.example.com.\n";
    let zone = import(contents);
    let ptr = find_record(&zone.records, Record::TYPE_PTR).unwrap();
    assert_eq!(ptr.name, "1.2.0.192.in-addr.arpa");
}

#[test]
fn import_fails_with_duplicate_soa() {
    let contents = "$ORIGIN example.com.\n@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800\n@ IN SOA ns2.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800\n";
    let err = File::import(contents, None, 3600).unwrap_err();
    assert!(err.to_string().contains("Multiple SOA records found"));
}

#[test]
fn import_rejects_ttl_with_suffix() {
    let err = File::import(&zone_with("www 1h IN A 192.0.2.10\n"), None, 3600).unwrap_err();
    assert!(err
        .to_string()
        .contains("Invalid record type '1H' (line 3)."));
}

#[test]
fn import_supports_alternative_classes() {
    let zone = import(&zone_with("www CS A 192.0.2.10\n"));
    let record = find_record(&zone.records, Record::TYPE_A).unwrap();
    assert_eq!(record.class, Record::CLASS_CS);
}

#[test]
fn import_txt_with_embedded_quote_and_backslash() {
    let zone = import(&zone_with(
        "@ 3600 IN TXT \"a \\\"quote\\\" and a \\\\ backslash\"\n",
    ));
    let record = find_record(&zone.records, Record::TYPE_TXT).unwrap();
    assert_eq!(record.rdata, "a \"quote\" and a \\ backslash");
}

#[test]
fn import_txt_three_digit_escape_consumes_only_three_digits() {
    let zone = import(&zone_with("@ 3600 IN TXT \"foo\\0100bar\"\n"));
    let record = find_record(&zone.records, Record::TYPE_TXT).unwrap();
    assert_eq!(record.rdata, "foo\n0bar");
}

#[test]
fn import_fails_when_soa_has_too_few_fields() {
    let err = File::import(
        "@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600",
        Some("example.com"),
        3600,
    )
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("SOA requires MNAME, RNAME, SERIAL, REFRESH, RETRY, EXPIRE, MINIMUM"));
}

#[test]
fn import_fails_without_soa() {
    let err = File::import("www IN A 192.168.1.10\n", Some("example.com"), 3600).unwrap_err();
    assert!(err.to_string().contains("No SOA record found in zone file"));
}

#[test]
fn import_fails_when_owner_omitted_without_context() {
    let contents = "$ORIGIN example.com.\n    IN A 127.0.0.1\n@ IN SOA ns1.example.com. admin.example.com. 2025011801 7200 3600 1209600 1800\n";
    let err = File::import(contents, None, 3600).unwrap_err();
    assert!(err
        .to_string()
        .contains("Owner omitted but no previous owner available"));
}
