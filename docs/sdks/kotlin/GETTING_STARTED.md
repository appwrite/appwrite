## Getting Started

### Init your SDK

Initialize your SDK with your Appwrite server API endpoint and project ID which can be found in your project settings page and your new API secret Key project API keys section.

```kotlin
import io.appwrite.Client
import io.appwrite.services.Account

suspend fun main() {
    val client = Client(context)
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
      .setSelfSigned(true) // Use only on dev mode with a self-signed SSL cert
}
```

### Make Your First Request

Once your SDK object is set, create any of the Appwrite service objects and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

```kotlin
val users = Users(client)
val user = users.create(
    user = ID.unique(),
    email = "email@example.com",
    phone = "+123456789",
    password = "password",
    name = "Walter O'Brien"
)
```

### Full Example

```kotlin
import io.appwrite.Client
import io.appwrite.services.Users
import io.appwrite.ID

suspend fun main() {
    val client = Client(context)
      .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
      .setSelfSigned(true) // Use only on dev mode with a self-signed SSL cert

    val users = Users(client)
    val user = users.create(
        user = ID.unique(),
        email = "email@example.com",
        phone = "+123456789",
        password = "password",
        name = "Walter O'Brien"
    )
}
```

### Type Safety with Models

The Appwrite Kotlin SDK provides type safety when working with database documents through generic methods. Methods like `listDocuments`, `getDocument`, and others accept a `nestedType` parameter that allows you to specify your custom model type for full type safety.

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

The Appwrite Kotlin SDK raises `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching `AppwriteException` and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.services.Users

suspend fun main() {
    val users = Users(client)
    try {
        val user = users.create(
            user = ID.unique(),
            email = "email@example.com",
            phone = "+123456789",
            password = "password",
            name = "Walter O'Brien"
        )
    } catch (e: AppwriteException) {
        e.printStackTrace()
    }
}
```

### Learn more

You can use the following resources to learn more and get help

- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Kotlin Playground](https://github.com/appwrite/playground-for-kotlin)
