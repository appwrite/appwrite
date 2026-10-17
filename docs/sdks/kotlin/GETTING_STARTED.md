## Getting Started

### Init your SDK

Initialize the client with your Appwrite endpoint, project ID and an API key. The endpoint and project ID are on your project's settings page; create an [API key](https://appwrite.io/docs/advanced/security/api-keys) with only the scopes your code needs from **API Keys** in the project sidebar of the [Appwrite Console](https://cloud.appwrite.io/console).

```kotlin
import io.appwrite.Client

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<API_KEY>")
```

API keys are secrets that bypass user permissions, so use this SDK only in server-side code and never ship a key in a client app. If you self-host Appwrite, use your own endpoint instead, such as `https://appwrite.example.com/v1`, and call `.setSelfSigned(true)` only while developing against a server with a self-signed certificate.

### Make your first request

Service methods are `suspend` functions, so call them from a coroutine:

```kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.services.Users

suspend fun main() {
    val client = Client()
        .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
        .setProject("<PROJECT_ID>")
        .setKey("<API_KEY>")

    val users = Users(client)

    val user = users.create(
        userId = ID.unique(),
        email = "email@example.com",
        password = "password",
        name = "Walter O'Brien",
    )

    println(user.id)
}
```

### Error handling

Failed requests throw an `AppwriteException` with `message`, `code`, `type` and `response` properties:

```kotlin
import io.appwrite.exceptions.AppwriteException

try {
    val user = users.get(userId = "<USER_ID>")
    println(user.name)
} catch (e: AppwriteException) {
    println("${e.code} ${e.type}: ${e.message}")
}
```

### Using the SDK from Java

From Java, pass a `CoroutineCallback` as the last argument instead of calling the method from a coroutine. Request failures arrive as the callback's `error`; methods also declare `AppwriteException`, which Java treats as checked, for invalid arguments.

```java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.exceptions.AppwriteException;
import io.appwrite.services.Users;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<API_KEY>");

Users users = new Users(client);

try {
    users.get("<USER_ID>", new CoroutineCallback<>((user, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(user.getName());
    }));
} catch (AppwriteException e) {
    e.printStackTrace();
}
```

### Type safety with models

Methods that return rows, such as `listRows` and `getRow`, accept a `nestedType` so that each row's `data` is your own class:

```kotlin
import io.appwrite.services.TablesDB

data class Book(
    val name: String,
    val author: String,
    val releaseYear: String? = null,
    val genre: List<String>? = null,
    val isCheckedOut: Boolean,
)

val tablesDB = TablesDB(client)

val result = tablesDB.listRows(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    nestedType = Book::class.java,
)

for (row in result.rows) {
    val book = row.data
    println("${book.name} by ${book.author}")
}
```

From Java, pass the class before the callback:

```java
try {
    tablesDB.listRows(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        Book.class,
        new CoroutineCallback<>((result, error) -> {
            if (error != null) {
                error.printStackTrace();
                return;
            }

            for (Row<Book> row : result.getRows()) {
                System.out.println(row.getData().getName());
            }
        })
    );
} catch (AppwriteException e) {
    e.printStackTrace();
}
```

Use `appwrite types` from the [Appwrite CLI](https://appwrite.io/docs/tooling/command-line/installation) to generate these model classes from your database schema. Learn more about [type generation](https://appwrite.io/docs/products/databases/tablesdb/type-generation).

Every model also has `toMap()`, which is handy for logging or manual data handling:

```kotlin
val user = users.get(userId = "<USER_ID>")
println(user.toMap())
```
