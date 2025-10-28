from appwrite.client import Client

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_session('') # The user session to authenticate with

account = Account(client)

result = account.update_challenge(
    challenge_id = '<CHALLENGE_ID>',
    otp = '<OTP>'
)
