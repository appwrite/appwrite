//! Port of `tests/Unit/SubscriptionSlowConsumerTest.php`.

use parking_lot::Mutex;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use utopia_nats::subscription::{MessageCallback, SlowConsumerCallback};
use utopia_nats::{Message, Subscription};

#[test]
fn test_exceeding_message_limit_signals_and_drops() {
    let signaled = Arc::new(Mutex::new(Vec::new()));
    let signaled2 = Arc::clone(&signaled);
    let on_slow: SlowConsumerCallback = Arc::new(move |s: &Subscription| {
        signaled2.lock().push(s.sid.clone());
    });
    let sub = Subscription::new("1", "foo", None, None, 3, 1_000_000, Some(on_slow));
    for _ in 0..10 {
        sub.deliver(Message::new("foo", "x"));
    }
    assert!(!signaled.lock().is_empty(), "slow consumer callback fired");
    assert_eq!(signaled.lock()[0], "1");
    assert_eq!(sub.get_pending_count(), 3);
}

#[test]
fn test_exceeding_byte_limit_signals() {
    let fired = Arc::new(AtomicUsize::new(0));
    let fired2 = Arc::clone(&fired);
    let on_slow: SlowConsumerCallback = Arc::new(move |_s| {
        fired2.fetch_add(1, Ordering::SeqCst);
    });
    let sub = Subscription::new("2", "foo", None, None, 1_000_000, 10, Some(on_slow));
    sub.deliver(Message::new("foo", "a".repeat(6)));
    assert_eq!(fired.load(Ordering::SeqCst), 0);
    assert_eq!(sub.get_pending_bytes(), 6);
    sub.deliver(Message::new("foo", "a".repeat(6)));
    assert_eq!(fired.load(Ordering::SeqCst), 1);
    assert_eq!(sub.get_pending_bytes(), 6, "over-limit message dropped");
    assert_eq!(sub.get_pending_count(), 1);
}

#[test]
fn test_draining_resets_signal_and_bytes() {
    let fired = Arc::new(AtomicUsize::new(0));
    let fired2 = Arc::clone(&fired);
    let on_slow: SlowConsumerCallback = Arc::new(move |_s| {
        fired2.fetch_add(1, Ordering::SeqCst);
    });
    let sub = Subscription::new("3", "foo", None, None, 2, 1_000_000, Some(on_slow));
    sub.deliver(Message::new("foo", "aa"));
    sub.deliver(Message::new("foo", "bb"));
    sub.deliver(Message::new("foo", "cc"));
    assert_eq!(fired.load(Ordering::SeqCst), 1);
    sub.next_message(Some(0.0));
    assert_eq!(sub.get_pending_count(), 1);
    sub.deliver(Message::new("foo", "dd"));
    sub.deliver(Message::new("foo", "ee"));
    assert_eq!(fired.load(Ordering::SeqCst), 2);
}

#[test]
fn test_callback_subscriptions_never_queue() {
    let received = Arc::new(AtomicUsize::new(0));
    let received2 = Arc::clone(&received);
    let callback: MessageCallback = Arc::new(move |_m| {
        received2.fetch_add(1, Ordering::SeqCst);
    });
    let sub = Subscription::new("4", "foo", None, Some(callback), 1, 1, None);
    for _ in 0..5 {
        sub.deliver(Message::new("foo", "payload"));
    }
    assert_eq!(received.load(Ordering::SeqCst), 5);
    assert_eq!(sub.get_pending_count(), 0);
}
