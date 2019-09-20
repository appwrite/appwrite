require 'net/http'
require 'uri'
require 'json'

module Appwrite
    class Client
        
        METHOD_GET = 'GET'
        METHOD_POST = 'POST'
        METHOD_PUT = 'PUT'
        METHOD_PATCH = 'PATCH'
        METHOD_DELETE = 'DELETE'
        METHOD_HEAD = 'HEAD'
        METHOD_OPTIONS = 'OPTIONS'
        METHOD_CONNECT = 'CONNECT'
        METHOD_TRACE = 'TRACE'

        def initialize()
            @headers = {
                'content-type': '',
                'user-agent': RUBY_PLATFORM + ':ruby-' + RUBY_VERSION,
                'x-sdk-version': 'appwrite:ruby:v1.0.0'
            }
            @endpoint = 'https://appwrite.test/v1';
        end

        def set_project(value)
            add_header('x-appwrite-project', value)

            return self
        end

        def set_locale(value)
            add_header('x-appwrite-locale', value)

            return self
        end

        def set_mode(value)
            add_header('x-appwrite-mode', value)

            return self
        end

        def set_key(value)
            add_header('x-appwrite-key', value)

            return self
        end

        def set_endpoint(endpoint)
            @endpoint = endpoint
            
            return self
        end
        
        def add_header(key, value)
            @headers[key.downcase] = value.downcase

            return self
        end
        
        def call(method, path = '', headers = {}, params = {})
            uri = URI.parse(@endpoint + path + ((method == METHOD_GET) ? '?' + URI.encode_www_form(params) : ''))
            http = Net::HTTP.new(uri.host, uri.port)
            http.use_ssl = (uri.scheme == 'https')
            
            headers = @headers.merge(headers)

            case headers[:'content-type'][0, headers[:'content-type'].index(';') || headers[:'content-type'].length]
                when 'application/json'
                    params = params.to_json
                else
                    params = URI.encode_www_form(params)
            end

            begin
                response = http.send_request(method.upcase, uri.request_uri, '', @headers)
            rescue => error
                raise 'Request Failed: '  + error.message
            end

            puts response['content-type']
        end

        protected

        private
    end 
end