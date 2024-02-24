from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

messaging = Messaging(client)

result = messaging.update_smtp_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>', # optional
    host = '<HOST>', # optional
    port = 1, # optional
    username = '<USERNAME>', # optional
    password = '<PASSWORD>', # optional
    encryption = SmtpEncryption.NONE, # optional
    auto_tls = False, # optional
    mailer = '<MAILER>', # optional
    from_name = '<FROM_NAME>', # optional
    from_email = 'email@example.com', # optional
    reply_to_name = '<REPLY_TO_NAME>', # optional
    reply_to_email = '<REPLY_TO_EMAIL>', # optional
    enabled = False # optional
)
