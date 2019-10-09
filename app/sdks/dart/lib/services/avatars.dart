import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Avatars extends Service {
     
     Avatars(Client client): super(client);

     /// /docs/references/avatars/get-browser.md
    Future<Response> getBrowser({code, width = 100, height = 100, quality = 100}) async {
       String path = '/avatars/browsers/{code}'.replaceAll(RegExp('{code}'), code);

       Map<String, dynamic> params = {
         'width': width,
         'height': height,
         'quality': quality,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/avatars/get-credit-cards.md
    Future<Response> getCreditCard({code, width = 100, height = 100, quality = 100}) async {
       String path = '/avatars/credit-cards/{code}'.replaceAll(RegExp('{code}'), code);

       Map<String, dynamic> params = {
         'width': width,
         'height': height,
         'quality': quality,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/avatars/get-favicon.md
    Future<Response> getFavicon({url}) async {
       String path = '/avatars/favicon';

       Map<String, dynamic> params = {
         'url': url,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/avatars/get-flag.md
    Future<Response> getFlag({code, width = 100, height = 100, quality = 100}) async {
       String path = '/avatars/flags/{code}'.replaceAll(RegExp('{code}'), code);

       Map<String, dynamic> params = {
         'width': width,
         'height': height,
         'quality': quality,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/avatars/get-image.md
    Future<Response> getImage({url, width = 400, height = 400}) async {
       String path = '/avatars/image';

       Map<String, dynamic> params = {
         'url': url,
         'width': width,
         'height': height,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// /docs/references/avatars/get-qr.md
    Future<Response> getQR({text, size = 400, margin = 1, download = null}) async {
       String path = '/avatars/qr';

       Map<String, dynamic> params = {
         'text': text,
         'size': size,
         'margin': margin,
         'download': download,
       };

       return await this.client.call('get', path: path, params: params);
    }
}