# Getting started

`utopia-orchestration` runs containers on Docker, through the `docker` command-line client (`DockerCli`) or the Engine API on Docker's Unix socket (`DockerApi`). Both offer the same operations behind the `Adapter` trait, and `Orchestration` fronts either one with the settings every container gets.

## Run a container with limits and labels

Set the label namespace and limits once, then run containers with `RunOptions`; every container gets `<namespace>-type` and `<namespace>-created` labels besides yours.

```no_run
use utopia_orchestration::{DockerCli, Orchestration, Restart, RunOptions};

# async fn run() -> Result<(), utopia_orchestration::Error> {
let mut orchestration = Orchestration::new(DockerCli::new());
orchestration.set_namespace("executor").set_cpus(0.5).set_memory(256);

let options = RunOptions {
    command: vec!["sh".into(), "-c".into(), "tail -f /dev/null".into()],
    workdir: "/usr/local/src".into(),
    vars: vec![("APP_ENV".into(), "production".into())],
    labels: vec![("function".into(), "thumbnails".into())],
    restart: Restart::UnlessStopped,
    ..RunOptions::default()
};
let id = orchestration.run("alpine:3.20", "thumbnails-1", &options).await?;
println!("started {id}");
# Ok(())
# }
```

```php
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;

$orchestration = new Orchestration(new DockerCLI());
$orchestration->setNamespace('executor')->setCpus(0.5)->setMemory(256);
// $id = $orchestration->run('alpine:3.20', 'thumbnails-1', ['sh', '-c', 'tail -f /dev/null'],
//     workdir: '/usr/local/src', vars: ['APP_ENV' => 'production'],
//     labels: ['function' => 'thumbnails'], restart: Adapter::RESTART_UNLESS_STOPPED);
```

## Execute a command and read its output

`execute` sets the output whether or not the command succeeds, and fails with `Error::Timeout` (API) or `Error::Orchestration` when it runs out of time or exits with an error.

```no_run
use utopia_orchestration::{DockerApi, Error, Orchestration};

# async fn run() -> Result<(), Error> {
let orchestration = Orchestration::new(DockerApi::new());
let mut output = Vec::new();
match orchestration.execute("thumbnails-1", &["sh".into(), "-c".into(), "echo -n ready".into()], &mut output, &[], 10).await {
    Ok(_) => assert_eq!(output, b"ready"),
    Err(e) => eprintln!("{e}: {}", String::from_utf8_lossy(&output)),
}
# Ok(())
# }
```

```php
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;

$orchestration = new Orchestration(new DockerAPI());
$output = '';
// $orchestration->execute('thumbnails-1', ['sh', '-c', 'echo -n ready'], $output, [], 10);
```

## Split a command line

`parse_command` splits a command at spaces, keeping a `'`-quoted script whole so it can follow `sh -c`.

```
use utopia_orchestration::parse_command;

let args = parse_command(b"sh -c 'cp /tmp/code.tar.gz . && tar -zxf code.tar.gz'")?;
assert_eq!(args.len(), 3);
assert_eq!(args[2], b"'cp /tmp/code.tar.gz . && tar -zxf code.tar.gz'");
# Ok::<(), utopia_orchestration::Error>(())
```

```php
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;

$args = (new Orchestration(new DockerCLI()))->parseCommandString("sh -c 'cp /tmp/code.tar.gz . && tar -zxf code.tar.gz'");
echo count($args), "\n"; // 3
```

## Find containers and their usage

List containers by Docker filter, and read the usage of the ones they select.

```no_run
use utopia_orchestration::{DockerCli, Orchestration};

# async fn run() -> Result<(), utopia_orchestration::Error> {
let orchestration = Orchestration::new(DockerCli::new());
let filters = [("label".to_owned(), "function=thumbnails".to_owned())];
for container in orchestration.list(&filters).await? {
    println!("{} {}", container.name, container.status);
}
for stats in orchestration.stats(None, &filters).await? {
    println!("{}: {:.1}% CPU, {:.1}% memory", stats.container_name, stats.cpu_usage * 100.0, stats.memory_usage);
}
# Ok(())
# }
```

```php
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;

$orchestration = new Orchestration(new DockerCLI());
// foreach ($orchestration->list(['label' => 'function=thumbnails']) as $container) { ... }
// foreach ($orchestration->getStats(filters: ['label' => 'function=thumbnails']) as $stats) { ... }
```
