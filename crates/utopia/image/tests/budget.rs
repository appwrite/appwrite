//! The pixel budget: an image whose decoded pixels would pass its canvas's
//! `memory` limit fails before its buffers are allocated.

use utopia_image::{Canvas, Error, Image, Limits, OutputFormat};

const PNG: &[u8] = include_bytes!("../../../../packages/image/tests/resources/resize/100x100.png");
const GIF: &[u8] = include_bytes!("../../../../packages/image/tests/resources/disk-a/kitten-3.gif");

/// A GIF whose logical screen is 1x1 but whose only frame declares
/// 65535x65535 (17 GB as RGBA): it must fail before the frame is decoded.
fn oversized_frame_gif() -> Vec<u8> {
    let mut gif = b"GIF89a".to_vec();
    gif.extend_from_slice(&[1, 0, 1, 0, 0x80, 0, 0]); // 1x1 screen, a 2-colour global table
    gif.extend_from_slice(&[0, 0, 0, 0xFF, 0xFF, 0xFF]);
    gif.extend_from_slice(&[0x2C, 0, 0, 0, 0, 0xFF, 0xFF, 0xFF, 0xFF, 0]); // frame: 65535x65535
    gif.extend_from_slice(&[2, 2, 0x4C, 0x01, 0, 0x3B]); // LZW data, terminator, trailer
    gif
}

/// Reads `data` under a budget of `memory` bytes and decodes it.
fn decoded(data: &[u8], memory: u64) -> utopia_image::Result<Vec<u8>> {
    let canvas = Canvas::decode(data, Limits { memory, ..Limits::default() })?;
    canvas.encode(OutputFormat::Png, None)
}

fn exhausted(result: utopia_image::Result<Vec<u8>>) -> bool {
    matches!(result, Err(Error::LimitExceeded))
}

#[test]
fn images_past_the_budget_fail_before_decoding() {
    let budget = Limits::default().memory;
    // Within the default budget: decodes.
    assert!(decoded(PNG, budget).is_ok());
    let gif = Image::new(GIF).expect("the animation fits the default budget");
    let (w, h, frames) = (gif.width() as u64, gif.height() as u64, gif.frames() as u64);
    assert!(frames > 1, "the fixture is an animation");

    // A budget smaller than one 100x100 canvas (40,000 bytes): refused from the header.
    assert!(matches!(Canvas::decode(PNG, Limits { memory: 20_000, ..Limits::default() }), Err(Error::LimitExceeded)));

    // Room for one frame of the animation but not all of them: the header is
    // accepted, and decoding is refused while reading frames, since each keeps
    // a whole canvas.
    let frame = w * h * 4;
    assert!(Canvas::decode(GIF, Limits { memory: frame * (frames - 1), ..Limits::default() }).is_ok());
    assert!(exhausted(decoded(GIF, frame * (frames - 1))));

    // Room for every frame plus what decoding holds alongside them (the
    // working canvas, the copy being pushed, a saved canvas and one frame's
    // pixels): decodes again.
    assert!(decoded(GIF, frame * (frames + 4)).is_ok());

    // A frame declaring more than its 1x1 screen: refused from its
    // descriptor, before its pixels are decoded, even with the default budget.
    assert!(exhausted(decoded(&oversized_frame_gif(), budget)));
}

#[test]
fn a_canvas_keeps_its_own_limits() {
    let narrow = Limits { width: 50, ..Limits::default() };
    assert!(matches!(Canvas::decode(PNG, narrow), Err(Error::LimitExceeded)));
    // Another canvas on the same thread is unaffected.
    assert!(Canvas::decode(PNG, Limits::default()).is_ok());
    assert!(matches!(
        Canvas::blank(10, 60, "#fff", Limits { height: 50, ..Limits::default() }),
        Err(Error::LimitExceeded)
    ));
}

#[test]
fn a_resized_animation_is_held_to_the_budget_as_a_whole() {
    let gif = Image::new(GIF).unwrap();
    let (w, h, frames) = (gif.width() as u64, gif.height() as u64, gif.frames() as u64);
    // Room to decode it, and for one frame at twice the size, but not for every frame at that size.
    let memory = w * h * 4 * (frames + 4);
    let canvas = Canvas::decode(GIF, Limits { memory, ..Limits::default() }).unwrap();
    let doubled = utopia_image::Transform { width: (w * 2) as u32, height: (h * 2) as u32, ..Default::default() };
    assert!(exhausted(canvas.apply(doubled).unwrap().encode(OutputFormat::Gif, None)));
    assert!(canvas.encode(OutputFormat::Gif, None).is_ok());
}
