require 'appwrite'

client = Appwrite::Client.new
  .set_endpoint(ENV['APPWRITE_ENDPOINT'])
  .set_project(ENV['APPWRITE_PROJECT_ID'])
  .set_key(ENV['APPWRITE_API_KEY'])

account = Appwrite::Services::Account.new(client)
user = account.get
puts "Hello, #{user['name']}"
