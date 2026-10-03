//! Crop gravity constants (PHP `Image::GRAVITY_*` parity).

/// Center gravity.
pub const GRAVITY_CENTER: &str = "center";
/// Top-left gravity.
pub const GRAVITY_TOP_LEFT: &str = "top-left";
/// Top gravity.
pub const GRAVITY_TOP: &str = "top";
/// Top-right gravity.
pub const GRAVITY_TOP_RIGHT: &str = "top-right";
/// Left gravity.
pub const GRAVITY_LEFT: &str = "left";
/// Right gravity.
pub const GRAVITY_RIGHT: &str = "right";
/// Bottom-left gravity.
pub const GRAVITY_BOTTOM_LEFT: &str = "bottom-left";
/// Bottom gravity.
pub const GRAVITY_BOTTOM: &str = "bottom";
/// Bottom-right gravity.
pub const GRAVITY_BOTTOM_RIGHT: &str = "bottom-right";

/// All supported gravity type strings.
pub fn gravity_types() -> &'static [&'static str] {
    &[
        GRAVITY_CENTER,
        GRAVITY_TOP_LEFT,
        GRAVITY_TOP,
        GRAVITY_TOP_RIGHT,
        GRAVITY_LEFT,
        GRAVITY_RIGHT,
        GRAVITY_BOTTOM_LEFT,
        GRAVITY_BOTTOM,
        GRAVITY_BOTTOM_RIGHT,
    ]
}

/// Map a Utopia gravity string to FIR `fit_into_destination` centering `(x, y)`.
///
/// Unknown values fall back to center `(0.5, 0.5)`, matching PHP's `default` branch.
pub fn centering(gravity: &str) -> (f64, f64) {
    match gravity {
        GRAVITY_TOP_LEFT => (0.0, 0.0),
        GRAVITY_TOP => (0.5, 0.0),
        GRAVITY_TOP_RIGHT => (1.0, 0.0),
        GRAVITY_LEFT => (0.0, 0.5),
        GRAVITY_RIGHT => (1.0, 0.5),
        GRAVITY_BOTTOM_LEFT => (0.0, 1.0),
        GRAVITY_BOTTOM => (0.5, 1.0),
        GRAVITY_BOTTOM_RIGHT => (1.0, 1.0),
        _ => (0.5, 0.5),
    }
}
