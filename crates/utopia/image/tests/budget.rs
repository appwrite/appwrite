//! The pixel budget: an image whose pixels would pass the `memory` and `map`
//! limits fails before its buffers are allocated, decoding it or transforming
//! every frame of it. Its own test binary with a single test, because resource
//! limits are process-wide.

use utopia_image::{Gravity, Image, Resource, set_resource_limit};

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

/// A GIF of `frames` 1x1 frames on a `width` x `height` logical screen: a
/// handful of bytes a frame in the file, and a screen-sized canvas a frame
/// decoded, so a 1x1 screen costs four bytes a frame however many it carries.
fn animation_gif(frames: usize, width: u16, height: u16) -> Vec<u8> {
    let mut gif = b"GIF89a".to_vec();
    gif.extend_from_slice(&width.to_le_bytes());
    gif.extend_from_slice(&height.to_le_bytes());
    gif.extend_from_slice(&[0x80, 0, 0]); // a 2-colour global table
    gif.extend_from_slice(&[0, 0, 0, 0xFF, 0xFF, 0xFF]);
    for _ in 0..frames {
        gif.extend_from_slice(&[0x2C, 0, 0, 0, 0, 1, 0, 1, 0, 0]); // frame: 1x1 at the origin
        gif.extend_from_slice(&[2, 2, 0x4C, 0x01, 0]); // LZW data, block terminator
    }
    gif.push(0x3B); // trailer
    gif
}

fn exhausted<T>(result: utopia_image::Result<T>) -> bool {
    matches!(result, Err(utopia_image::Error::LimitExceeded))
}

#[test]
fn pixels_past_the_budget_fail_before_they_are_allocated() {
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

    // An animation whose 1x1 logical screen makes every frame cost four bytes
    // decodes within any budget, so what it costs is only visible at the crop,
    // which resamples every frame to the target and keeps it: 64 frames of
    // 300x300 is 23 MB of pixels, past a 4 MB budget.
    let animation = animation_gif(64, 1, 1);
    set_resource_limit(Resource::Memory, 4 * 1024 * 1024);
    set_resource_limit(Resource::Map, 0);
    let mut image = Image::new(&animation).expect("a 1x1 animation fits any budget");
    assert_eq!(image.frames(), 64, "every frame of the file is decoded");
    assert!(exhausted(image.crop(300, 300, Gravity::Center, None)));
    assert!(exhausted(image.crop(300, 300, Gravity::TopLeft, None)));

    // Room for every cropped frame: the same crop goes through.
    set_resource_limit(Resource::Memory, 64 * 300 * 300 * 4 + 1024 * 1024);
    let mut image = Image::new(&animation).expect("a 1x1 animation fits any budget");
    image.crop(300, 300, Gravity::Center, None).expect("the cropped animation fits the budget");
    assert_eq!((image.width(), image.height()), (300, 300));

    // The same animation cropped to the 2000x2000 an avatar may ask for is a
    // gigabyte of frames: refused under the default budget too.
    set_resource_limit(Resource::Memory, 268_435_456);
    set_resource_limit(Resource::Map, 536_870_912);
    let mut image = Image::new(&animation).expect("a 1x1 animation fits any budget");
    assert!(exhausted(image.crop(2000, 2000, Gravity::Center, None)));

    // The frame being cropped holds the canvas covering the target as well:
    // 20 frames of a 400x4 animation cropped to 100x100 are 800 KB, but the
    // 10000x100 canvas each is scaled to first is another 4 MB.
    let wide = animation_gif(20, 400, 4);
    set_resource_limit(Resource::Memory, 4_500_000);
    set_resource_limit(Resource::Map, 0);
    let mut image = Image::new(&wide).expect("a 400x4 animation fits the budget");
    assert!(exhausted(image.crop(100, 100, Gravity::TopLeft, None)));

    // Room for that canvas on top of the frames: the crop goes through.
    set_resource_limit(Resource::Memory, 5_000_000);
    let mut image = Image::new(&wide).expect("a 400x4 animation fits the budget");
    image.crop(100, 100, Gravity::TopLeft, None).expect("the cover canvas fits the budget");
    assert_eq!((image.width(), image.height()), (100, 100));

    // A border grows the frame it is applied to and nothing else, and a
    // squarer frame covers a 200x50 target with a taller canvas: the cover
    // canvas comes from the largest frame, not from the image's size. The
    // budget the crop needs, to the byte: nine 400x100 frames and the
    // bordered 1200x900 one, plus the 200x150 that frame is resampled to and
    // the 200x50 cropped out of it.
    let needed = (9 * 400 * 100 + 1200 * 900 + 200 * 150 + 200 * 50) * 4;
    let bordered = |limit: i64| {
        set_resource_limit(Resource::Memory, limit);
        set_resource_limit(Resource::Map, 0);
        let mut image = Image::new(&animation_gif(10, 400, 100)).expect("a 400x100 animation fits the budget");
        image.set_border(400, "#ff0000").expect("the bordered frame fits the budget");
        image
    };
    assert!(exhausted(bordered(needed - 1).crop(200, 50, Gravity::Center, None)));
    let mut image = bordered(needed);
    image.crop(200, 50, Gravity::Center, None).expect("the crop fits the budget exactly");
    assert_eq!((image.width(), image.height()), (200, 50));
}
