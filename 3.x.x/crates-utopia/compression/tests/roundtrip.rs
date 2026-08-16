use utopia_compression::{Compression, CompressionError, BROTLI, DEFLATE, GZIP, ZSTD};

const SAMPLE: &[u8] = b"the quick brown fox jumps over the lazy dog. ";

fn roundtrip(algorithm: Compression, payload: &[u8]) {
    assert!(algorithm.is_supported());
    let compressed = algorithm.compress(payload).expect("compress");
    let decompressed = algorithm.decompress(&compressed).expect("decompress");
    assert_eq!(decompressed, payload);
}

#[test]
fn gzip_roundtrip() {
    let payload = SAMPLE.repeat(32);
    roundtrip(Compression::Gzip, &payload);
}

#[test]
fn deflate_roundtrip() {
    let payload = SAMPLE.repeat(32);
    roundtrip(Compression::Deflate, &payload);
}

#[test]
fn brotli_roundtrip() {
    let payload = SAMPLE.repeat(32);
    roundtrip(Compression::brotli(), &payload);
}

#[test]
fn zstd_roundtrip() {
    let payload = SAMPLE.repeat(32);
    roundtrip(Compression::zstd(), &payload);
}

#[test]
fn none_roundtrip() {
    let payload = SAMPLE.repeat(4);
    roundtrip(Compression::None, &payload);
}

#[test]
fn from_name_aliases() {
    assert_eq!(Compression::from_name("gzip"), Some(Compression::Gzip));
    assert_eq!(Compression::from_name("br"), Some(Compression::brotli()));
    assert_eq!(
        Compression::from_name("brotli"),
        Some(Compression::brotli())
    );
    assert_eq!(Compression::from_name("unknown"), None);
    assert_eq!(Compression::from_name("none"), None);
    assert_eq!(Compression::from_name("identity"), None);
}

#[test]
fn content_encoding_values() {
    assert_eq!(Compression::Gzip.content_encoding(), GZIP);
    assert_eq!(Compression::Deflate.content_encoding(), DEFLATE);
    assert_eq!(Compression::brotli().content_encoding(), "br");
    assert_eq!(Compression::zstd().content_encoding(), ZSTD);
}

#[test]
fn brotli_level_setters() {
    let mut algo = Compression::brotli();
    algo.set_brotli_level(5).unwrap();
    assert_eq!(algo.brotli_level(), Some(5));

    let err = algo.set_brotli_level(99).unwrap_err();
    assert!(matches!(err, CompressionError::InvalidLevel { .. }));
}

#[test]
fn zstd_level_setters() {
    let mut algo = Compression::zstd();
    algo.set_zstd_level(10).unwrap();
    assert_eq!(algo.zstd_level(), Some(10));

    let err = algo.set_zstd_level(0).unwrap_err();
    assert!(matches!(err, CompressionError::InvalidLevel { .. }));
}

#[test]
fn accept_encoding_basic() {
    assert_eq!(
        Compression::from_accept_encoding("br"),
        Some(Compression::brotli())
    );
    assert_eq!(
        Compression::from_accept_encoding("br;q=0.5"),
        Some(Compression::brotli())
    );
    assert_eq!(
        Compression::from_accept_encoding("br;q=0.5, gzip;q=0.5"),
        Some(Compression::brotli())
    );
}

#[test]
fn accept_encoding_supported_list() {
    assert_eq!(
        Compression::from_accept_encoding_with_supported("gzip;q=0.5, br;q=0.5", Some(&[GZIP])),
        Some(Compression::Gzip)
    );
    assert_eq!(
        Compression::from_accept_encoding_with_supported(
            "gzip;q=0.5, br;q=0.5",
            Some(&[BROTLI, GZIP])
        ),
        Some(Compression::Gzip)
    );
    assert_eq!(
        Compression::from_accept_encoding_with_supported("gzip;q=0.5, br;q=0.5", Some(&[BROTLI])),
        Some(Compression::brotli())
    );
    assert_eq!(
        Compression::from_accept_encoding_with_supported("gzip;q=0.5, br;q=0.5", Some(&["snappy"])),
        None
    );
}

#[test]
fn accept_encoding_quality_ordering() {
    assert_eq!(
        Compression::from_accept_encoding_with_supported(
            "gzip;q=0.4, br;q=0.9",
            Some(&[GZIP, BROTLI])
        ),
        Some(Compression::brotli())
    );
}

#[test]
fn accept_encoding_invalid() {
    assert_eq!(Compression::from_accept_encoding("adfkljasdjkf"), None);
    assert_eq!(
        Compression::from_accept_encoding_with_supported("adfkljasdjkf", Some(&[BROTLI])),
        None
    );
    assert_eq!(Compression::from_accept_encoding(""), None);
    assert_eq!(Compression::from_accept_encoding("0"), None);
    assert_eq!(Compression::from_accept_encoding("identity"), None);
}
