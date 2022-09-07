## Getting Started

### Init your SDK
Initialize your SDK with your Appwrite server API endpoint and project ID which can be found in your project settings page and your new API secret Key from project's API keys section.

```ruby
require 'appwrite'

client = Appwrite::Client.new()

client
    .set_endpoint(ENV["APPWRITE_ENDPOINT"]) # Your API Endpoint
    .set_project(ENV["APPWRITE_PROJECT"]) # Your project ID
    .set_key(ENV["APPWRITE_SECRET"]) # Your secret API key
    .setSelfSigned() # Use only on dev mode with a self-signed SSL cert
;
```

### Make Your First Request
Once your SDK object is set, create any of the Appwrite service objects and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

```ruby
users = Appwrite::Users.new(client);

user = users.create(userId: Appwrite::ID::unique(), email: 'email@example.com', password: 'password');
```

### Full Example
```ruby
require 'appwrite'

client = Appwrite::Client.new()

client
    .set_endpoint(ENV["APPWRITE_ENDPOINT"]) # Your API Endpoint
    .set_project(ENV["APPWRITE_PROJECT"]) # Your project ID
    .set_key(ENV["APPWRITE_SECRET"]) # Your secret API key
    .setSelfSigned() # Use only on dev mode with a self-signed SSL cert
;

users = Appwrite::Users.new(client);

user = users.create(userId: Appwrite::ID::unique(), email: 'email@example.com', password: 'password');
```

### Error Handling
The Appwrite Ruby SDK raises `Appwrite::Exception` object with `message`, `code` and `response` properties. You can handle any errors by catching `Appwrite::Exception` and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```ruby
users = Appwrite::Users.new(client);

begin
    user = users.create(userId: Appwrite::ID::unique(), email: 'email@example.com', password: 'password');
rescue Appwrite::Exception => error
    puts error.message
end
```

### Learn more
You can use the following resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Ruby Playground](https://github.com/appwrite/playground-for-ruby)
