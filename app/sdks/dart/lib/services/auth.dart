import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Auth extends Service {
     
     Auth(Client client): super(client);

     /// /docs/references/auth/login.md
    Future<Response> login({email, password, success, failure}) async {
       String path = '/auth/login';

       Map<String, dynamic> params = {
         'email': email,
         'password': password,
         'success': success,
         'failure': failure,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/auth/logout.md
    Future<Response> logout() async {
       String path = '/auth/logout';

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// /docs/references/auth/logout-by-session.md
    Future<Response> logoutBySession({id}) async {
       String path = '/auth/logout/{id}'.replaceAll(RegExp('{id}'), id);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
    Future<Response> oauth({provider, success = null, failure = null}) async {
       String path = '/auth/oauth/{provider}'.replaceAll(RegExp('{provider}'), provider);

       Map<String, dynamic> params = {
         'success': success,
         'failure': failure,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/auth/recovery.md
    Future<Response> recovery({email, reset}) async {
       String path = '/auth/recovery';

       Map<String, dynamic> params = {
         'email': email,
         'reset': reset,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/auth/recovery-reset.md
    Future<Response> recoveryReset({userId, token, passwordA, passwordB}) async {
       String path = '/auth/recovery/reset';

       Map<String, dynamic> params = {
         'userId': userId,
         'token': token,
         'password-a': passwordA,
         'password-b': passwordB,
       };

       return await this.client.call('put', path: path, params: params);
    }
     /// /docs/references/auth/register.md
    Future<Response> register({email, password, confirm, success = null, failure = null, name = null}) async {
       String path = '/auth/register';

       Map<String, dynamic> params = {
         'email': email,
         'password': password,
         'confirm': confirm,
         'success': success,
         'failure': failure,
         'name': name,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/auth/confirm.md
    Future<Response> confirm({userId, token}) async {
       String path = '/auth/register/confirm';

       Map<String, dynamic> params = {
         'userId': userId,
         'token': token,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/auth/confirm-resend.md
    Future<Response> confirmResend({confirm}) async {
       String path = '/auth/register/confirm/resend';

       Map<String, dynamic> params = {
         'confirm': confirm,
       };

       return await this.client.call('post', path: path, params: params);
    }
}