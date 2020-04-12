

import 'package:dio/dio.dart';
import 'package:meta/meta.dart';

import "../client.dart";
import '../enums.dart';
import "../service.dart";

class Database extends Service {
    Database(Client client): super(client);

     /// List Documents
     ///
     /// Get a list of all the user documents. You can use the query params to
     /// filter your results. On admin mode, this endpoint will return a list of all
     /// of the project documents. [Learn more about different API
     /// modes](/docs/admin).
     ///
    Future<Response> listDocuments({@required String collectionId, List filters = const [], int offset = 0, int limit = 50, String orderField = '\$id', String orderType = 'ASC', String orderCast = 'string', String search = '', int first = 0, int last = 0}) {
        final String path = '/database/collections/{collectionId}/documents'.replaceAll(RegExp('{collectionId}'), collectionId);

        final Map<String, dynamic> params = {
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

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// Create Document
     ///
     /// Create a new Document.
     ///
    Future<Response> createDocument({@required String collectionId, @required dynamic data, @required List read, @required List write, String parentDocument = '', String parentProperty = '', String parentPropertyType = 'assign'}) {
        final String path = '/database/collections/{collectionId}/documents'.replaceAll(RegExp('{collectionId}'), collectionId);

        final Map<String, dynamic> params = {
            'data': data,
            'read': read,
            'write': write,
            'parentDocument': parentDocument,
            'parentProperty': parentProperty,
            'parentPropertyType': parentPropertyType,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.post, path: path, params: params, headers: headers);
    }

     /// Get Document
     ///
     /// Get document by its unique ID. This endpoint response returns a JSON object
     /// with the document data.
     ///
    Future<Response> getDocument({@required String collectionId, @required String documentId}) {
        final String path = '/database/collections/{collectionId}/documents/{documentId}'.replaceAll(RegExp('{collectionId}'), collectionId).replaceAll(RegExp('{documentId}'), documentId);

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.get, path: path, params: params, headers: headers);
    }

     /// Update Document
    Future<Response> updateDocument({@required String collectionId, @required String documentId, @required dynamic data, @required List read, @required List write}) {
        final String path = '/database/collections/{collectionId}/documents/{documentId}'.replaceAll(RegExp('{collectionId}'), collectionId).replaceAll(RegExp('{documentId}'), documentId);

        final Map<String, dynamic> params = {
            'data': data,
            'read': read,
            'write': write,
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.patch, path: path, params: params, headers: headers);
    }

     /// Delete Document
     ///
     /// Delete document by its unique ID. This endpoint deletes only the parent
     /// documents, his attributes and relations to other documents. Child documents
     /// **will not** be deleted.
     ///
    Future<Response> deleteDocument({@required String collectionId, @required String documentId}) {
        final String path = '/database/collections/{collectionId}/documents/{documentId}'.replaceAll(RegExp('{collectionId}'), collectionId).replaceAll(RegExp('{documentId}'), documentId);

        final Map<String, dynamic> params = {
        };

        final Map<String, String> headers = {
            'content-type': 'application/json',
        };

        return client.call(HttpMethod.delete, path: path, params: params, headers: headers);
    }
}