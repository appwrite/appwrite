//! The facade on the engine of the build: tasks, timers, sockets, files,
//! processes and the test attribute.

use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::{Duration, Instant};

use utopia_async::io::{AsyncReadExt, AsyncSeekExt, AsyncWriteExt, SeekFrom};
use utopia_async::net::{TcpListener, TcpSocket, TcpStream, UdpSocket};
use utopia_async::process::{Command, Stdio};
use utopia_async::sync::{Notify, oneshot};
use utopia_async::task::JoinSet;
use utopia_async::time::{MissedTickBehavior, interval_at, sleep_until, timeout_at};
use utopia_async::{Builder, Flavor, Handle, Runtime};

fn scratch(name: &str) -> std::path::PathBuf {
    std::env::temp_dir().join(format!("utopia-async-{}-{name}", std::process::id()))
}

#[test]
fn run_returns_the_output() {
    assert_eq!(utopia_async::run(async { 7 }), 7);
}

#[test]
fn runtimes_report_their_flavor() {
    let single = Builder::current_thread().build().unwrap();
    assert_eq!(single.block_on(async { Handle::current().map(|h| h.flavor()) }), Some(Flavor::CurrentThread));
    let multi = Builder::multi_thread().threads(2).name("engine-test").build().unwrap();
    assert_eq!(multi.block_on(async { Handle::current().map(|h| h.flavor()) }), Some(Flavor::MultiThread));
    assert!(Handle::current().is_none());
}

#[test]
fn a_runtime_spawns_from_outside() {
    let runtime = Runtime::new().unwrap();
    let task = runtime.spawn(async { "spawned" });
    assert_eq!(runtime.block_on(task).unwrap(), "spawned");
    let handle = runtime.handle();
    let task = std::thread::spawn(move || handle.block_on(async { utopia_async::spawn(async { 3 }).await.unwrap() }));
    assert_eq!(task.join().unwrap(), 3);
    runtime.shutdown_timeout(Duration::from_secs(1));
}

#[utopia_async::test]
async fn spawned_tasks_return_their_output() {
    let task = utopia_async::spawn(async { 40 + 2 });
    let id = task.id();
    assert_eq!(task.await.unwrap(), 42);
    assert!(id.as_u64() > 0);
}

#[utopia_async::test]
async fn task_ids_are_set_inside_tasks_only() {
    assert!(utopia_async::task::id().is_none());
    let task = utopia_async::spawn(async { utopia_async::task::id() });
    let id = task.id();
    assert_eq!(task.await.unwrap(), Some(id));
}

#[utopia_async::test]
async fn aborted_tasks_are_cancelled() {
    let task = utopia_async::spawn(std::future::pending::<()>());
    task.abort();
    let error = task.await.unwrap_err();
    assert!(error.is_cancelled());
    assert!(error.to_string().ends_with("was cancelled"), "{error}");
}

#[utopia_async::test]
async fn panics_are_reported() {
    let task = utopia_async::spawn(async { panic!("boom") });
    let error = task.await.unwrap_err();
    assert!(error.is_panic());
    assert!(error.to_string().ends_with("panicked with message \"boom\""), "{error}");
    assert_eq!(*error.into_panic().downcast::<&str>().unwrap(), "boom");
}

#[utopia_async::test]
async fn finished_tasks_say_so() {
    let task = utopia_async::spawn(async {});
    utopia_async::sleep(Duration::from_millis(5)).await;
    assert!(task.is_finished());
}

#[utopia_async::test]
async fn join_sets_collect_in_order_of_completion() {
    let mut set = JoinSet::new();
    set.spawn(async {
        utopia_async::sleep(Duration::from_millis(20)).await;
        "slow"
    });
    set.spawn(async { "fast" });
    set.spawn_blocking(|| "blocking");
    assert_eq!(set.len(), 3);
    let mut order = Vec::new();
    while let Some(result) = set.join_next().await {
        order.push(result.unwrap());
    }
    assert_eq!(order.last(), Some(&"slow"));
    assert!(set.is_empty());
}

#[utopia_async::test]
async fn dropping_a_join_set_cancels_its_tasks() {
    let (sender, receiver) = oneshot::channel::<()>();
    let mut set = JoinSet::new();
    set.spawn(async move {
        let _sender = sender;
        std::future::pending::<()>().await;
    });
    drop(set);
    assert!(receiver.await.is_err());
}

#[utopia_async::test]
async fn join_all_returns_every_output() {
    let mut set = JoinSet::new();
    for n in 0..4 {
        set.spawn(async move { n });
    }
    let mut outputs = set.join_all().await;
    outputs.sort();
    assert_eq!(outputs, [0, 1, 2, 3]);
}

