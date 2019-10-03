import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Auth extends Service {
     
     Auth(Client client): super(client);

     /// Allow the user to login into his account by providing a valid email and
     /// password combination. Use the success and failure arguments to provide a
     /// redirect URL\'s back to your app when login is completed. 
     /// 
     /// Please notice that in order to avoid a [Redirect
     /// Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     /// the only valid redirect URL's are the once from domains you have set when
     /// added your platforms in the console interface.
     /// 
     /// When accessing this route using JavaScript from the browser, success and
     /// failure parameter URLs are required. Appwrite server will respond with a
     /// 301 redirect status code and will set the user session cookie. This
     /// behavior is enforced because modern browsers are limiting 3rd party cookies
     /// in XHR of fetch requests to protect user privacy.
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
     /// Use this endpoint to log out the currently logged in user from his account.
     /// When succeed this endpoint will delete the user session and remove the
     /// session secret cookie from the user client.
    Future<Response> logout() async {
       String path = '/auth/logout';

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// Use this endpoint to log out the currently logged in user from all his
     /// account sessions across all his different devices. When using the option id
     /// argument, only the session unique ID provider will be deleted.
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
     /// Sends the user an email with a temporary secret token for password reset.
     /// When the user clicks the confirmation link he is redirected back to your
     /// app password reset redirect URL with a secret token and email address
     /// values attached to the URL query string. Use the query string params to
     /// submit a request to the /auth/password/reset endpoint to complete the
     /// process.
    Future<Response> recovery({email, reset}) async {
       String path = '/auth/recovery';

       Map<String, dynamic> params = {
         'email': email,
         'reset': reset,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// Use this endpoint to complete the user account password reset. Both the
     /// **userId** and **token** arguments will be passed as query parameters to
     /// the redirect URL you have provided when sending your request to the
     /// /auth/recovery endpoint.
     /// 
     /// Please notice that in order to avoid a [Redirect
     /// Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     /// the only valid redirect URL's are the once from domains you have set when
     /// added your platforms in the console interface.
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
     /// Use this endpoint to allow a new user to register an account in your
     /// project. Use the success and failure URL's to redirect users back to your
     /// application after signup completes.
     /// 
     /// If registration completes successfully user will be sent with a
     /// confirmation email in order to confirm he is the owner of the account email
     /// address. Use the confirmation parameter to redirect the user from the
     /// confirmation email back to your app. When the user is redirected, use the
     /// /auth/confirm endpoint to complete the account confirmation.
     /// 
     /// Please notice that in order to avoid a [Redirect
     /// Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     /// the only valid redirect URL's are the once from domains you have set when
     /// added your platforms in the console interface.
     /// 
     /// When accessing this route using JavaScript from the browser, success and
     /// failure parameter URLs are required. Appwrite server will respond with a
     /// 301 redirect status code and will set the user session cookie. This
     /// behavior is enforced because modern browsers are limiting 3rd party cookies
     /// in XHR of fetch requests to protect user privacy.
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
     /// Use this endpoint to complete the confirmation of the user account email
     /// address. Both the **userId** and **token** arguments will be passed as
     /// query parameters to the redirect URL you have provided when sending your
     /// request to the /auth/register endpoint.
    Future<Response> confirm({userId, token}) async {
       String path = '/auth/register/confirm';

       Map<String, dynamic> params = {
         'userId': userId,
         'token': token,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// This endpoint allows the user to request your app to resend him his email
     /// confirmation message. The redirect arguments acts the same way as in
     /// /auth/register endpoint.
     /// 
     /// Please notice that in order to avoid a [Redirect
     /// Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     /// the only valid redirect URL's are the once from domains you have set when
     /// added your platforms in the console interface.
    Future<Response> confirmResend({confirm}) async {
       String path = '/auth/register/confirm/resend';

       Map<String, dynamic> params = {
         'confirm': confirm,
       };

       return await this.client.call('post', path: path, params: params);
    }
}