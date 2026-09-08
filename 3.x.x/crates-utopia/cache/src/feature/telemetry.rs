use std::sync::Arc;

/// PHP `Utopia\Cache\Feature\Telemetry`.
pub trait Telemetry: Send + Sync {
    fn set_telemetry(&mut self, telemetry: Arc<dyn utopia_telemetry::Adapter>);
}
