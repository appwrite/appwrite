# Parity

The same images through `Utopia\Image\Image` on Imagick and on the extension, compared case by case.

```sh
docker build --target development --tag appwrite-utopia --build-arg UTOPIA_EXTENSION=true --build-arg TESTING=true .
W=$PWD/target/parity; mkdir -p $W
run() { docker run --rm -v "$PWD/packages:/usr/src/code/packages:ro" -v "$PWD/crates/extension/parity:/parity:ro" -v "$W:/work" "$@"; }
run -e PHP_INI_SCAN_DIR=/tmp/none appwrite-utopia php -d extension=imagick /parity/corpus.php /usr/src/code/packages/image/tests/resources /work/corpus
run --memory 1500m appwrite-utopia sh /parity/run.sh /work/corpus /work/results
run -e PHP_INI_SCAN_DIR=/tmp/none appwrite-utopia php -d extension=imagick /parity/compare.php /work/results
```

- `corpus.php` builds 42 valid images (JPEG encodings, colour spaces, subsampling and all eight EXIF
  orientations; PNG colour types and depths; animated GIF and WebP; AVIF; HEIC; two more with colour
  profiles when given a directory of `.icc` files) and 24 damaged copies of each one under 200 kB.
- `run.sh` runs seven operations on each valid file and a thumbnail on each damaged one, every case in
  its own PHP process with 20 seconds to finish, on both implementations.
- `compare.php` decodes both outputs and reports, per case, the outcome, dimensions, frames, bytes and
  the pixel difference (RMSE of the first frame over magenta, in sRGB), in `report.json`.
- `api.py` runs the corpus through a deployed Appwrite's storage preview endpoint instead, into the
  same layout, to compare two deployments.

## Result, 2026-11-11

1,124 cases a side, in the image, 4 CPUs.

- Valid, 308 cases: 296 match (median pixel difference 1.2%, largest 4.1%), 11 fail the same way on both
  (a 1x400 and a 400x1 image whose crop passes the dimension limit) and 1 differs: a CMYK JPEG with
  opacity and a background, which Imagick renders in wrong colours.
- Damaged, 816 cases: no crash, hang or kill on either side. 27 differ in outcome, where one decoder
  tolerates damage the other refuses.
- Output bytes, extension over Imagick, median: JPEG 0.99, WebP 1.02, PNG 0.99, AVIF 1.04, GIF 1.23.
- Time over the valid cases: 4.7 s against 12.7 s. Largest peak memory: 186 MB against 310 MB.

It found, and this branch fixes: rounded corners after a background were opaque black in the crate;
PHP left the orientation tag on output it had already rotated, so viewers turned AVIF (and JPEG, WebP
and PNG on gravity crops) twice; greyscale images were written as colour by the crate.
