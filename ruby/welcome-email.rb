require 'json'
require 'http'

# Parsing data
data = JSON.parse(ENV['APPWRITE_FUNCTION_EVENT_DATA'])
name = data["name"]
email = data["email"]

# Using Mailgun API to send email
response = HTTP.basic_auth(:user => 'auth', :pass => '%s' % [ENV['MAILGUN_API_KEY']]).post("https://api.mailgun.net/v3/%s/messages" % [ENV['MAILGUN_DOMAIN']], :form => {'from' => 'Excited User <mailgun@%s>' % [ENV['MAILGUN_DOMAIN']], 'to' => email, 'subject': 'Hello, %' % [name], 'text' => 'Testing some Mailgun awesomness!'})

puts 'response code: %s' % [response.code.to_s]
