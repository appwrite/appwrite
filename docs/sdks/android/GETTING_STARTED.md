## Getting Started

### Add your Android Platform
To initialize your SDK and start interacting with Appwrite services, you need to add a new Android platform to your project. To add a new platform, go to your Appwrite console, select your project (create one if you haven't already), and click the 'Add Platform' button on the project Dashboard.

From the options, choose to add a new **Android** platform and add your app credentials.

Add your app <u>name</u> and <u>package name</u>. Your package name is generally the applicationId in your app-level `build.gradle` file. By registering a new platform, you are allowing your app to communicate with the Appwrite API.

### Registering additional activities
In order to capture the Appwrite OAuth callback url, the following activity needs to be added to your [AndroidManifest.xml](https://github.com/appwrite/playground-for-android/blob/master/app/src/main/AndroidManifest.xml). Be sure to replace the **[PROJECT_ID]** string with your actual Appwrite project ID. You can find your Appwrite project ID in your project settings screen in the console.

```xml
<manifest>
    <application>
        <activity android:name="io.appwrite.views.CallbackActivity" >
            <intent-filter android:label="android_web_auth">
                <action android:name="android.intent.action.VIEW" />
                <category android:name="android.intent.category.DEFAULT" />
                <category android:name="android.intent.category.BROWSABLE" />
                <data android:scheme="appwrite-callback-<PROJECT_ID>" />
            </intent-filter>
        </activity>
    </application>
</manifest>
```

### Init your SDK

<p>Initialize your SDK with your Appwrite server API endpoint and project ID, which can be found in your project settings page.

```kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
  .setEndpoint("https://<HOSTNAME_OR_IP>/v1") // Your API Endpoint
  .setProject("<PROJECT_ID>") // Your project ID
  .setSelfSigned(true) // Remove in production
```

Before starting to send any API calls to your new Appwrite instance, make sure your Android emulators has network access to the Appwrite server hostname or IP address.

When trying to connect to Appwrite from an emulator or a mobile device, localhost is the hostname of the device or emulator and not your local Appwrite instance. You should replace localhost with your private IP. You can also use a service like [ngrok](https://ngrok.com/) to proxy the Appwrite API.

### Make Your First Request

<p>Once your SDK object is set, access any of the Appwrite services and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

```kotlin
// Register User
val account = Account(client)
val response = account.create(
    ID.unique(),
    "email@example.com",
    "password",
    "Walter O'Brien"
)
```

### Full Example

```kotlin
import io.appwrite.Client
import io.appwrite.services.Account
import io.appwrite.ID

val client = Client(context)
  .setEndpoint("https://<HOSTNAME_OR_IP>/v1") // Your API Endpoint
  .setProject("<PROJECT_ID>") // Your project ID
  .setSelfSigned(true) // Remove in production

val account = Account(client)
val user = account.create(
    ID.unique(),
    "email@example.com",
    "password",
    "Walter O'Brien"
)
```

### Type Safety with Models

The Appwrite Android SDK provides type safety when working with database documents through generic methods. Methods like `listDocuments`, `getDocument`, and others accept a `nestedType` parameter that allows you to specify your custom model type for full type safety.

**Kotlin:**
```kotlin
data class Book(
    val name: String,
    val author: String,
    val releaseYear: String? = null,
    val category: String? = null,
    val genre: List<String>? = null,
    val isCheckedOut: Boolean
)

val databases = Databases(client)

try {
    val documents = databases.listDocuments(
        databaseId = "your-database-id",
        collectionId = "your-collection-id",
        nestedType = Book::class.java // Pass in your custom model type
    )
    
    for (book in documents.documents) {
        Log.d("Appwrite", "Book: ${book.name} by ${book.author}") // Now you have full type safety
    }
} catch (e: AppwriteException) {
    Log.e("Appwrite", e.message ?: "Unknown error")
}
```

**Java:**
```java
public class Book {
    private String name;
    private String author;
    private String releaseYear;
    private String category;
    private List<String> genre;
    private boolean isCheckedOut;

    // Constructor
    public Book(String name, String author, boolean isCheckedOut) {
        this.name = name;
        this.author = author;
        this.isCheckedOut = isCheckedOut;
    }

    // Getters and setters
    public String getName() { return name; }
    public void setName(String name) { this.name = name; }
    
    public String getAuthor() { return author; }
    public void setAuthor(String author) { this.author = author; }
    
    public String getReleaseYear() { return releaseYear; }
    public void setReleaseYear(String releaseYear) { this.releaseYear = releaseYear; }
    
    public String getCategory() { return category; }
    public void setCategory(String category) { this.category = category; }
    
    public List<String> getGenre() { return genre; }
    public void setGenre(List<String> genre) { this.genre = genre; }
    
    public boolean isCheckedOut() { return isCheckedOut; }
    public void setCheckedOut(boolean checkedOut) { isCheckedOut = checkedOut; }
}

Databases databases = new Databases(client);

try {
    DocumentList<Book> documents = databases.listDocuments(
        "your-database-id",
        "your-collection-id",
        Book.class // Pass in your custom model type
    );
    
    for (Book book : documents.getDocuments()) {
        Log.d("Appwrite", "Book: " + book.getName() + " by " + book.getAuthor()); // Now you have full type safety
    }
} catch (AppwriteException e) {
    Log.e("Appwrite", e.getMessage() != null ? e.getMessage() : "Unknown error");
}
```

**Tip**: You can use the `appwrite types` command to automatically generate model definitions based on your Appwrite database schema. Learn more about [type generation](https://appwrite.io/docs/products/databases/type-generation).

### Working with Model Methods

All Appwrite models come with built-in methods for data conversion and manipulation:

**`toMap()`** - Converts a model instance to a Map format, useful for debugging or manual data manipulation:
```kotlin
val account = Account(client)
val user = account.get()
val userMap = user.toMap()
Log.d("Appwrite", userMap.toString()) // Prints all user properties as a Map
```

**`from(map:, nestedType:)`** - Creates a model instance from a Map, useful when working with raw data:
```kotlin
val userData: Map<String, Any> = mapOf(
    "\$id" to "123",
    "name" to "John",
    "email" to "john@example.com"
)
val user = User.from(userData, User::class.java)
```

**JSON Serialization** - Models can be easily converted to/from JSON using Gson (which the SDK uses internally):
```kotlin
import com.google.gson.Gson

val account = Account(client)
val user = account.get()

// Convert to JSON
val gson = Gson()
val jsonString = gson.toJson(user)
Log.d("Appwrite", "User JSON: $jsonString")

// Convert from JSON
val userFromJson = gson.fromJson(jsonString, User::class.java)
```

### Error Handling

The Appwrite Android SDK raises an `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching `AppwriteException` and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```kotlin
try {
    var user = account.create(ID.unique(),"email@example.com","password","Walter O'Brien")
    Log.d("Appwrite user", user.toMap())
} catch(e : AppwriteException) {
    e.printStackTrace()
}
```

### Learn more
You can use the following resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-android)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Android Playground](https://github.com/appwrite/playground-for-android)
