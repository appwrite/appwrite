import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Storage extends Service {
     
     Storage(Client client): super(client);

     /// /docs/references/storage/list-files.md
    Future<Response> listFiles({search = null, limit = 25, offset = null, orderType = 'ASC'}) async {
       String path = '/storage/files';

       Map<String, dynamic> params = {
         'search': search,
         'limit': limit,
         'offset': offset,
         'orderType': orderType,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/storage/create-file.md
    Future<Response> createFile({files, read = const [], write = const [], folderId = null}) async {
       String path = '/storage/files';

       Map<String, dynamic> params = {
         'files': files,
         'read': read,
         'write': write,
         'folderId': folderId,
       };

       return await this.client.call('post', path: path, params: params);
    }
     /// /docs/references/storage/get-file.md
    Future<Response> getFile({fileId}) async {
       String path = '/storage/files/{fileId}'.replaceAll(RegExp('{fileId}'), fileId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/storage/update-file.md
    Future<Response> updateFile({fileId, read = const [], write = const [], folderId = null}) async {
       String path = '/storage/files/{fileId}'.replaceAll(RegExp('{fileId}'), fileId);

       Map<String, dynamic> params = {
         'read': read,
         'write': write,
         'folderId': folderId,
       };

       return await this.client.call('put', path: path, params: params);
    }
     /// /docs/references/storage/delete-file.md
    Future<Response> deleteFile({fileId}) async {
       String path = '/storage/files/{fileId}'.replaceAll(RegExp('{fileId}'), fileId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('delete', path: path, params: params);
    }
     /// /docs/references/storage/get-file-download.md
    Future<Response> getFileDownload({fileId}) async {
       String path = '/storage/files/{fileId}/download'.replaceAll(RegExp('{fileId}'), fileId);

       Map<String, dynamic> params = {
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/storage/get-file-preview.md
    Future<Response> getFilePreview({fileId, width = null, height = null, quality = 100, background = null, output = null}) async {
       String path = '/storage/files/{fileId}/preview'.replaceAll(RegExp('{fileId}'), fileId);

       Map<String, dynamic> params = {
         'width': width,
         'height': height,
         'quality': quality,
         'background': background,
         'output': output,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/storage/get-file-view.md
    Future<Response> getFileView({fileId, as = null}) async {
       String path = '/storage/files/{fileId}/view'.replaceAll(RegExp('{fileId}'), fileId);

       Map<String, dynamic> params = {
         'as': as,
       };

       return await this.client.call('get', path: path, params: params);
    }
}