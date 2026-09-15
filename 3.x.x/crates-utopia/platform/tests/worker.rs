use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;

use utopia_platform::{Action, ActionType, GenericWorker, Module, Platform, Service};

#[test]
fn worker_start_action_bound_to_worker_start_hook() {
    let invoked = Arc::new(AtomicBool::new(false));
    let flag = invoked.clone();
    let worker_start = Action::new()
        .set_type(ActionType::WorkerStart)
        .groups(["test"])
        .callback(move || {
            flag.store(true, Ordering::SeqCst);
        });
    let service = Service::worker().add_action("workerStartHook", worker_start);

    let mut platform = Platform::new(Module::new()).add_service("testWorker", service);
    platform.init_worker_with_name(Some("test")).unwrap();

    let hooks = platform.get_worker().expect("worker").get_worker_start();
    assert_eq!(hooks.len(), 1);

    hooks[0].get_action()();
    assert!(
        invoked.load(Ordering::SeqCst),
        "TYPE_WORKER_START action must be invoked through the workerStart hook"
    );
}

#[test]
fn worker_stop_action_bound_to_worker_stop_hook() {
    let invoked = Arc::new(AtomicBool::new(false));
    let flag = invoked.clone();
    let worker_stop = Action::new()
        .set_type(ActionType::WorkerStop)
        .groups(["test"])
        .callback(move || {
            flag.store(true, Ordering::SeqCst);
        });
    let service = Service::worker().add_action("workerStopHook", worker_stop);

    let mut platform = Platform::new(Module::new()).add_service("testWorker", service);
    platform.init_worker_with_name(Some("test")).unwrap();

    let hooks = platform.get_worker().expect("worker").get_worker_stop();
    assert_eq!(hooks.len(), 1);

    hooks[0].get_action()();
    assert!(
        invoked.load(Ordering::SeqCst),
        "TYPE_WORKER_STOP action must be invoked through the workerStop hook"
    );
}

#[test]
fn worker_default_job_filtered_by_worker_name() {
    let invoked = Arc::new(AtomicBool::new(false));
    let flag = invoked.clone();
    let job = Action::new().callback(move || {
        flag.store(true, Ordering::SeqCst);
    });
    let other = Action::new().callback(|| {});
    let service = Service::worker()
        .add_action("test", job)
        .add_action("other", other);

    let mut platform = Platform::new(Module::new()).add_service("testWorker", service);
    platform.init_worker_with_name(Some("test")).unwrap();

    let job_hook = platform
        .get_worker()
        .expect("worker")
        .job_hook()
        .expect("job hook");
    job_hook.invoke();
    assert!(invoked.load(Ordering::SeqCst));
}

#[test]
fn generic_worker_hook_registrar() {
    let invoked = Arc::new(AtomicBool::new(false));
    let flag = invoked.clone();
    let mut worker = GenericWorker::new();
    worker
        .worker_start()
        .groups(["test"])
        .action(Arc::new(move || {
            flag.store(true, Ordering::SeqCst);
        }))
        .finish()
        .unwrap();

    worker.worker_start_hooks()[0].invoke();
    assert!(invoked.load(Ordering::SeqCst));
}
