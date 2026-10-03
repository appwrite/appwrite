use utopia_span::{Auto, Coroutine, Memory, Span, Storage};

#[test]
fn memory_get_set_clear_overwrite_independent() {
    let storage = Memory::new();
    assert!(storage.get().is_none());
    let span = Span::new();
    storage.set(Some(span.clone()));
    assert!(storage.get().is_some());
    storage.set(None);
    assert!(storage.get().is_none());

    let a = Memory::new();
    let b = Memory::new();
    let s1 = Span::new();
    let s2 = Span::new();
    a.set(Some(s1.clone()));
    a.set(Some(s2.clone()));
    assert_eq!(a.get().unwrap().get("span.id"), s2.get("span.id"));
    assert!(b.get().is_none());
}

#[test]
fn auto_uses_memory_outside_task() {
    let storage = Auto::new();
    assert!(storage.get().is_none());
    let span = Span::new();
    storage.set(Some(span.clone()));
    assert_eq!(storage.get().unwrap().get("span.id"), span.get("span.id"));
    storage.set(None);
    assert!(storage.get().is_none());
}

#[test]
fn coroutine_noop_outside_task() {
    let storage = Coroutine::new();
    assert!(storage.get().is_none());
    storage.set(Some(Span::new()));
    assert!(storage.get().is_none());
    storage.set(None);
    assert!(storage.get().is_none());
}

#[test]
fn coroutine_isolates_tokio_tasks() {
    let rt = tokio::runtime::Builder::new_multi_thread()
        .enable_all()
        .build()
        .unwrap();
    let storage = std::sync::Arc::new(Coroutine::new());
    let storage_a = std::sync::Arc::clone(&storage);
    let storage_b = std::sync::Arc::clone(&storage);

    let (id_a, id_b) = rt.block_on(async {
        let a = tokio::spawn(async move {
            let span = Span::with_action("a");
            storage_a.set(Some(span.clone()));
            storage_a
                .get()
                .unwrap()
                .get("span.id")
                .and_then(|v| v.as_str().map(str::to_string))
        });
        let b = tokio::spawn(async move {
            let span = Span::with_action("b");
            storage_b.set(Some(span.clone()));
            storage_b
                .get()
                .unwrap()
                .get("span.id")
                .and_then(|v| v.as_str().map(str::to_string))
        });
        (a.await.unwrap(), b.await.unwrap())
    });
    assert_ne!(id_a, id_b);
}
