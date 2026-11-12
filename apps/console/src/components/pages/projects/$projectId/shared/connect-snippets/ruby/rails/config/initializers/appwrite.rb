APPWRITE_CLIENT = Appwrite::Client.new
  .set_endpoint(ENV['APPWRITE_ENDPOINT'])
  .set_project(ENV['APPWRITE_PROJECT_ID'])
  .set_key(ENV['APPWRITE_API_KEY'])
