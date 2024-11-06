require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID

functions = Functions.new(client)

result = functions.get_template(
    template_id: '<TEMPLATE_ID>'
)
