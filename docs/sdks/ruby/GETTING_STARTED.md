## Getting Started

### Init your SDK
Initialize your SDK code with your project ID which can be found in your project settings page and your new API secret Key from project's API keys section.

```ruby
require 'appwrite'

client = Appwrite::Client.new()

client
    .set_endpoint(ENV["APPWRITE_ENDPOINT"]) # Your API Endpoint
    .set_project(ENV["APPWRITE_PROJECT"]) # Your project ID
    .set_key(ENV["APPWRITE_SECRET"]) # Your secret API key
;
```

### Make Your First Request
Once your SDK object is set, create any of the Appwrite service objects and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the API References section.

```ruby
users = Appwrite::Users.new(client);

result = users.create(email: 'email@example.com', password: 'password');
```

### Full Example
```ruby
require 'appwrite'

client = Appwrite::Client.new()

client
    .set_endpoint(ENV["APPWRITE_ENDPOINT"]) # Your API Endpoint
    .set_project(ENV["APPWRITE_PROJECT"]) # Your project ID
    .set_key(ENV["APPWRITE_SECRET"]) # Your secret API key
;

users = Appwrite::Users.new(client);

result = users.create(email: 'email@example.com', password: 'password');
```

### Learn more
You can use followng resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Ruby Playground](https://github.com/appwrite/playground-for-ruby)
