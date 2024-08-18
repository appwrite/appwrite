require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID

functions = Functions.new(client)

result = functions.list_templates(
    runtimes: [], # optional
    use_cases: [], # optional
    limit: 1, # optional
    offset: 0 # optional
)
