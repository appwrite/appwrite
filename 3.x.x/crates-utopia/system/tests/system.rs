use utopia_system::{get_arch, get_cpu_cores, get_env, get_os};

#[test]
fn cpu_cores_is_at_least_one() {
    assert!(get_cpu_cores() >= 1);
}

#[test]
fn os_and_arch_are_non_empty() {
    assert!(!get_os().is_empty());
    assert!(!get_arch().is_empty());
}

#[test]
fn env_reads_existing_and_default() {
    // Avoid `std::env::set_var` (unsafe under forbid(unsafe_code)).
    // `PATH` is present in normal process environments.
    let path = get_env("PATH", "default");
    assert_ne!(path, "default");
    assert!(!path.is_empty());

    assert_eq!(
        get_env("UTOPIA_SYSTEM_MISSING_ENV_XYZ_12345", "default"),
        "default"
    );
}