#[utopia_async::test]
async fn blocking_work_runs_on_the_pool() {
    let thread = std::thread::current().id();
    let elsewhere = utopia_async::spawn_blocking(move || std::thread::current().id() != thread).await.unwrap();
    assert!(elsewhere);
    assert_eq!(utopia_async::block_in_place(|| 5), 5);
}

#[utopia_async::test(threads = 2)]
async fn block_in_place_keeps_other_tasks_running() {
    let ran = Arc::new(AtomicUsize::new(0));
    let counter = ran.clone();
    let other = utopia_async::spawn(async move {
        counter.fetch_add(1, Ordering::SeqCst);
    });
    utopia_async::block_in_place(|| std::thread::sleep(Duration::from_millis(20)));
    other.await.unwrap();
    assert_eq!(ran.load(Ordering::SeqCst), 1);
}

#[utopia_async::test]
async fn yielding_lets_other_tasks_run() {
    let ran = Arc::new(AtomicUsize::new(0));
    let counter = ran.clone();
    utopia_async::spawn(async move {
        counter.fetch_add(1, Ordering::SeqCst);
    });
    utopia_async::yield_now().await;
    assert_eq!(ran.load(Ordering::SeqCst), 1);
}

#[utopia_async::test]
async fn sleeps_last_and_reset() {
    let start = Instant::now();
    utopia_async::sleep(Duration::from_millis(10)).await;
    assert!(start.elapsed() >= Duration::from_millis(10));

    let sleep = sleep_until(Instant::now() + Duration::from_secs(60));
    utopia_async::pin!(sleep);
    assert!(!sleep.is_elapsed());
    let soon = Instant::now() + Duration::from_millis(5);
    sleep.as_mut().reset(soon);
    assert_eq!(sleep.deadline(), soon);
    sleep.as_mut().await;
    assert!(sleep.is_elapsed());

    // A duration past what an instant holds sleeps "forever" rather than
    // overflowing.
    let forever = utopia_async::sleep(Duration::MAX);
    assert!(utopia_async::timeout(Duration::from_millis(1), forever).await.is_err());
}

#[utopia_async::test]
async fn timeouts_give_up_or_pass_through() {
    assert_eq!(utopia_async::timeout(Duration::from_secs(1), async { 1 }).await, Ok(1));
    let elapsed = utopia_async::timeout(Duration::from_millis(5), std::future::pending::<()>()).await.unwrap_err();
    assert_eq!(elapsed.to_string(), "deadline has elapsed");
    assert_eq!(std::io::Error::from(elapsed).kind(), std::io::ErrorKind::TimedOut);
    let at = timeout_at(Instant::now() + Duration::from_millis(5), std::future::pending::<()>());
    assert!(at.await.is_err());
}

#[utopia_async::test]
async fn intervals_tick_on_schedule() {
    let period = Duration::from_millis(5);
    let start = Instant::now();
    let mut ticks = utopia_async::time::interval(period);
    let first = ticks.tick().await;
    assert!(first - start < period);
    let second = ticks.tick().await;
    assert_eq!(second - first, period);

    let mut delayed = interval_at(Instant::now(), period);
    delayed.set_missed_tick_behavior(MissedTickBehavior::Delay);
    delayed.tick().await;
    std::thread::sleep(Duration::from_millis(20));
    let late = delayed.tick().await;
    let next = delayed.tick().await;
    assert!(next - late >= period);
}

#[utopia_async::test]
async fn notifications_wake_waiters() {
    let notify = Arc::new(Notify::new());
    let waiter = notify.clone();
    let task = utopia_async::spawn(async move { waiter.notified().await });
    utopia_async::yield_now().await;
    notify.notify_one();
    task.await.unwrap();
}

#[utopia_async::test]
async fn tcp_round_trips() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let addr = listener.local_addr().unwrap();
    let server = utopia_async::spawn(async move {
        let (socket, peer) = listener.accept().await.unwrap();
        assert!(peer.ip().is_loopback());
        let (mut read, mut write) = socket.into_split();
        let mut buf = [0; 4];
        read.read_exact(&mut buf).await.unwrap();
        write.write_all(&buf).await.unwrap();
        write.shutdown().await.unwrap();
    });

    let mut client = TcpStream::connect(("localhost", addr.port())).await.unwrap();
    client.set_nodelay(true).unwrap();
    assert!(client.nodelay().unwrap());
    client.set_keepalive(Some(Duration::from_secs(30))).unwrap();
    client.set_keepalive(None).unwrap();
    assert_eq!(client.peer_addr().unwrap().port(), addr.port());
    client.write_all(b"ping").await.unwrap();
    let mut echo = Vec::new();
    client.read_to_end(&mut echo).await.unwrap();
    assert_eq!(echo, b"ping");
    server.await.unwrap();
}

