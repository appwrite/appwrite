require 'Appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

users = Users.new(client)

response = users.create_scrypt_user(user_id: '[USER_ID]', email: 'email@example.com', password: 'password', password_salt: '[PASSWORD_SALT]', password_cpu: null, password_memory: null, password_parallel: null, password_length: null)

puts response.inspect