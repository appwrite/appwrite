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
            }, params);
        end

        def create_team(name:, roles: ["owner"])
            path = '/teams'

            params = {
                'name': name, 
                'roles': roles
            }

            return @client.call('post', path, {
            }, params);
        end

        def get_team(team_id:)
            path = '/teams/{teamId}'
                .gsub('{team_id}', team_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def update_team(team_id:, name:)
            path = '/teams/{teamId}'
                .gsub('{team_id}', team_id)

            params = {
                'name': name
            }

            return @client.call('put', path, {
            }, params);
        end

        def delete_team(team_id:)
            path = '/teams/{teamId}'
                .gsub('{team_id}', team_id)

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end

        def get_team_members(team_id:)
            path = '/teams/{teamId}/members'
                .gsub('{team_id}', team_id)

            params = {
            }

            return @client.call('get', path, {
            }, params);
        end

        def create_team_membership(team_id:, email:, roles:, redirect:, name: '')
            path = '/teams/{teamId}/memberships'
                .gsub('{team_id}', team_id)

            params = {
                'email': email, 
                'name': name, 
                'roles': roles, 
                'redirect': redirect
            }

            return @client.call('post', path, {
            }, params);
        end

        def delete_team_membership(team_id:, invite_id:)
            path = '/teams/{teamId}/memberships/{inviteId}'
                .gsub('{team_id}', team_id)
                .gsub('{invite_id}', invite_id)

            params = {
            }

            return @client.call('delete', path, {
            }, params);
        end

        def create_team_membership_resend(team_id:, invite_id:, redirect:)
            path = '/teams/{teamId}/memberships/{inviteId}/resend'
                .gsub('{team_id}', team_id)
                .gsub('{invite_id}', invite_id)

            params = {
                'redirect': redirect
            }

            return @client.call('post', path, {
            }, params);
        end

        def update_team_membership_status(team_id:, invite_id:, user_id:, secret:, success: '', failure: '')
            path = '/teams/{teamId}/memberships/{inviteId}/status'
                .gsub('{team_id}', team_id)
                .gsub('{invite_id}', invite_id)

            params = {
                'userId': user_id, 
                'secret': secret, 
                'success': success, 
                'failure': failure
            }

            return @client.call('patch', path, {
            }, params);
        end


        protected

        private
    end 
end