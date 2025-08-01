require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

users = Users.new(client)

result = users.create_scrypt_modified_user(
    user_id: '<USER_ID>',
    email: 'email@example.com',
    password: 'password',
    password_salt: '<PASSWORD_SALT>',
    password_salt_separator: '<PASSWORD_SALT_SEPARATOR>',
    password_signer_key: '<PASSWORD_SIGNER_KEY>',
    name: '<NAME>' # optional
)
