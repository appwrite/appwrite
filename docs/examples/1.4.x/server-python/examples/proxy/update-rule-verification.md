from appwrite.client import Client
from appwrite.services.proxy import Proxy

client = Client()

(client
  .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('5df5acd0d48c2') # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
)

proxy = Proxy(client)

result = proxy.update_rule_verification('[RULE_ID]')
