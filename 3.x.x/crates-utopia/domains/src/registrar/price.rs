/// Domain price (PHP `Utopia\Domains\Registrar\Price`).
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Price {
    pub price: f64,
    pub premium: bool,
}

impl Price {
    /// PHP constructor (`$premium = false`).
    pub fn new(price: f64, premium: bool) -> Self {
        Self { price, premium }
    }
}
