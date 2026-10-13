A migration moves resources (users, databases, tables, rows, buckets, files, functions, messaging, sites) from one service into another. A `Source` exports them: Appwrite (through its API or straight from a project database), Supabase, NHost, Firebase, or a CSV or JSON file. A `Destination` imports them: Appwrite, a CSV or JSON file, or a local backup. A `Transfer` connects the two, keeps every resource it has seen in a `Cache`, and counts them by status. Sources and destinations are async, so the examples run them on a Tokio runtime. This guide covers the common tasks.

## Stream a large JSON export

`json_machine::Items` reads a JSON array (or object) one item at a time, so an export of millions of rows never has to fit in memory. Each item comes with its key; the `Ext` decoder decodes it like `json_decode`, `PassThru` hands back its JSON text.

```
use php_std::Value;
use utopia_migration::json_machine::{Decoder, Items};

tokio::runtime::Runtime::new()?.block_on(async {
    let json = r#"[{"$id": "ada", "age": 36}, {"$id": "alan", "age": 41}]"#;
    let mut items = Items::from_bytes(json, Decoder::Ext { assoc: true });
    let mut ids = Vec::new();
    while let Some((_key, row)) = items.next().await? {
        if let Value::Array(row) = row {
            ids.push(row.get_str("$id").cloned());
        }
    }
    assert_eq!(ids, [Some(Value::from("ada")), Some(Value::from("alan"))]);

    // A truncated document yields what it has, then fails where it ends.
    let mut broken = Items::from_bytes("[1, 2", Decoder::PassThru);
    assert_eq!(broken.next().await?.map(|(_, v)| v), Some(Value::from("1")));
    assert_eq!(broken.next().await?.map(|(_, v)| v), Some(Value::from("2")));
    assert!(broken.next().await.is_err());
    Ok::<_, utopia_migration::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonMachine\JsonDecoder\PassThruDecoder;

$json = '[{"$id": "ada", "age": 36}, {"$id": "alan", "age": 41}]';
foreach (Items::fromString($json, ['decoder' => new ExtJsonDecoder(true)]) as $row) {
    echo $row['$id'], "\n"; // ada, then alan
}

try {
    foreach (Items::fromString('[1, 2', ['decoder' => new PassThruDecoder()]) as $item) {
        echo $item, "\n"; // 1, then 2
    }
} catch (\JsonMachine\Exception\JsonMachineException $e) {
    echo $e->getMessage(), "\n"; // JSON string ended unexpectedly '2' At position 0.
}
```

## Turn a JSON export into a CSV file

A JSON file of rows is a source for one table (`<database>:<table>`); a CSV destination writes the rows it imports to `<directory>/<filename>.csv` on a storage device. Run the transfer for the `row` resource type, then shut the destination down: that is when the file is moved onto the device.

```
use std::sync::Arc;
use utopia_migration::destinations::csv::{Csv, CsvOptions};
use utopia_migration::sources::json::Json;
use utopia_migration::{Destination, Transfer};
use utopia_storage::{Device, Local};

let dir = std::env::temp_dir().join(format!("utopia-migration-guide-{}", std::process::id()));
std::fs::create_dir_all(&dir)?;
std::fs::write(dir.join("people.json"), r#"[{"$id": "ada", "name": "Ada", "age": 36}, {"$id": "alan", "name": "Alan", "age": 41}]"#)?;

tokio::runtime::Runtime::new()?.block_on(async {
    let device: Arc<dyn Device> = Arc::new(Local::new(dir.to_str().unwrap_or_default()));
    let source = Json::new("people:people", dir.join("people.json").to_string_lossy(), device.clone());
    let destination =
        Csv::new(device, "people:people", "exports", "people", Vec::new(), CsvOptions::default()).await?;

    let mut transfer = Transfer::new(Box::new(source), Box::new(destination));
    transfer.run(&["row"], &mut |_rows| Ok(()), None, None).await?;
    transfer.destination_mut().shutdown().await?;
    Ok::<_, utopia_migration::Error>(())
})?;

let csv = std::fs::read_to_string(dir.join("exports/people.csv"))?;
assert_eq!(csv, "$id,$permissions,$createdAt,$updatedAt,name,age\nada,,,,Ada,36\nalan,,,,Alan,41\n");
std::fs::remove_dir_all(&dir)?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Migration\Destinations\CSV;
use Utopia\Migration\Sources\JSON;
use Utopia\Migration\Transfer;
use Utopia\Storage\Device\Local;

$dir = sys_get_temp_dir() . '/utopia-migration-guide-' . getmypid();
mkdir($dir, 0755, true);
file_put_contents("$dir/people.json", '[{"$id": "ada", "name": "Ada", "age": 36}, {"$id": "alan", "name": "Alan", "age": 41}]');

$device = new Local($dir);
$source = new JSON('people:people', "$dir/people.json", $device, null);
$destination = new CSV($device, 'people:people', 'exports', 'people');

$transfer = new Transfer($source, $destination);
$transfer->run(['row'], function (array $rows) {});
$destination->shutdown();

echo file_get_contents("$dir/exports/people.csv"); // $id,$permissions,$createdAt,$updatedAt,name,age ...
exec('rm -rf ' . escapeshellarg($dir));
```

