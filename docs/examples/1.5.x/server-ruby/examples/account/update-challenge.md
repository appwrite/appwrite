require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_session('') # The user session to authenticate with

account = Account.new(client)

result = account.update_challenge(
    challenge_id: '<CHALLENGE_ID>',
    otp: '<OTP>'
)
