# Add new Database Adapter

To get started with implementing a new adapter, start by reviewing the [specification](/SPEC.md) to understand the goals of this library. The specification defines the NoSQL-inspired API methods and contains all of the functions a new adapter must support, including types, queries, paging, indexes, and especially emojis ❤️.. The capabilities of each adapter are defined in the `getSupportFor*` and `get*Limit` methods.

### File Structure

Below are outlined the most useful files for adding a new database adapter: 

```bash
.
├── src # Source code
│   └── Database
│       ├── Adapter/ # Where your new adapter goes!
│       ├── Adapter.php # Parent class for individual adapters
│       ├── Database.php # Database class - calls individual adapter methods
│       ├── Document.php # Document class - 
│       └── Query.php # Query class - holds query attributes, methods, and values
└── tests
    └── Database
        ├── Adapter/ # Extended from Base 
        └── Base.php # Parent class that holds all tests
```


### Extend the Adapter

Create your `NewDB.php` file in `src/Database/Adapter/` and extend the parent class:

```php
<?php

namespace Utopia\Database\Adapter;

use Utopia\Database\Exception;
use Utopia\Database\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

class NewDB extends Adapter
{
```

Only include dependencies strictly necessary for the database, preferably official PHP libraries, if available.

### Testing with Docker 

The existing test suite is helpful when developing a new database adapter. To get started with testing, add a stanza to `docker-compose.yml` with your new database, using existing adapters as examples. Use official Docker images from trusted sources, and provide the necessary `environment` variables for startup. Then, create a new file for your NewDB in `tests/Database/Adapter`, extending the `Base.php` test class. The specific `docker-compose` command for testing can be found in the [README](/README.md#tests).

### Tips and Tricks

- Keep it simple :)
- Databases are namespaced, so name them with `$this->getNamespace()` from the parent Adapter class.
- Create indexes for `$id` and `$permissions` fields by default for query performance.
- Filter new IDs with `$this->filter($id);`
- Prioritize code performance.
- The Query and Queries validators contain the information about which queries the adapters support.
- The [Authorization validator](/src/Database/Validator/Authorization.php) is used to check permissions for searching methods `find()` and `sort()`. Ensure these methods only return documents with the correct `read` permissions.
- The `Database` class has useful constants like types and definitions. Prefer these constants when comparing strings.

#### SQL Databases
- Treat Collections as tables and Documents as rows, with attributes as columns. NoSQL databases are more straight-forward to translate.
- For row-level permissions, create a pair of tables: one for data and one for permissions. The MariaDB adapter demonstrates the implementation.

#### NoSQL Databases
- NoSQL databases may not need to implement the attribute functions. See the MongoDB adapter as an example.