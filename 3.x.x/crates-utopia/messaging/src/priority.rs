//! PHP `Utopia\Messaging\Priority`.

/// Push notification priority (PHP backed enum `Priority: int`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
#[repr(i32)]
pub enum Priority {
    /// PHP `Priority::NORMAL = 0`.
    Normal = 0,
    /// PHP `Priority::HIGH = 1`.
    High = 1,
}

impl Priority {
    /// Integer value matching the PHP enum.
    #[must_use]
    pub const fn as_int(self) -> i32 {
        self as i32
    }
}
