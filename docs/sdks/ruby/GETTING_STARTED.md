## Getting started

### Initialize the client

Create a client with your Appwrite endpoint, project ID and an [API key](https://appwrite.io/docs/partners/project/api-keys). Find the endpoint and project ID in your project's **Settings** page, and create an API key with only the scopes you need under **API keys** in the project sidebar.

```ruby
require 'appwrite'

client = Appwrite::Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<PROJECT_ID>')
    .set_key(ENV['APPWRITE_API_KEY'])
```

API keys grant server-level access to your project. Keep them out of client-side code and version control.

### Make a request

Create a service object with the client and call any of its methods. Methods take keyword arguments and return model objects such as `Appwrite::Models::User`.

```ruby
users = Appwrite::Users.new(client)

user = users.create(
    user_id: Appwrite::ID.unique,
    email: 'email@example.com',
    password: 'password',
    name: "Walter O'Brien"
)

puts user.id
puts user.name
```

See the [API reference](https://appwrite.io/docs/references/cloud/server-ruby/users) for every service and method.

### Work with rows

Use `Appwrite::TablesDB` to read and write rows. Row fields are available as a hash through `row.data`.

```ruby
tables_db = Appwrite::TablesDB.new(client)

row = tables_db.create_row(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    row_id: Appwrite::ID.unique,
    data: { title: 'Hello, world', published: true }
)

puts row.id
puts row.data['title']

result = tables_db.list_rows(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    queries: [
        Appwrite::Query.equal('published', true),
        Appwrite::Query.limit(25)
    ]
)

result.rows.each do |row|
    puts row.data['title']
end
```

### Handle errors

Failed requests raise `Appwrite::Exception`, which carries `message`, `code`, `type` and `response`.

```ruby
begin
    user = users.get(user_id: '<USER_ID>')
rescue Appwrite::Exception => error
    puts "#{error.code} #{error.type}: #{error.message}"
end
```

### Self-hosted Appwrite

Point the client at your own instance instead of Appwrite Cloud. Call `set_self_signed` only in development, when the server uses a self-signed TLS certificate.

```ruby
client
    .set_endpoint('https://appwrite.example.com/v1')
    .set_self_signed
```

### Learn more

- [Ruby quick start](https://appwrite.io/docs/quick-starts/ruby)
- [Ruby API reference](https://appwrite.io/docs/references/cloud/server-ruby/users)
- [Appwrite docs](https://appwrite.io/docs)
- [Discord community](https://appwrite.io/discord)
- [Appwrite Ruby playground](https://github.com/appwrite/playground-for-ruby)