#[utopia_async::test]
async fn tcp_sockets_configure_then_connect() {
    let socket = TcpSocket::new_v4().unwrap();
    socket.set_reuseaddr(true).unwrap();
    socket.bind("127.0.0.1:0".parse().unwrap()).unwrap();
    let listener = socket.listen(16).unwrap();
    let addr = listener.local_addr().unwrap();

    let client = TcpSocket::new_v4().unwrap();
    client.set_recv_buffer_size(4096).unwrap();
    let (stream, accepted) = utopia_async::join!(client.connect(addr), listener.accept());
    assert_eq!(stream.unwrap().local_addr().unwrap(), accepted.unwrap().1);
}

#[utopia_async::test]
async fn connecting_to_nothing_fails() {
    let error = TcpStream::connect("not an address").await.unwrap_err();
    assert_eq!(error.kind(), std::io::ErrorKind::InvalidInput);
    let error = TcpStream::connect("localhost:port").await.unwrap_err();
    assert_eq!(error.to_string(), "invalid port value");
    let empty: &[std::net::SocketAddr] = &[];
    let error = TcpStream::connect(empty).await.unwrap_err();
    assert_eq!(error.to_string(), "could not resolve to any address");
}

#[utopia_async::test]
async fn udp_round_trips() {
    let server = UdpSocket::bind("127.0.0.1:0").await.unwrap();
    let client = UdpSocket::bind(("127.0.0.1", 0)).await.unwrap();
    client.connect(server.local_addr().unwrap()).await.unwrap();
    client.send(b"query").await.unwrap();
    let mut buf = [0; 16];
    let (n, from) = server.recv_from(&mut buf).await.unwrap();
    assert_eq!((&buf[..n], from), (&b"query"[..], client.local_addr().unwrap()));
    server.send_to(b"answer", from).await.unwrap();
    let n = client.recv(&mut buf).await.unwrap();
    assert_eq!(&buf[..n], b"answer");
}

#[utopia_async::test]
async fn hosts_resolve() {
    let addrs: Vec<_> = utopia_async::net::lookup_host("127.0.0.1:53").await.unwrap().collect();
    assert_eq!(addrs, ["127.0.0.1:53".parse().unwrap()]);
    let addrs: Vec<_> = utopia_async::net::lookup_host(("localhost", 80)).await.unwrap().collect();
    assert!(addrs.iter().all(|a| a.ip().is_loopback() && a.port() == 80), "{addrs:?}");
}

#[cfg(unix)]
#[utopia_async::test]
async fn unix_streams_connect() {
    let path = scratch("socket");
    let _ = std::fs::remove_file(&path);
    let listener = std::os::unix::net::UnixListener::bind(&path).unwrap();
    let mut client = utopia_async::net::UnixStream::connect(&path).await.unwrap();
    let (server, _) = utopia_async::spawn_blocking(move || listener.accept()).await.unwrap().unwrap();
    client.write_all(b"unix").await.unwrap();
    let received = utopia_async::spawn_blocking(move || {
        let mut server = server;
        let mut buf = [0; 4];
        std::io::Read::read_exact(&mut server, &mut buf).map(|()| buf)
    })
    .await
    .unwrap()
    .unwrap();
    assert_eq!(&received, b"unix");
    std::fs::remove_file(&path).unwrap();
}

#[utopia_async::test]
async fn files_are_written_read_and_listed() {
    let dir = scratch("dir");
    let _ = std::fs::remove_dir_all(&dir);
    utopia_async::fs::DirBuilder::new().recursive(true).create(dir.join("nested")).await.unwrap();
    for n in 0..40 {
        utopia_async::fs::write(dir.join(format!("{n}.txt")), n.to_string()).await.unwrap();
    }
    let mut entries = utopia_async::fs::read_dir(&dir).await.unwrap();
    let mut count = 0;
    while let Some(entry) = entries.next_entry().await.unwrap() {
        assert!(entry.path().starts_with(&dir));
        if entry.file_type().await.unwrap().is_dir() {
            assert_eq!(entry.file_name(), "nested");
        }
        count += 1;
    }
    assert_eq!(count, 41);
    assert_eq!(utopia_async::fs::read_to_string(dir.join("7.txt")).await.unwrap(), "7");
    assert!(utopia_async::fs::try_exists(dir.join("7.txt")).await.unwrap());
    utopia_async::fs::rename(dir.join("7.txt"), dir.join("seven.txt")).await.unwrap();
    assert_eq!(utopia_async::fs::copy(dir.join("seven.txt"), dir.join("copy.txt")).await.unwrap(), 1);
    utopia_async::fs::remove_file(dir.join("copy.txt")).await.unwrap();
    assert!(utopia_async::fs::metadata(dir.join("copy.txt")).await.is_err());
    utopia_async::fs::remove_dir_all(&dir).await.unwrap();
    assert!(!utopia_async::fs::try_exists(&dir).await.unwrap());
}

