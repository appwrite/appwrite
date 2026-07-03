## Getting Started

### Init your SDK
Initialize your SDK with your Appwrite server API endpoint and project ID which can be found on your project settings page and your new API secret Key from project's API keys section.

```rust
use appwrite::client::Client;

let client = Client::new()
    .set_endpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .set_project("5df5acd0d48c2") // Your project ID
    .set_key("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key
    .set_self_signed(true); // Use only on dev mode with a self-signed SSL cert
```

### Make Your First Request
Once your SDK object is set, create any of the Appwrite service objects and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

```rust
use appwrite::client::Client;
use appwrite::services::users::Users;
use appwrite::id::ID;

let client = Client::new()
    .set_endpoint("https://[HOSTNAME_OR_IP]/v1")
    .set_project("5df5acd0d48c2")
    .set_key("919c2d18fb5d4...a2ae413da83346ad2")
    .set_self_signed(true);

let users = Users::new(&client);

let user = users.create(
    ID::unique(),
    Some("email@example.com"),
    Some("+123456789"),
    Some("password"),
    Some("Walter O'Brien"),
).await?;

println!("{}", user.name);
println!("{}", user.email);
```

### Error Handling
The Appwrite Rust SDK returns `Result` types. You can handle errors using standard Rust error handling patterns. Below is an example.

```rust
use appwrite::error::AppwriteError;

match users.create(
    ID::unique(),
    Some("email@example.com"),
    Some("+123456789"),
    Some("password"),
    Some("Walter O'Brien"),
).await {
    Ok(user) => println!("{}", user.name),
    Err(AppwriteError { message, code, .. }) => {
        eprintln!("Error {}: {}", code, message);
    }
}
```

### Learn more
You can use the following resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
