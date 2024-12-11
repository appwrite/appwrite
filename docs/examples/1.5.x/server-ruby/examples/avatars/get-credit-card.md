require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_session('') # The user session to authenticate with

avatars = Avatars.new(client)

result = avatars.get_credit_card(
    code: CreditCard::AMERICAN_EXPRESS,
    width: 0, # optional
    height: 0, # optional
    quality: 0 # optional
)
