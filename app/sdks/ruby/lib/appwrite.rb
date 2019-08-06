require 'net/http'
require 'uri'
require 'json'
require_relative 'appwrite/client'
require_relative 'appwrite/service'
require_relative 'appwrite/services/account'
require_relative 'appwrite/services/auth'
require_relative 'appwrite/services/avatars'
require_relative 'appwrite/services/database'
require_relative 'appwrite/services/locale'
require_relative 'appwrite/services/projects'
require_relative 'appwrite/services/storage'
require_relative 'appwrite/services/teams'
require_relative 'appwrite/services/users'

module Appwrite

end

client = Appwrite::Client.new()  

client
    .set_endpoint('https://www.walla.co.il')
    .add_header('x', 'y')
    .add_header('z', 'i')
    .call('get', '/info', {}, {'test': 'xxx'})