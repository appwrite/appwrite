import "package:dart_appwrite/service.dart";
import "package:dart_appwrite/client.dart";
import 'package:dio/dio.dart';

class Projects extends Service {
     
     Projects(Client client): super(client);

    Future<Response> listProjects() async {
       String path = '/projects';

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> createProject({name, teamId, description = null, logo = null, url = null, legalName = null, legalCountry = null, legalState = null, legalCity = null, legalAddress = null, legalTaxId = null}) async {
       String path = '/projects';

       Map<String, dynamic> params = {
         'name': name,
         'teamId': teamId,
         'description': description,
         'logo': logo,
         'url': url,
         'legalName': legalName,
         'legalCountry': legalCountry,
         'legalState': legalState,
         'legalCity': legalCity,
         'legalAddress': legalAddress,
         'legalTaxId': legalTaxId,
       };

       return await this.client.call('post', path: path, params: params);
    }
    Future<Response> getProject({projectId}) async {
       String path = '/projects/{projectId}'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> updateProject({projectId, name, description = null, logo = null, url = null, legalName = null, legalCountry = null, legalState = null, legalCity = null, legalAddress = null, legalTaxId = null}) async {
       String path = '/projects/{projectId}'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
         'name': name,
         'description': description,
         'logo': logo,
         'url': url,
         'legalName': legalName,
         'legalCountry': legalCountry,
         'legalState': legalState,
         'legalCity': legalCity,
         'legalAddress': legalAddress,
         'legalTaxId': legalTaxId,
       };

       return await this.client.call('patch', path: path, params: params);
    }
    Future<Response> deleteProject({projectId}) async {
       String path = '/projects/{projectId}'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
    Future<Response> listKeys({projectId}) async {
       String path = '/projects/{projectId}/keys'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> createKey({projectId, name, scopes}) async {
       String path = '/projects/{projectId}/keys'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
         'name': name,
         'scopes': scopes,
       };

       return await this.client.call('post', path: path, params: params);
    }
    Future<Response> getKey({projectId, keyId}) async {
       String path = '/projects/{projectId}/keys/{keyId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{keyId}'), keyId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> updateKey({projectId, keyId, name, scopes}) async {
       String path = '/projects/{projectId}/keys/{keyId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{keyId}'), keyId);

       Map<String, dynamic> params = {
         'name': name,
         'scopes': scopes,
       };

       return await this.client.call('put', path: path, params: params);
    }
    Future<Response> deleteKey({projectId, keyId}) async {
       String path = '/projects/{projectId}/keys/{keyId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{keyId}'), keyId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
    Future<Response> updateProjectOAuth({projectId, provider, appId = null, secret = null}) async {
       String path = '/projects/{projectId}/oauth'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
         'provider': provider,
         'appId': appId,
         'secret': secret,
       };

       return await this.client.call('patch', path: path, params: params);
    }
    Future<Response> listPlatforms({projectId}) async {
       String path = '/projects/{projectId}/platforms'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> createPlatform({projectId, type, name, key = null, store = null, url = null}) async {
       String path = '/projects/{projectId}/platforms'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
         'type': type,
         'name': name,
         'key': key,
         'store': store,
         'url': url,
       };

       return await this.client.call('post', path: path, params: params);
    }
    Future<Response> getPlatform({projectId, platformId}) async {
       String path = '/projects/{projectId}/platforms/{platformId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{platformId}'), platformId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> updatePlatform({projectId, platformId, name, key = null, store = null, url = null}) async {
       String path = '/projects/{projectId}/platforms/{platformId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{platformId}'), platformId);

       Map<String, dynamic> params = {
         'name': name,
         'key': key,
         'store': store,
         'url': url,
       };

       return await this.client.call('put', path: path, params: params);
    }
    Future<Response> deletePlatform({projectId, platformId}) async {
       String path = '/projects/{projectId}/platforms/{platformId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{platformId}'), platformId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
    Future<Response> listTasks({projectId}) async {
       String path = '/projects/{projectId}/tasks'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> createTask({projectId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders = null, httpUser = null, httpPass = null}) async {
       String path = '/projects/{projectId}/tasks'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
         'name': name,
         'status': status,
         'schedule': schedule,
         'security': security,
         'httpMethod': httpMethod,
         'httpUrl': httpUrl,
         'httpHeaders': httpHeaders,
         'httpUser': httpUser,
         'httpPass': httpPass,
       };

       return await this.client.call('post', path: path, params: params);
    }
    Future<Response> getTask({projectId, taskId}) async {
       String path = '/projects/{projectId}/tasks/{taskId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{taskId}'), taskId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> updateTask({projectId, taskId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders = null, httpUser = null, httpPass = null}) async {
       String path = '/projects/{projectId}/tasks/{taskId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{taskId}'), taskId);

       Map<String, dynamic> params = {
         'name': name,
         'status': status,
         'schedule': schedule,
         'security': security,
         'httpMethod': httpMethod,
         'httpUrl': httpUrl,
         'httpHeaders': httpHeaders,
         'httpUser': httpUser,
         'httpPass': httpPass,
       };

       return await this.client.call('put', path: path, params: params);
    }
    Future<Response> deleteTask({projectId, taskId}) async {
       String path = '/projects/{projectId}/tasks/{taskId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{taskId}'), taskId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
    Future<Response> getProjectUsage({projectId}) async {
       String path = '/projects/{projectId}/usage'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> listWebhooks({projectId}) async {
       String path = '/projects/{projectId}/webhooks'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> createWebhook({projectId, name, events, url, security, httpUser = null, httpPass = null}) async {
       String path = '/projects/{projectId}/webhooks'.replaceAll(RegExp('{projectId}'), projectId);

       Map<String, dynamic> params = {
         'name': name,
         'events': events,
         'url': url,
         'security': security,
         'httpUser': httpUser,
         'httpPass': httpPass,
       };

       return await this.client.call('post', path: path, params: params);
    }
    Future<Response> getWebhook({projectId, webhookId}) async {
       String path = '/projects/{projectId}/webhooks/{webhookId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{webhookId}'), webhookId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> updateWebhook({projectId, webhookId, name, events, url, security, httpUser = null, httpPass = null}) async {
       String path = '/projects/{projectId}/webhooks/{webhookId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{webhookId}'), webhookId);

       Map<String, dynamic> params = {
         'name': name,
         'events': events,
         'url': url,
         'security': security,
         'httpUser': httpUser,
         'httpPass': httpPass,
       };

       return await this.client.call('put', path: path, params: params);
    }
    Future<Response> deleteWebhook({projectId, webhookId}) async {
       String path = '/projects/{projectId}/webhooks/{webhookId}'.replaceAll(RegExp('{projectId}'), projectId).replaceAll(RegExp('{webhookId}'), webhookId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
}