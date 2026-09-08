/// PHP `Utopia\Replication\Source\MySQL\Transport`.
pub trait Transport {
    /// PHP `open(?string $position = null)`.
    fn open(&mut self, position: Option<&str>) -> Result<(), crate::ReplicationError>;
    /// PHP `events()`.
    fn events(&mut self) -> Result<Vec<Vec<u8>>, crate::ReplicationError>;
    /// PHP `checksum()`.
    fn checksum(&self) -> bool;
    /// PHP `position()`.
    fn position(&self) -> String;
    /// PHP `close()`.
    fn close(&mut self);
}
