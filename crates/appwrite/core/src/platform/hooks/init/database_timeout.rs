//! The database timeout header (PHP:
//! `src/Appwrite/Platform/Modules/Databases/Http/Init/Timeout.php`;
//! hooks.json `database-timeout`), applied when the project database is
//! first opened ([`crate::platform::Context::db`]).

use utopia_database::Database;
use utopia_database::event::Event;
use utopia_http::Request;

use crate::Result;
use crate::platform::State;

/// `x-appwrite-timeout` (the `api` group's database timeout hook):
/// development only.
pub(in crate::platform) fn database_timeout(state: &State, request: &Request, db: &Database) -> Result<()> {
    if state.config.development {
        let timeout = php_std::format::intval(
            php_std::format::Arg::Str(request.header_or_empty("x-appwrite-timeout").as_bytes()),
            10,
        );
        if timeout != 0 {
            db.set_timeout(timeout, Event::All)?;
        }
    }
    Ok(())
}
