//! The SQL adapters: PostgreSQL, MariaDB, MySQL and SQLite over one shared
//! base, as PHP's `Postgres`, `MariaDB`, `MySQL` and `SQLite` extend
//! `Adapter\SQL`.
//!
//! PHP: `Utopia\Database\Adapter\SQL` and its subclasses, `Adapter\SQL\*`,
//! `Utopia\Database\PDO`, `Utopia\Database\PDOStatement`.

pub mod hook;
pub mod pdo;
