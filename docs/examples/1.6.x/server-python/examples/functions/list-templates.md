from appwrite.client import Client

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID

functions = Functions(client)

result = functions.list_templates(
    runtimes = [], # optional
    use_cases = [], # optional
    limit = 1, # optional
    offset = 0 # optional
)
