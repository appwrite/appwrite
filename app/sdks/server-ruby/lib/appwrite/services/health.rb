module Appwrite
    class Health < Service

        def get()
            path = '/health'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_anti_virus()
            path = '/health/anti-virus'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_cache()
            path = '/health/cache'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_d_b()
            path = '/health/db'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_queue_certificates()
            path = '/health/queue/certificates'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_queue_functions()
            path = '/health/queue/functions'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_queue_logs()
            path = '/health/queue/logs'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_queue_tasks()
            path = '/health/queue/tasks'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_queue_usage()
            path = '/health/queue/usage'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_queue_webhooks()
            path = '/health/queue/webhooks'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_storage_local()
            path = '/health/storage/local'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_time()
            path = '/health/time'

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end


        protected

        private
    end 
end