## Count what a transfer moved

Every resource the transfer sees is cached with its status. `status_counters()` totals them per resource type (types with nothing are left out), and `report()` lists them, filtered by a status when you give one. Rows are cached as a count per status rather than one by one.

```
use std::sync::Arc;
use utopia_migration::destinations::json::Json as JsonDestination;
use utopia_migration::sources::json::Json;
use utopia_migration::{Destination, Transfer};
use utopia_storage::{Device, Local};

let dir = std::env::temp_dir().join(format!("utopia-migration-counters-{}", std::process::id()));
std::fs::create_dir_all(&dir)?;
std::fs::write(dir.join("in.json"), r#"[{"$id": "a", "n": 1}, {"$id": "b", "n": 2}, {"$id": "c", "n": 3}]"#)?;

tokio::runtime::Runtime::new()?.block_on(async {
    let device: Arc<dyn Device> = Arc::new(Local::new(dir.to_str().unwrap_or_default()));
    let source = Json::new("db:numbers", dir.join("in.json").to_string_lossy(), device.clone());
    let destination = JsonDestination::new(device, "db:numbers", "out", "numbers", Vec::new()).await?;

    let mut transfer = Transfer::new(Box::new(source), Box::new(destination));
    transfer.run(&["row"], &mut |_rows| Ok(()), None, None).await?;
    transfer.destination_mut().shutdown().await?;

    let counters = transfer.status_counters();
    assert_eq!(counters["row"]["success"], 3);
    // Rows are cached as counts per status, so their report lines carry
    // the status as the id and the count as the status.
    let report = transfer.report("");
    assert_eq!((report[0].resource.as_str(), report[0].status.as_str()), ("row", "3"));
    assert_eq!(report[0].id, php_std::Value::from("success"));
    Ok::<_, utopia_migration::Error>(())
})?;
std::fs::remove_dir_all(&dir)?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Migration\Destinations\JSON as JSONDestination;
use Utopia\Migration\Sources\JSON;
use Utopia\Migration\Transfer;
use Utopia\Storage\Device\Local;

$dir = sys_get_temp_dir() . '/utopia-migration-counters-' . getmypid();
mkdir($dir, 0755, true);
file_put_contents("$dir/in.json", '[{"$id": "a", "n": 1}, {"$id": "b", "n": 2}, {"$id": "c", "n": 3}]');

$device = new Local($dir);
$destination = new JSONDestination($device, 'db:numbers', 'out', 'numbers');
$transfer = new Transfer(new JSON('db:numbers', "$dir/in.json", $device, null), $destination);
$transfer->run(['row'], function (array $rows) {});
$destination->shutdown();

echo $transfer->getStatusCounters()['row']['success'], "\n"; // 3
$line = $transfer->getReport()[0];
echo $line['resource'], ' ', $line['id'], ' ', $line['status'], "\n"; // row success 3
exec('rm -rf ' . escapeshellarg($dir));
```

## Decide what happens to resources that already exist

When the destination already has a database, table, column or index, `OnDuplicate` decides: `Fail` creates it anyway (so the create fails), `Skip` keeps the destination's, and `Overwrite` updates it only when the source changed it more recently. The Appwrite destination takes the mode in its options; `resolve_schema_action` is the decision itself.

```
use utopia_migration::{OnDuplicate, SchemaAction};

let source = Some("2025-03-01 10:00:00");
let dest = Some("2025-01-01 10:00:00");

assert_eq!(OnDuplicate::Overwrite.resolve_schema_action(true, source, dest), SchemaAction::Overwrite);
assert_eq!(OnDuplicate::Overwrite.resolve_schema_action(true, dest, source), SchemaAction::Skip);
assert_eq!(OnDuplicate::Skip.resolve_schema_action(true, source, dest), SchemaAction::Skip);
assert_eq!(OnDuplicate::Fail.resolve_schema_action(true, source, dest), SchemaAction::Create);
// Nothing there yet: every mode creates it.
assert_eq!(OnDuplicate::Skip.resolve_schema_action(false, None, None), SchemaAction::Create);
assert_eq!(OnDuplicate::from_value("overwrite"), Some(OnDuplicate::Overwrite));
```

```php
use Utopia\Migration\Destinations\OnDuplicate;

$source = '2025-03-01 10:00:00';
$dest = '2025-01-01 10:00:00';

echo OnDuplicate::Overwrite->resolveSchemaAction(true, $source, $dest)->name, "\n"; // Overwrite
echo OnDuplicate::Overwrite->resolveSchemaAction(true, $dest, $source)->name, "\n"; // Skip
echo OnDuplicate::Skip->resolveSchemaAction(true, $source, $dest)->name, "\n"; // Skip
echo OnDuplicate::Fail->resolveSchemaAction(true, $source, $dest)->name, "\n"; // Create
echo OnDuplicate::Skip->resolveSchemaAction(false, null, null)->name, "\n"; // Create
echo OnDuplicate::from('overwrite')->name, "\n"; // Overwrite
```
