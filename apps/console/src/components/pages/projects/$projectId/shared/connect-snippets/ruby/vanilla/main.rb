require 'appwrite'
require 'json'

client = Appwrite::Client.new
  .set_endpoint(ENV['APPWRITE_ENDPOINT'])
  .set_project(ENV['APPWRITE_PROJECT_ID'])
  .set_key(ENV['APPWRITE_API_KEY'])

project = Appwrite::Project.new(client)

policy = project.update_password_strength_policy(
  min: 8,
  uppercase: true,
  number: true,
  symbols: true
)

puts JSON.pretty_generate(policy.to_map)

policies = project.list_policies

puts JSON.pretty_generate(policies.to_map)
