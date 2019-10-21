import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Database extends Service {
     
     Database(Client client): super(client);

     /// Get a list of all the user collections. You can use the query params to
     /// filter your results. On admin mode, this endpoint will return a list of all
     /// of the project collections. [Learn more about different API
     /// modes](/docs/modes).
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
     /// Create a new Collection.
    Future<Response> createCollection({name, read, write, rules}) async {
       String path = '/database';

       Map<String, dynamic> params = {
         'name': name,
         'read': read,
         'write': write,
         'rules': rules,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// Get collection by its unique ID. This endpoint response returns a JSON
     /// object with the collection metadata.
    Future<Response> getCollection({collectionId}) async {
       String path = '/database/{collectionId}'.replaceAll(RegExp('{collectionId}'), collectionId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Update collection by its unique ID.
    Future<Response> updateCollection({collectionId, name, read, write, rules = const []}) async {
       String path = '/database/{collectionId}'.replaceAll(RegExp('{collectionId}'), collectionId);

       Map<String, dynamic> params = {
         'name': name,
         'read': read,
         'write': write,
         'rules': rules,
       };

       return await this.client.call('put', path: path, params: params);
    }
     /// Delete a collection by its unique ID. Only users with write permissions
     /// have access to delete this resource.
    Future<Response> deleteCollection({collectionId}) async {
       String path = '/database/{collectionId}'.replaceAll(RegExp('{collectionId}'), collectionId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// Get a list of all the user documents. You can use the query params to
     /// filter your results. On admin mode, this endpoint will return a list of all
     /// of the project documents. [Learn more about different API
     /// modes](/docs/modes).
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
     /// Create a new Document.
    Future<Response> createDocument({collectionId, data, read, write, parentDocument = null, parentProperty = null, parentPropertyType = 'assign'}) async {
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
     /// Get document by its unique ID. This endpoint response returns a JSON object
     /// with the document data.
    Future<Response> getDocument({collectionId, documentId}) async {
       String path = '/database/{collectionId}/documents/{documentId}'.replaceAll(RegExp('{collectionId}'), collectionId).replaceAll(RegExp('{documentId}'), documentId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
    Future<Response> updateDocument({collectionId, documentId, data, read, write}) async {
       String path = '/database/{collectionId}/documents/{documentId}'.replaceAll(RegExp('{collectionId}'), collectionId).replaceAll(RegExp('{documentId}'), documentId);

       Map<String, dynamic> params = {
         'data': data,
         'read': read,
         'write': write,
       };

       return await this.client.call('patch', path: path, params: params);
    }
     /// Delete document by its unique ID. This endpoint deletes only the parent
     /// documents, his attributes and relations to other documents. Child documents
     /// **will not** be deleted.
    Future<Response> deleteDocument({collectionId, documentId}) async {
       String path = '/database/{collectionId}/documents/{documentId}'.replaceAll(RegExp('{collectionId}'), collectionId).replaceAll(RegExp('{documentId}'), documentId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
}