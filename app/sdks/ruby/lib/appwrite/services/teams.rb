module Appwrite
    class Teams < Service

        def list_teams(search: '', limit: 25, offset: 0, order_type: 'ASC')
            path = '/teams'

            params = {
                'search': search, 
                'limit': limit, 
                'offset': offset, 
                'orderType': order_type
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def create_team(name:, roles: ["owner"])
            path = '/teams'

            params = {
                'name': name, 
                'roles': roles
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_team(team_id:)
            path = '/teams/{teamId}'
                .gsub('{team_id}', team_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_team(team_id:, name:)
            path = '/teams/{teamId}'
                .gsub('{team_id}', team_id)

            params = {
                'name': name
            }

            return @client.call('put', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_team(team_id:)
            path = '/teams/{teamId}'
                .gsub('{team_id}', team_id)

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def get_team_memberships(team_id:)
            path = '/teams/{teamId}/memberships'
                .gsub('{team_id}', team_id)

            params = {
            }

            return @client.call('get', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def create_team_membership(team_id:, email:, roles:, url:, name: '')
            path = '/teams/{teamId}/memberships'
                .gsub('{team_id}', team_id)

            params = {
                'email': email, 
                'name': name, 
                'roles': roles, 
                'url': url
            }

            return @client.call('post', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def delete_team_membership(team_id:, invite_id:)
            path = '/teams/{teamId}/memberships/{inviteId}'
                .gsub('{team_id}', team_id)
                .gsub('{invite_id}', invite_id)

            params = {
            }

            return @client.call('delete', path, {
                'content-type' => 'application/json',
            }, params);
        end

        def update_team_membership_status(team_id:, invite_id:, user_id:, secret:)
            path = '/teams/{teamId}/memberships/{inviteId}/status'
                .gsub('{team_id}', team_id)
                .gsub('{invite_id}', invite_id)

            params = {
                'userId': user_id, 
                'secret': secret
            }

            return @client.call('patch', path, {
                'content-type' => 'application/json',
            }, params);
        end


        protected

        private
    end 
end