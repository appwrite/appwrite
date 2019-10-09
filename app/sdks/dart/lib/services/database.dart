import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Database extends Service {
     
     Database(Client client): super(client);

     /// /docs/references/database/list-collections.md
    Future<Response> listCollections({search = null, limit = 25, offset = null, orderType = 'ASC'}) async {
       String path = '/database';

       Map<String, dynamic> params = {
         'search': search,
         'limit': limit,
         'offset': offset,
         'orderType': orderType,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/database/create-collection.md
    Future<Response> createCollection({name, read = const [], write = const [], rules = const []}) async {
       String path = '/database';

       Map<String, dynamic> params = {
         'name': name,
         'read': read,
         'write': write,
         'rules': rules,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/database/get-collection.md
    Future<Response> getCollection({collectionId}) async {
       String path = '/database/{collectionId}'.replaceAll(RegExp('{collectionId}'), collectionId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/database/update-collection.md
    Future<Response> updateCollection({collectionId, name, read = const [], write = const [], rules = const []}) async {
       String path = '/database/{collectionId}'.replaceAll(RegExp('{collectionId}'), collectionId);

       Map<String, dynamic> params = {
         'name': name,
         'read': read,
         'write': write,
         'rules': rules,
       };

       return await this.client.call('put', path: path, params: params);
    }
     /// /docs/references/database/delete-collection.md
    Future<Response> deleteCollection({collectionId}) async {
       String path = '/database/{collectionId}'.replaceAll(RegExp('{collectionId}'), collectionId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// /docs/references/database/list-documents.md
    Future<Response> listDocuments({collectionId, filters = const [], offset = null, limit = 50, orderField = '\$uid', orderType = 'ASC', orderCast = 'string', search = null, first = null, last = null}) async {
       String path = '/database/{collectionId}/documents'.replaceAll(RegExp('{collectionId}'), collectionId);

       Map<String, dynamic> params = {
         'filters': filters,
         'offset': offset,
         'limit': limit,
         'order-field': orderField,
         'order-type': orderType,
         'order-cast': orderCast,
         'search': search,
         'first': first,
         'last': last,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/database/create-document.md
    Future<Response> createDocument({collectionId, data, read = const [], write = const [], parentDocument = null, parentProperty = null, parentPropertyType = 'assign'}) async {
       String path = '/database/{collectionId}/documents'.replaceAll(RegExp('{collectionId}'), collectionId);

       Map<String, dynamic> params = {
         'data': data,
         'read': read,
         'write': write,
         'parentDocument': parentDocument,
         'parentProperty': parentProperty,
         'parentPropertyType': parentPropertyType,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/database/get-document.md
    Future<Response> getDocument({collectionId, documentId}) async {
       String path = '/database/{collectionId}/documents/{documentId}'.replaceAll(RegExp('{collectionId}'), collectionId).replaceAll(RegExp('{documentId}'), documentId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/database/update-document.md
    Future<Response> updateDocument({collectionId, documentId, data, read = const [], write = const []}) async {
       String path = '/database/{collectionId}/documents/{documentId}'.replaceAll(RegExp('{collectionId}'), collectionId).replaceAll(RegExp('{documentId}'), documentId);

       Map<String, dynamic> params = {
         'data': data,
         'read': read,
         'write': write,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// /docs/references/database/delete-document.md
    Future<Response> deleteDocument({collectionId, documentId}) async {
       String path = '/database/{collectionId}/documents/{documentId}'.replaceAll(RegExp('{collectionId}'), collectionId).replaceAll(RegExp('{documentId}'), documentId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
}