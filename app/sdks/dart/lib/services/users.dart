import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Users extends Service {
     
     Users(Client client): super(client);

     /// Get a list of all the project users. You can use the query params to filter
     /// your results.
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
     /// Create a new user.
    Future<Response> createUser({email, password, name = null}) async {
       String path = '/users';

       Map<String, dynamic> params = {
         'email': email,
         'password': password,
         'name': name,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// Get user by its unique ID.
    Future<Response> getUser({userId}) async {
       String path = '/users/{userId}'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Get user activity logs list by its unique ID.
    Future<Response> getUserLogs({userId}) async {
       String path = '/users/{userId}/logs'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Get user preferences by its unique ID.
    Future<Response> getUserPrefs({userId}) async {
       String path = '/users/{userId}/prefs'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Update user preferences by its unique ID. You can pass only the specific
     /// settings you wish to update.
    Future<Response> updateUserPrefs({userId, prefs}) async {
       String path = '/users/{userId}/prefs'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
         'prefs': prefs,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// Get user sessions list by its unique ID.
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
     /// Delete user sessions by its unique ID.
    Future<Response> deleteUserSession({userId, sessionId}) async {
       String path = '/users/{userId}/sessions/:session'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
         'sessionId': sessionId,
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// Update user status by its unique ID.
    Future<Response> updateUserStatus({userId, status}) async {
       String path = '/users/{userId}/status'.replaceAll(RegExp('{userId}'), userId);

       Map<String, dynamic> params = {
         'status': status,
       };

       return await this.client.call('patch', path: path, params: params);
    }
}