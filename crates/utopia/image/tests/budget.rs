//! The pixel budget: an image whose decoded pixels would pass the `memory`
//! and `map` limits fails before its buffers are allocated. Its own test
//! binary with a single test, because resource limits are process-wide.

use utopia_image::{Image, Resource, set_resource_limit};

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

fn exhausted(result: utopia_image::Result<Image>) -> bool {
    result.err().is_some_and(|e| e.to_string().contains("cache resources exhausted"))
}

#[test]
fn images_past_the_budget_fail_before_decoding() {
    // Within the default budget: decodes.
    assert!(Image::new(PNG).is_ok());
    let gif = Image::new(GIF).expect("the animation fits the default budget");
    let (w, h, frames) = (gif.width() as u64, gif.height() as u64, gif.frames() as u64);
    assert!(frames > 1, "the fixture is an animation");

    // A budget smaller than one 100x100 canvas (40,000 bytes): refused.
    set_resource_limit(Resource::Memory, 20_000);
    set_resource_limit(Resource::Map, 0);
    assert!(exhausted(Image::new(PNG)));

    // Room for one frame of the animation but not all of them: refused while
    // reading frames, since each keeps a whole canvas.
    let frame = (w * h * 4) as i64;
    set_resource_limit(Resource::Memory, frame * (frames as i64 - 1));
    assert!(exhausted(Image::new(GIF)));

    // Room for every frame plus what decoding holds alongside them (the
    // working canvas, the copy being pushed, a saved canvas and one frame's
    // pixels): decodes again.
    set_resource_limit(Resource::Memory, frame * (frames as i64 + 4));
    assert!(Image::new(GIF).is_ok());

    // A frame declaring more than its 1x1 screen: refused from its
    // descriptor, before its pixels are decoded, even with the default budget.
    set_resource_limit(Resource::Memory, 268_435_456);
    set_resource_limit(Resource::Map, 536_870_912);
    assert!(exhausted(Image::new(&oversized_frame_gif())));
}
