require 'net/http'
require 'uri'
require 'json'

module Appwrite
    class Client
        
        METHOD_GET = 'get'
        METHOD_POST = 'post'
        METHOD_PUT = 'put'
        METHOD_PATCH = 'patch'
        METHOD_DELETE = 'delete'
        METHOD_HEAD = 'head'
        METHOD_OPTIONS = 'options'
        METHOD_CONNECT = 'connect'
        METHOD_TRACE = 'trace'

        def initialize()
            @headers = {
                'content-type' => '',
                'user-agent' => RUBY_PLATFORM + ':ruby-' + RUBY_VERSION,
                'x-sdk-version' => 'appwrite:ruby:1.0.3'
            }
            @endpoint = 'https://appwrite.io/v1';
        end

        def set_project(value)
            add_header('x-appwrite-project', value)

            return self
        end

        def set_key(value)
            add_header('x-appwrite-key', value)

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
            payload = ''
            
            headers = @headers.merge(headers)

            if (method != METHOD_GET)
                case headers['content-type'][0, headers['content-type'].index(';') || headers['content-type'].length]
                    when 'application/json'
                        payload = params.to_json
                    else
                        payload = URI.encode_www_form(params)
                end
            end

            begin
                response = http.send_request(method.upcase, uri.request_uri, payload, headers)
            rescue => error
                raise 'Request Failed: '  + error.message
            end

            return JSON.parse(response.body);
        end

        protected

        private
    end 
end