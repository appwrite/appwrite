module Appwrite
    class Projects < Service

        def list_projects()
            path = '/projects'

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def create_project(name:, team_id:, description: '', logo: '', url: '', clients: [], legal_name: '', legal_country: '', legal_state: '', legal_city: '', legal_address: '', legal_tax_id: '')
            path = '/projects'

            params = {
                'name': name, 
                'teamId': team_id, 
                'description': description, 
                'logo': logo, 
                'url': url, 
                'clients': clients, 
                'legalName': legal_name, 
                'legalCountry': legal_country, 
                'legalState': legal_state, 
                'legalCity': legal_city, 
                'legalAddress': legal_address, 
                'legalTaxId': legal_tax_id
            }

            return @client.call('post', path, {
            }, params);
        end

        def get_project(project_id:)
            path = '/projects/{projectId}'
                .gsub('{project_id}', project_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def update_project(project_id:, name:, description: '', logo: '', url: '', clients: [], legal_name: '', legal_country: '', legal_state: '', legal_city: '', legal_address: '', legal_tax_id: '')
            path = '/projects/{projectId}'
                .gsub('{project_id}', project_id)

            params = {
                'name': name, 
                'description': description, 
                'logo': logo, 
                'url': url, 
                'clients': clients, 
                'legalName': legal_name, 
                'legalCountry': legal_country, 
                'legalState': legal_state, 
                'legalCity': legal_city, 
                'legalAddress': legal_address, 
                'legalTaxId': legal_tax_id
            }

            return @client.call('patch', path, {
            }, params);
        end

        def delete_project(project_id:)
            path = '/projects/{projectId}'
                .gsub('{project_id}', project_id)

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end

        def list_keys(project_id:)
            path = '/projects/{projectId}/keys'
                .gsub('{project_id}', project_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def create_key(project_id:, name:, scopes:)
            path = '/projects/{projectId}/keys'
                .gsub('{project_id}', project_id)

            params = {
                'name': name, 
                'scopes': scopes
            }

            return @client.call('post', path, {
            }, params);
        end

        def get_key(project_id:, key_id:)
            path = '/projects/{projectId}/keys/{keyId}'
                .gsub('{project_id}', project_id)
                .gsub('{key_id}', key_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def update_key(project_id:, key_id:, name:, scopes:)
            path = '/projects/{projectId}/keys/{keyId}'
                .gsub('{project_id}', project_id)
                .gsub('{key_id}', key_id)

            params = {
                'name': name, 
                'scopes': scopes
            }

            return @client.call('put', path, {
            }, params);
        end

        def delete_key(project_id:, key_id:)
            path = '/projects/{projectId}/keys/{keyId}'
                .gsub('{project_id}', project_id)
                .gsub('{key_id}', key_id)

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end

        def update_project_o_auth(project_id:, provider:, app_id: '', secret: '')
            path = '/projects/{projectId}/oauth'
                .gsub('{project_id}', project_id)

            params = {
                'provider': provider, 
                'appId': app_id, 
                'secret': secret
            }

            return @client.call('patch', path, {
            }, params);
        end

        def list_platforms(project_id:)
            path = '/projects/{projectId}/platforms'
                .gsub('{project_id}', project_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def create_platform(project_id:, type:, name:, key: '', store: '', url: '')
            path = '/projects/{projectId}/platforms'
                .gsub('{project_id}', project_id)

            params = {
                'type': type, 
                'name': name, 
                'key': key, 
                'store': store, 
                'url': url
            }

            return @client.call('post', path, {
            }, params);
        end

        def get_platform(project_id:, platform_id:)
            path = '/projects/{projectId}/platforms/{platformId}'
                .gsub('{project_id}', project_id)
                .gsub('{platform_id}', platform_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def update_platform(project_id:, platform_id:, name:, key: '', store: '', url: '[]')
            path = '/projects/{projectId}/platforms/{platformId}'
                .gsub('{project_id}', project_id)
                .gsub('{platform_id}', platform_id)

            params = {
                'name': name, 
                'key': key, 
                'store': store, 
                'url': url
            }

            return @client.call('put', path, {
            }, params);
        end

        def delete_platform(project_id:, platform_id:)
            path = '/projects/{projectId}/platforms/{platformId}'
                .gsub('{project_id}', project_id)
                .gsub('{platform_id}', platform_id)

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end

        def list_tasks(project_id:)
            path = '/projects/{projectId}/tasks'
                .gsub('{project_id}', project_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def create_task(project_id:, name:, status:, schedule:, security:, http_method:, http_url:, http_headers: [], http_user: '', http_pass: '')
            path = '/projects/{projectId}/tasks'
                .gsub('{project_id}', project_id)

            params = {
                'name': name, 
                'status': status, 
                'schedule': schedule, 
                'security': security, 
                'httpMethod': http_method, 
                'httpUrl': http_url, 
                'httpHeaders': http_headers, 
                'httpUser': http_user, 
                'httpPass': http_pass
            }

            return @client.call('post', path, {
            }, params);
        end

        def get_task(project_id:, task_id:)
            path = '/projects/{projectId}/tasks/{taskId}'
                .gsub('{project_id}', project_id)
                .gsub('{task_id}', task_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def update_task(project_id:, task_id:, name:, status:, schedule:, security:, http_method:, http_url:, http_headers: [], http_user: '', http_pass: '')
            path = '/projects/{projectId}/tasks/{taskId}'
                .gsub('{project_id}', project_id)
                .gsub('{task_id}', task_id)

            params = {
                'name': name, 
                'status': status, 
                'schedule': schedule, 
                'security': security, 
                'httpMethod': http_method, 
                'httpUrl': http_url, 
                'httpHeaders': http_headers, 
                'httpUser': http_user, 
                'httpPass': http_pass
            }

            return @client.call('put', path, {
            }, params);
        end

        def delete_task(project_id:, task_id:)
            path = '/projects/{projectId}/tasks/{taskId}'
                .gsub('{project_id}', project_id)
                .gsub('{task_id}', task_id)

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end

        def get_project_usage(project_id:)
            path = '/projects/{projectId}/usage'
                .gsub('{project_id}', project_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def list_webhooks(project_id:)
            path = '/projects/{projectId}/webhooks'
                .gsub('{project_id}', project_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def create_webhook(project_id:, name:, events:, url:, security:, http_user: '', http_pass: '')
            path = '/projects/{projectId}/webhooks'
                .gsub('{project_id}', project_id)

            params = {
                'name': name, 
                'events': events, 
                'url': url, 
                'security': security, 
                'httpUser': http_user, 
                'httpPass': http_pass
            }

            return @client.call('post', path, {
            }, params);
        end

        def get_webhook(project_id:, webhook_id:)
            path = '/projects/{projectId}/webhooks/{webhookId}'
                .gsub('{project_id}', project_id)
                .gsub('{webhook_id}', webhook_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def update_webhook(project_id:, webhook_id:, name:, events:, url:, security:, http_user: '', http_pass: '')
            path = '/projects/{projectId}/webhooks/{webhookId}'
                .gsub('{project_id}', project_id)
                .gsub('{webhook_id}', webhook_id)

            params = {
                'name': name, 
                'events': events, 
                'url': url, 
                'security': security, 
                'httpUser': http_user, 
                'httpPass': http_pass
            }

            return @client.call('put', path, {
            }, params);
        end

        def delete_webhook(project_id:, webhook_id:)
            path = '/projects/{projectId}/webhooks/{webhookId}'
                .gsub('{project_id}', project_id)
                .gsub('{webhook_id}', webhook_id)

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end


        protected

        private
    end 
end