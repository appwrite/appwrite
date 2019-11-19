module Appwrite
    class Avatars < Service

        def get_browser(code:, width: 100, height: 100, quality: 100)
            path = '/avatars/browsers/{code}'
                .gsub('{code}', code)

            params = {
                'width': width, 
                'height': height, 
                'quality': quality
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_credit_card(code:, width: 100, height: 100, quality: 100)
            path = '/avatars/credit-cards/{code}'
                .gsub('{code}', code)

            params = {
                'width': width, 
                'height': height, 
                'quality': quality
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_favicon(url:)
            path = '/avatars/favicon'

            params = {
                'url': url
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_flag(code:, width: 100, height: 100, quality: 100)
            path = '/avatars/flags/{code}'
                .gsub('{code}', code)

            params = {
                'width': width, 
                'height': height, 
                'quality': quality
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_image(url:, width: 400, height: 400)
            path = '/avatars/image'

            params = {
                'url': url, 
                'width': width, 
                'height': height
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_q_r(text:, size: 400, margin: 1, download: 0)
            path = '/avatars/qr'

            params = {
                'text': text, 
                'size': size, 
                'margin': margin, 
                'download': download
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end


        protected

        private
    end 
end