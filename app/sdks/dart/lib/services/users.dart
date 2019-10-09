import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Users extends Service {
     
     Users(Client client): super(client);

     /// /docs/references/users/list-users.md
    Future<Response> listUsers({search = null, limit = 25, offset = null, orderType = 'ASC'}) async {
       String path = '/users';

       Map<String, dynamic> params = {
         'search': search,
         'limit': limit,
         'offset': offset,
         'orderType': orderType,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/users/create-user.md
    Future<Response> createUser({email, password, name = null}) async {
       String path = '/users';

       Map<String, dynamic> params = {
         'email': email,
         'password': password,
         'name': name,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/users/get-user.md
    Future<Response> getUser({userId}) async {
       String path = '/users/{userId}'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/users/get-user-logs.md
    Future<Response> getUserLogs({userId}) async {
       String path = '/users/{userId}/logs'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/users/get-user-prefs.md
    Future<Response> getUserPrefs({userId}) async {
       String path = '/users/{userId}/prefs'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/users/update-user-prefs.md
    Future<Response> updateUserPrefs({userId, prefs}) async {
       String path = '/users/{userId}/prefs'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
         'prefs': prefs,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// /docs/references/users/get-user-sessions.md
    Future<Response> getUserSessions({userId}) async {
       String path = '/users/{userId}/sessions'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Delete all user sessions by its unique ID.
    Future<Response> deleteUserSessions({userId}) async {
       String path = '/users/{userId}/sessions'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// /docs/references/users/delete-user-session.md
    Future<Response> deleteUserSession({userId, sessionId}) async {
       String path = '/users/{userId}/sessions/:session'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
         'sessionId': sessionId,
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// /docs/references/users/update-user-status.md
    Future<Response> updateUserStatus({userId, status}) async {
       String path = '/users/{userId}/status'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
         'status': status,
       };

       return await this.client.call('patch', path: path, params: params);
    }
}