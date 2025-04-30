require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://example.com/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

health = Health.new(client)

result = health.get_queue_stats_usage_dump(
    threshold: null # optional
)
