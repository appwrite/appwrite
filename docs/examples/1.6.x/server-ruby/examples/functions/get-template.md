require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID

functions = Functions.new(client)

result = functions.get_template(
    template_id: '<TEMPLATE_ID>'
)
