require 'Appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

users = Users.new(client)

response = users.create_scrypt_modified_user(user_id: '[USER_ID]', email: 'email@example.com', password: 'password', password_salt: '[PASSWORD_SALT]', password_salt_separator: '[PASSWORD_SALT_SEPARATOR]', password_signer_key: '[PASSWORD_SIGNER_KEY]')

puts response.inspect