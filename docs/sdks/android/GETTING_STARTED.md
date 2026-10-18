## Getting Started

### Add your Android platform

To let your app talk to Appwrite, register it as a platform. In the [Appwrite Console](https://cloud.appwrite.io/console), open your project (create one if you haven't already), click **Add platform** and choose **Android**.

Enter your app's name and package name. The package name is the `applicationId` in your app module's `build.gradle.kts` file.

### Init your SDK

Initialize the client with your Appwrite endpoint and project ID, both of which are on your project's settings page.

```kotlin
import io.appwrite.Client

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
```

If you self-host Appwrite, use your own endpoint instead, such as `https://appwrite.example.com/v1`. On the Android emulator, `localhost` is the emulator itself: use `10.0.2.2` to reach your development machine, or a tunnel such as [ngrok](https://ngrok.com/). Call `.setSelfSigned(true)` only while developing against a server with a self-signed certificate.

### Make your first request

Service methods are `suspend` functions, so call them from a coroutine, for example `lifecycleScope` in an activity:

```kotlin
import androidx.lifecycle.lifecycleScope
import io.appwrite.ID
import io.appwrite.services.Account
import kotlinx.coroutines.launch

val account = Account(client)

lifecycleScope.launch {
    val user = account.create(
        userId = ID.unique(),
        email = "email@example.com",
        password = "password",
        name = "Walter O'Brien",
    )
}
```

### Error handling

Failed requests throw an `AppwriteException` with `message`, `code`, `type` and `response` properties:

```kotlin
import android.util.Log
import io.appwrite.exceptions.AppwriteException

lifecycleScope.launch {
    try {
        val user = account.get()
        Log.d("Appwrite", user.name)
    } catch (e: AppwriteException) {
        Log.e("Appwrite", "${e.code} ${e.type}: ${e.message}")
    }
}
```

### OAuth login

To create OAuth sessions with `createOAuth2Session` or `createOAuth2Token`, add the SDK's callback activity inside the `<application>` tag of your `AndroidManifest.xml`. Replace `<PROJECT_ID>` with your Appwrite project ID, which you can find in your project settings.

```xml
<activity android:name="io.appwrite.views.CallbackActivity" android:exported="true">
    <intent-filter android:label="android_web_auth">
        <action android:name="android.intent.action.VIEW" />
        <category android:name="android.intent.category.DEFAULT" />
        <category android:name="android.intent.category.BROWSABLE" />
        <data android:scheme="appwrite-callback-<PROJECT_ID>" />
    </intent-filter>
</activity>
```

Then start the flow from your activity:

```kotlin
import io.appwrite.enums.OAuthProvider

lifecycleScope.launch {
    account.createOAuth2Session(
        activity = this@MainActivity,
        provider = OAuthProvider.GITHUB,
    )
}
```

### Using the SDK from Java

From Java, pass a `CoroutineCallback` as the last argument instead of calling the method from a coroutine. Request failures arrive as the callback's `error`; most methods also declare `AppwriteException`, which Java treats as checked, for invalid arguments. The callback runs on a background thread, so switch to the main thread before touching views.

```java
import android.util.Log;

import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.exceptions.AppwriteException;
import io.appwrite.services.Account;

Account account = new Account(client);

try {
    account.get(new CoroutineCallback<>((user, error) -> {
        if (error != null) {
            Log.e("Appwrite", error.getMessage());
            return;
        }

        Log.d("Appwrite", user.getName());
    }));
} catch (AppwriteException e) {
    Log.e("Appwrite", e.getMessage());
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
    Log.d("Appwrite", "${book.name} by ${book.author}")
}
```

From Java, pass the class before the callback:

```java
tablesDB.listRows(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    Book.class,
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            Log.e("Appwrite", error.getMessage());
            return;
        }

        for (Row<Book> row : result.getRows()) {
            Log.d("Appwrite", row.getData().getName());
        }
    })
);
```

Use `appwrite types` from the [Appwrite CLI](https://appwrite.io/docs/tooling/command-line/installation) to generate these model classes from your database schema. Learn more about [type generation](https://appwrite.io/docs/products/databases/tablesdb/type-generation).

Every model also has `toMap()`, which is handy for logging or manual data handling:

```kotlin
val user = account.get()
Log.d("Appwrite", user.toMap().toString())
```
