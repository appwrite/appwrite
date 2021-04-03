require 'appwrite'

client = Appwrite::Client.new()

client
    .set_endpoint(ENV["APPWRITE_ENDPOINT"]) # Your API Endpoint
    .set_project(ENV["APPWRITE_PROJECT"]) # Your project ID
    .set_key(ENV["APPWRITE_SECRET"]) # Your secret API key
;

storage = Appwrite::Storage.new(client);

# result = storage.get_file(ENV["APPWRITE_FILEID"]);

puts ENV["APPWRITE_FUNCTION_ID"]
puts ENV["APPWRITE_FUNCTION_NAME"]
puts ENV["APPWRITE_FUNCTION_TAG"]
puts ENV["APPWRITE_FUNCTION_TRIGGER"]
puts ENV["APPWRITE_FUNCTION_ENV_NAME"]
puts ENV["APPWRITE_FUNCTION_ENV_VERSION"]
# puts result["$id"]
puts ENV["APPWRITE_FUNCTION_EVENT"]
puts ENV["APPWRITE_FUNCTION_EVENT_DATA"]