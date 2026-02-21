require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

messaging = Messaging.new(client)

result = messaging.update_smtp_provider(
    provider_id: '<PROVIDER_ID>',
    name: '<NAME>', # optional
    host: '<HOST>', # optional
    port: 1, # optional
    username: '<USERNAME>', # optional
    password: '<PASSWORD>', # optional
    encryption: SmtpEncryption::NONE, # optional
    auto_tls: false, # optional
    mailer: '<MAILER>', # optional
    from_name: '<FROM_NAME>', # optional
    from_email: 'email@example.com', # optional
    reply_to_name: '<REPLY_TO_NAME>', # optional
    reply_to_email: '<REPLY_TO_EMAIL>', # optional
    enabled: false # optional
)
