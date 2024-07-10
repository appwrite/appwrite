from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
client.set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

functions = Functions(client)

result = functions.list_deployments(
    function_id = '<FUNCTION_ID>',
    queries = [], # optional
    search = '<SEARCH>' # optional
)
