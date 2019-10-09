import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Teams extends Service {
     
     Teams(Client client): super(client);

     /// /docs/references/teams/list-teams.md
    Future<Response> listTeams({search = null, limit = 25, offset = null, orderType = 'ASC'}) async {
       String path = '/teams';

       Map<String, dynamic> params = {
         'search': search,
         'limit': limit,
         'offset': offset,
         'orderType': orderType,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/teams/create-team.md
    Future<Response> createTeam({name, roles = const ["owner"]}) async {
       String path = '/teams';

       Map<String, dynamic> params = {
         'name': name,
         'roles': roles,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/teams/get-team.md
    Future<Response> getTeam({teamId}) async {
       String path = '/teams/{teamId}'.replaceAll(RegExp('{teamId}'), teamId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/teams/update-team.md
    Future<Response> updateTeam({teamId, name}) async {
       String path = '/teams/{teamId}'.replaceAll(RegExp('{teamId}'), teamId);

       Map<String, dynamic> params = {
         'name': name,
       };

       return await this.client.call('put', path: path, params: params);
    }
     /// /docs/references/teams/delete-team.md
    Future<Response> deleteTeam({teamId}) async {
       String path = '/teams/{teamId}'.replaceAll(RegExp('{teamId}'), teamId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// /docs/references/teams/get-team-members.md
    Future<Response> getTeamMembers({teamId}) async {
       String path = '/teams/{teamId}/members'.replaceAll(RegExp('{teamId}'), teamId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/teams/create-team-membership.md
    Future<Response> createTeamMembership({teamId, email, roles, redirect, name = null}) async {
       String path = '/teams/{teamId}/memberships'.replaceAll(RegExp('{teamId}'), teamId);

       Map<String, dynamic> params = {
         'email': email,
         'name': name,
         'roles': roles,
         'redirect': redirect,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/teams/delete-team-membership.md
    Future<Response> deleteTeamMembership({teamId, inviteId}) async {
       String path = '/teams/{teamId}/memberships/{inviteId}'.replaceAll(RegExp('{teamId}'), teamId).replaceAll(RegExp('{inviteId}'), inviteId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// /docs/references/teams/create-team-membership-resend.md
    Future<Response> createTeamMembershipResend({teamId, inviteId, redirect}) async {
       String path = '/teams/{teamId}/memberships/{inviteId}/resend'.replaceAll(RegExp('{teamId}'), teamId).replaceAll(RegExp('{inviteId}'), inviteId);

       Map<String, dynamic> params = {
         'redirect': redirect,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/teams/update-team-membership-status.md
    Future<Response> updateTeamMembershipStatus({teamId, inviteId, userId, secret, success = null, failure = null}) async {
       String path = '/teams/{teamId}/memberships/{inviteId}/status'.replaceAll(RegExp('{teamId}'), teamId).replaceAll(RegExp('{inviteId}'), inviteId);

       Map<String, dynamic> params = {
         'userId': userId,
         'secret': secret,
         'success': success,
         'failure': failure,
       };

       return await this.client.call('patch', path: path, params: params);
    }
}