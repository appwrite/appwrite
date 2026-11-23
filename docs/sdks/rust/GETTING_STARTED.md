## Getting Started

### Init your SDK

Create a client with your Appwrite API endpoint, project ID and an API key. You can find the project ID in your project settings, and create an API key with the scopes you need in your project's API keys section.

```rust
use appwrite::Client;

let client = Client::new()
    .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
    .set_project("<YOUR_PROJECT_ID>")
    .set_key("<YOUR_API_KEY>");
```

If you self-host Appwrite, use your own endpoint, such as `https://<HOSTNAME_OR_IP>/v1`. Add `.set_self_signed(true)` only in development, when your server uses a self-signed certificate.

### Make your first request

Create a service from the client and call any of its methods. Every method is `async` and returns `appwrite::Result<T>`. Optional parameters take an `Option`; pass `None` to skip one.

```rust
use appwrite::id::ID;
use appwrite::services::Users;
use appwrite::Client;

#[tokio::main]
async fn main() -> appwrite::Result<()> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<YOUR_PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let users = Users::new(&client);

    let user = users
        .create(
            ID::unique(),
            Some("email@example.com"),
            None,
            Some("password"),
            Some("Walter O'Brien"),
        )
        .await?;

    println!("{} {} {}", user.id, user.name, user.email);

    Ok(())
}
```

### Error handling

Failed requests return an `AppwriteError` with the HTTP status `code`, the `message`, the error type and the raw `response` body.

```rust
match users
    .create(ID::unique(), Some("email@example.com"), None, None, None)
    .await
{
    Ok(user) => println!("{}", user.name),
    Err(error) => eprintln!(
        "Appwrite error {} ({}): {}",
        error.code,
        error.get_type().unwrap_or("unknown"),
        error.message
    ),
}
```

### Learn more

- [Rust quick start](https://appwrite.io/docs/quick-starts/rust)
- [Rust API reference](https://appwrite.io/docs/references/cloud/server-rust)
- [Crate documentation](https://docs.rs/appwrite)
- [Appwrite docs](https://appwrite.io/docs)
- [Discord community](https://appwrite.io/discord)