#[utopia_async::test]
async fn files_seek_and_truncate() {
    let path = scratch("file");
    let mut file = utopia_async::fs::File::create(&path).await.unwrap();
    file.write_all(b"hello world").await.unwrap();
    file.flush().await.unwrap();
    file.set_len(5).await.unwrap();
    file.sync_all().await.unwrap();
    assert_eq!(file.metadata().await.unwrap().len(), 5);
    drop(file);

    let mut file = utopia_async::fs::OpenOptions::new().read(true).write(true).open(&path).await.unwrap();
    file.seek(SeekFrom::Start(1)).await.unwrap();
    let mut rest = String::new();
    file.read_to_string(&mut rest).await.unwrap();
    assert_eq!(rest, "ello");
    let std = file.into_std().await;
    assert_eq!(std.metadata().unwrap().len(), 5);
    utopia_async::fs::remove_file(&path).await.unwrap();
}

#[utopia_async::test]
async fn processes_pipe_and_exit() {
    let mut child =
        Command::new("cat").stdin(Stdio::piped()).stdout(Stdio::piped()).stderr(Stdio::null()).spawn().unwrap();
    assert!(child.id().is_some());
    let mut stdin = child.stdin.take().unwrap();
    stdin.write_all(b"through cat").await.unwrap();
    drop(stdin);
    let output = child.wait_with_output().await.unwrap();
    assert!(output.status.success());
    assert_eq!(output.stdout, b"through cat");

    let output = Command::new("sh").args(["-c", "echo $GREETING >&2; exit 3"]).env("GREETING", "hi").output().await;
    let output = output.unwrap();
    assert_eq!((output.status.code(), output.stderr.as_slice()), (Some(3), &b"hi\n"[..]));

    let mut sleeper = Command::new("sleep").arg("30").kill_on_drop(true).spawn().unwrap();
    assert!(sleeper.try_wait().unwrap().is_none());
    sleeper.kill().await.unwrap();
    assert!(!sleeper.try_wait().unwrap().unwrap().success());
}

#[cfg(unix)]
#[utopia_async::test]
async fn signals_are_received() {
    use utopia_async::signal::{Kind, Signal};

    let mut user = Signal::new(Kind::User1).unwrap();
    let pid = std::process::id().to_string();
    Command::new("kill").args(["-USR1", &pid]).status().await.unwrap();
    assert_eq!(utopia_async::timeout(Duration::from_secs(5), user.recv()).await, Ok(Some(())));
}

#[utopia_async::test]
async fn select_takes_the_first() {
    let picked = utopia_async::select! {
        _ = utopia_async::sleep(Duration::from_secs(5)) => "slow",
        _ = utopia_async::sleep(Duration::from_millis(1)) => "fast",
    };
    assert_eq!(picked, "fast");
    let (a, b) = utopia_async::join!(async { 1 }, async { 2 });
    assert_eq!(a + b, 3);
    let both: Result<(i32, i32), ()> = utopia_async::try_join!(async { Ok(1) }, async { Ok(2) });
    assert_eq!(both, Ok((1, 2)));
}

utopia_async::task_local! {
    static REQUEST: u32;
}

#[utopia_async::test]
async fn task_locals_are_scoped() {
    let seen = REQUEST.scope(7, async { REQUEST.get() }).await;
    assert_eq!(seen, 7);
    assert!(REQUEST.try_with(|r| *r).is_err());
}

#[utopia_async::test]
async fn standard_streams_open() {
    utopia_async::io::stdout().flush().await.unwrap();
    utopia_async::io::stderr().flush().await.unwrap();
    let _ = utopia_async::io::stdin();
}

#[utopia_async::test]
async fn tests_can_return_results() -> std::io::Result<()> {
    utopia_async::fs::metadata(std::env::temp_dir()).await?;
    Ok(())
}
