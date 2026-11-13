//! The pixel budget: an image whose decoded pixels would pass its canvas's
//! `memory` limit fails before its buffers are allocated.

use utopia_image::{Canvas, Error, Gravity, Image, Limits, OutputFormat, Transform};

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

/// Reads `data` under a budget of `memory` bytes and decodes it.
fn decoded(data: &[u8], memory: u64) -> utopia_image::Result<Vec<u8>> {
    let canvas = Canvas::decode(data, Limits { memory, ..Limits::default() })?;
    canvas.encode(OutputFormat::Png, None)
}

fn exhausted<T>(result: utopia_image::Result<T>) -> bool {
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
    let doubled = Transform { width: (w * 2) as u32, height: (h * 2) as u32, ..Default::default() };
    assert!(exhausted(canvas.apply(doubled).unwrap().encode(OutputFormat::Gif, None)));
    assert!(canvas.encode(OutputFormat::Gif, None).is_ok());
}

#[test]
fn a_half_turn_needs_no_room_for_a_second_copy() {
    // Decoding this 100x100 RGB PNG holds 30,000 bytes of its own pixels and the 40,000 of
    // the canvas made from them. 75,000 leaves no room for a second canvas.
    let canvas = Canvas::decode(PNG, Limits { memory: 75_000, ..Limits::default() }).unwrap();
    let turned = |rotation| canvas.apply(Transform { rotation, ..Default::default() }).unwrap();
    assert!(turned(180).encode(OutputFormat::Png, None).is_ok());
    // A quarter turn builds its replacement while the source is still held.
    assert!(exhausted(turned(90).encode(OutputFormat::Png, None)));
}

/// `data` cropped to `width` x `height` by `gravity` under a budget of `memory` bytes,
/// after a border of `border` pixels when there is one: the size it comes out at.
fn cropped(
    data: &[u8],
    memory: u64,
    border: u32,
    (width, height): (u32, u32),
    gravity: Gravity,
) -> utopia_image::Result<(i64, i64)> {
    let mut canvas = Canvas::decode(data, Limits { memory, ..Limits::default() })?;
    if border > 0 {
        canvas = canvas.apply(Transform { border: Some((border, "#ff0000".into())), ..Default::default() })?;
    }
    let bytes =
        canvas.apply(Transform { width, height, gravity, ..Default::default() })?.encode(OutputFormat::Gif, None)?;
    let image = Image::new(&bytes)?;
    Ok((image.width(), image.height()))
}

#[test]
fn a_cropped_animation_is_held_to_the_budget_as_a_whole() {
    // An animation whose 1x1 logical screen makes every frame cost four bytes
    // decodes within any budget, so what it costs is only visible at the crop,
    // which resamples every frame to the target and keeps it: 64 frames of
    // 300x300 is 23 MB of pixels, past a 4 MB budget.
    let animation = animation_gif(64, 1, 1);
    assert_eq!(Image::new(&animation).unwrap().frames(), 64, "every frame of the file is decoded");
    assert!(exhausted(cropped(&animation, 4 * 1024 * 1024, 0, (300, 300), Gravity::Center)));
    assert!(exhausted(cropped(&animation, 4 * 1024 * 1024, 0, (300, 300), Gravity::TopLeft)));

    // Room for every cropped frame: the same crop goes through.
    let room = 64 * 300 * 300 * 4 + 1024 * 1024;
    assert_eq!(cropped(&animation, room, 0, (300, 300), Gravity::Center).unwrap(), (300, 300));

    // The same animation cropped to the 2000x2000 an avatar may ask for is a
    // gigabyte of frames: refused under the default budget too.
    assert!(exhausted(cropped(&animation, Limits::default().memory, 0, (2000, 2000), Gravity::Center)));

    // The frame being cropped holds the canvas covering the target as well:
    // 20 frames of a 400x4 animation cropped to 100x100 are 800 KB, but the
    // 10000x100 canvas each is scaled to first is another 4 MB.
    let wide = animation_gif(20, 400, 4);
    assert!(exhausted(cropped(&wide, 4_500_000, 0, (100, 100), Gravity::TopLeft)));
    // Room for that canvas on top of the frames: the crop goes through.
    assert_eq!(cropped(&wide, 5_000_000, 0, (100, 100), Gravity::TopLeft).unwrap(), (100, 100));

    // A border grows the frame it is applied to and nothing else, and a
    // squarer frame covers a 200x50 target with a taller canvas: the cover
    // canvas comes from the largest frame, not from the image's size. The
    // budget the crop needs, to the byte: nine 400x100 frames and the
    // bordered 1200x900 one, plus the 200x150 that frame is resampled to and
    // the 200x50 cropped out of it.
    let needed = (9 * 400 * 100 + 1200 * 900 + 200 * 150 + 200 * 50) * 4;
    let bordered = animation_gif(10, 400, 100);
    assert!(exhausted(cropped(&bordered, needed - 1, 400, (200, 50), Gravity::Center)));
    assert_eq!(cropped(&bordered, needed, 400, (200, 50), Gravity::Center).unwrap(), (200, 50));
}
