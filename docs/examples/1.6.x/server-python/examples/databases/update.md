from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('&lt;YOUR_PROJECT_ID&gt;') # Your project ID
client.set_key('&lt;YOUR_API_KEY&gt;') # Your secret API key

databases = Databases(client)

result = databases.update(
    database_id = '<DATABASE_ID>',
    name = '<NAME>',
    enabled = False # optional
)
