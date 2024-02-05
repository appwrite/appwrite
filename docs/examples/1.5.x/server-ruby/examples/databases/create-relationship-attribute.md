require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('5df5acd0d48c2') # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

databases = Databases.new(client)

response = databases.create_relationship_attribute(database_id: '[DATABASE_ID]', collection_id: '[COLLECTION_ID]', related_collection_id: '[RELATED_COLLECTION_ID]', type: RelationshipType::ONE_TO_ONE)

puts response.inspect
