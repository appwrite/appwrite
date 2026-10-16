//! Microbenchmarks for utopia-image transforms.
//!
//! Crop/rotate/opacity/border time in-memory transforms (decode once, clone per
//! iter) - same model as `benchmarks/image/bench.php`. Pipeline metrics include
//! decode + transform + encode.

use std::path::PathBuf;
use std::time::Instant;

use utopia_image::{Image, GRAVITY_CENTER, GRAVITY_TOP_LEFT};

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    for _ in 0..iters.min(4) {
        f();
    }
    let start = Instant::now();
    for _ in 0..iters {
        f();
    }
    let elapsed = start.elapsed();
    println!(
        "{name}: {:.1} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64().max(1e-12)
    );
}

fn fixture(name: &str) -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("tests/resources/disk-a")
        .join(name)
}

fn main() {
    let jpeg = std::fs::read(fixture("kitten-1.jpg")).expect("kitten jpeg");
    let gif = std::fs::read(fixture("kitten-3.gif")).expect("kitten gif");
    let base = Image::new(&jpeg).expect("decode jpeg");

    bench("image_crop_100", 200, || {
        let mut img = base.clone();
        img.crop(100, 100, GRAVITY_CENTER).unwrap();
        std::hint::black_box(img.width());
    });

    bench("image_crop_gravity", 200, || {
        let mut img = base.clone();
        img.crop(50, 200, GRAVITY_TOP_LEFT).unwrap();
        std::hint::black_box(img.height());
    });

    bench("image_rotate_45", 40, || {
        let mut img = base.clone();
        img.set_rotation(45).unwrap();
        std::hint::black_box(img.width());
    });

    bench("image_opacity", 200, || {
        let mut img = base.clone();
        img.set_opacity(0.5).unwrap();
        std::hint::black_box(img.frame_count());
    });

    bench("image_border", 200, || {
        let mut img = base.clone();
        img.set_border(5, "#ff0000").unwrap();
        std::hint::black_box(img.width());
    });

    bench("image_pipeline_jpeg", 20, || {
        let mut img = Image::new(&jpeg).unwrap();
        img.crop(100, 100, GRAVITY_CENTER).unwrap();
        std::hint::black_box(img.output("jpg", 75).unwrap());
    });

    bench("image_pipeline_webp", 20, || {
        let mut img = Image::new(&jpeg).unwrap();
        img.crop(100, 100, GRAVITY_CENTER).unwrap();
        std::hint::black_box(img.output("webp", 75).unwrap());
    });

    #[cfg(feature = "avif")]
    bench("image_pipeline_avif", 8, || {
        let mut img = Image::new(&jpeg).unwrap();
        img.crop(100, 100, GRAVITY_CENTER).unwrap();
        std::hint::black_box(img.output("avif", 75).unwrap());
    });

    #[cfg(feature = "heic")]
    bench("image_pipeline_heic", 8, || {
        let mut img = Image::new(&jpeg).unwrap();
        img.crop(100, 100, GRAVITY_CENTER).unwrap();
        std::hint::black_box(img.output("heic", 75).unwrap());
    });

    bench("image_gif_crop", 10, || {
        let mut img = Image::new(&gif).unwrap();
        img.crop(100, 100, GRAVITY_CENTER).unwrap();
        std::hint::black_box(img.output("gif", 100).unwrap());
    });
}
