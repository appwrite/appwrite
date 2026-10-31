Queries are data: a filter, an order, a page or a join is a `Query` you can build in code, receive as JSON from a client, validate, and compile for the database you run. This library turns lists of queries into SQL for MySQL, MariaDB, PostgreSQL, SQLite and ClickHouse, and into MongoDB commands, always with the values as bindings. It also writes DDL, and reads SQL back with a tokenizer, a parser and a classifier.

## Build a select

Start a builder for your dialect, name the table, add queries, and build: the statement has the SQL with `?` placeholders and the values bound to them, in order.

```
use utopia_query::Query;
use utopia_query::builder::MySql;

let statement = MySql::new()
    .from("users", "")
    .select(["name", "email"])
    .filter([Query::equal("status", ["active"]), Query::greater_than("age", 18)])
    .sort_asc("name", None)
    .limit(25)
    .build()?;
assert_eq!(
    statement.query,
    "SELECT `name`, `email` FROM `users` WHERE `status` IN (?) AND `age` > ? ORDER BY `name` ASC LIMIT ?"
);
assert_eq!(statement.bindings.len(), 3);
# Ok::<(), utopia_query::Error>(())
```

```php
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Query;

$statement = (new MySQL())
    ->from('users')
    ->select(['name', 'email'])
    ->filter([Query::equal('status', ['active']), Query::greaterThan('age', 18)])
    ->sortAsc('name')
    ->limit(25)
    ->build();
echo $statement->query, "\n";
echo count($statement->bindings), "\n"; // 3
```

## Accept queries from clients

Clients send queries as JSON. Parsing refuses raw SQL unless you allow it, so an untrusted list can only filter, order and page.

```
use utopia_query::{Method, Query};

let query = Query::parse(r#"{"method":"lessThan","attribute":"price","values":[100]}"#, false)?;
assert_eq!(query.method(), Method::LessThan);
assert!(Query::parse(r#"{"method":"raw","attribute":"1 = 1"}"#, false).is_err());
# Ok::<(), utopia_query::Error>(())
```

```php
use Utopia\Query\Query;

$query = Query::parse('{"method":"lessThan","attribute":"price","values":[100]}');
echo $query->getMethod()->value, "\n"; // lessThan
```

## Scope every query to a tenant

A hook adds its condition to every statement a builder compiles, so tenancy cannot be forgotten.

```
use utopia_query::builder::MySql;
use utopia_query::hook::Tenant;

let statement = MySql::new().from("docs", "").add_hook(Tenant::new(["7"], "tenant_id")?)?.build()?;
assert_eq!(statement.query, "SELECT * FROM `docs` WHERE tenant_id IN (?)");
# Ok::<(), utopia_query::Error>(())
```

```php
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Hook\Filter\Tenant;

echo (new MySQL())->from('docs')->addHook(new Tenant(['7']))->build()->query, "\n";
// SELECT * FROM `docs` WHERE tenant_id IN (?)
```

## Create a table

A schema writes the DDL of the dialect: describe the table, then create it.

```
use utopia_query::schema::{MySql, Schema};

let mut users = MySql::new().table("users");
users.id("id");
users.string("email", 255);
assert_eq!(
    users.create()?.query,
    "CREATE TABLE `users` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `email` VARCHAR(255) NOT NULL, PRIMARY KEY (`id`))"
);
# Ok::<(), utopia_query::Error>(())
```

```php
use Utopia\Query\Schema\MySQL;

$users = (new MySQL())->table('users');
$users->id();
$users->string('email', 255);
echo $users->create()->query, "\n";
```

## Tell reads from writes

A classifier says whether a statement reads, writes or controls a transaction, to route it to a replica or the primary. `classify` reads a raw wire protocol message; `classify_sql` reads the SQL text.

```
use utopia_query::Type;
use utopia_query::classifier::{MySql, Sql};

assert_eq!(MySql.classify_sql(b"SELECT * FROM users"), Type::Read);
assert_eq!(MySql.classify_sql(b"UPDATE users SET name = 'a'"), Type::Write);
```

```php
use Utopia\Query\Classifier\MySQL;

echo (new MySQL())->classifySQL('SELECT * FROM users')->value, "\n";           // read
echo (new MySQL())->classifySQL("UPDATE users SET name = 'a'")->value, "\n"; // write
```
