//! The pixel budget: an image whose decoded pixels would pass the `memory`
//! and `map` limits fails before its buffers are allocated. Its own test
//! binary, because resource limits are process-wide.

use utopia_image::{Image, Resource, set_resource_limit};

const PNG: &[u8] = include_bytes!("../../../../packages/image/tests/resources/resize/100x100.png");
const GIF: &[u8] = include_bytes!("../../../../packages/image/tests/resources/disk-a/kitten-3.gif");

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

    // Back to room for every frame: decodes again.
    set_resource_limit(Resource::Memory, frame * frames as i64);
    assert!(Image::new(GIF).is_ok());
}
