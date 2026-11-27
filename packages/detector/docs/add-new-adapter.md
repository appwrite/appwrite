# Add new Detector and Detection Adapters

The detectors and detections are classes of the `utopia` PHP extension, so a new adapter is Rust:

- **The adapter:** in [`crates/utopia/detector`](../../../crates/utopia/detector) (`src/framework.rs`, `src/packager.rs`, `src/runtime.rs` or `src/rendering.rs`), with its tests. The crate's [guide](../../../crates/utopia/detector/guide.md) shows how each detector chooses.
- **The PHP class:** in [`crates/extension/src/detector`](../../../crates/extension/src/detector) (`detections.rs` lists every leaf with its name, parent and answers), and its signature in [`stubs/`](../stubs).
- **Tests:** add the cases to [`tests/DetectorTest.php`](../tests/DetectorTest.php); `crates/extension/tests/run.sh` runs them on the extension.
