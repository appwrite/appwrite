require 'Appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://[HOSTNAME_OR_IP]/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_jwt('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...') # Your secret JSON Web Token

account = Account.new(client)

response = account.update_phone(phone: '+12065550100', password: 'password')

puts response.inspect