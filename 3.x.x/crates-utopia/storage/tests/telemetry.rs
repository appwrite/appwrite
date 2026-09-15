#![cfg(feature = "telemetry")]

use tempfile::TempDir;
use utopia_storage::{Device, Local, TelemetryDevice};
use utopia_telemetry::TestAdapter;

#[test]
fn storage_operation_histogram_is_recorded() {
    let temp = TempDir::new().expect("tempdir");
    let telemetry = TestAdapter::new();
    let local = Local::new(temp.path());
    let device = TelemetryDevice::new(&telemetry, local);
    let path = device.get_device().get_path("lorem.txt");

    assert!(telemetry.snapshot().histograms.is_empty());
    assert!(!device.exists(&path));

    let measurements = telemetry.histogram_measurements("storage.operation");
    assert_eq!(measurements.len(), 1);
    assert_eq!(measurements[0].attributes.get("storage").unwrap(), "local");
    assert_eq!(
        measurements[0].attributes.get("operation").unwrap(),
        "device:exists"
    );
}

#[test]
fn decorated_device_is_accessible() {
    let temp = TempDir::new().expect("tempdir");
    let telemetry = TestAdapter::new();
    let local = Local::new(temp.path());
    let root = local.get_root().to_path_buf();
    let device = TelemetryDevice::new(&telemetry, local);

    assert_eq!(device.get_device().get_root(), root.as_path());
}
