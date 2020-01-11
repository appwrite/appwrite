import "package:appwrite/service.dart";
import "package:appwrite/client.dart";
import 'package:dio/dio.dart';

class Avatars extends Service {
     
     Avatars(Client client): super(client);

     /// You can use this endpoint to show different browser icons to your users.
     /// The code argument receives the browser code as it appears in your user
     /// /account/sessions endpoint. Use width, height and quality arguments to
     /// change the output settings.
    Future<Response> getBrowser({code, width = 100, height = 100, quality = 100}) async {
       String path = '/avatars/browsers/{code}'.replaceAll(RegExp('{code}'), code);

       Map<String, dynamic> params = {
         'width': width,
         'height': height,
         'quality': quality,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Need to display your users with your billing method or their payment
     /// methods? The credit card endpoint will return you the icon of the credit
     /// card provider you need. Use width, height and quality arguments to change
     /// the output settings.
    Future<Response> getCreditCard({code, width = 100, height = 100, quality = 100}) async {
       String path = '/avatars/credit-cards/{code}'.replaceAll(RegExp('{code}'), code);

       Map<String, dynamic> params = {
         'width': width,
         'height': height,
         'quality': quality,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Use this endpoint to fetch the favorite icon (AKA favicon) of a  any remote
     /// website URL.
    Future<Response> getFavicon({url}) async {
       String path = '/avatars/favicon';

       Map<String, dynamic> params = {
         'url': url,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// You can use this endpoint to show different country flags icons to your
     /// users. The code argument receives the 2 letter country code. Use width,
     /// height and quality arguments to change the output settings.
    Future<Response> getFlag({code, width = 100, height = 100, quality = 100}) async {
       String path = '/avatars/flags/{code}'.replaceAll(RegExp('{code}'), code);

       Map<String, dynamic> params = {
         'width': width,
         'height': height,
         'quality': quality,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Use this endpoint to fetch a remote image URL and crop it to any image size
     /// you want. This endpoint is very useful if you need to crop and display
     /// remote images in your app or in case you want to make sure a 3rd party
     /// image is properly served using a TLS protocol.
    Future<Response> getImage({url, width = 400, height = 400}) async {
       String path = '/avatars/image';

       Map<String, dynamic> params = {
         'url': url,
         'width': width,
         'height': height,
       };

       return await this.client.call('get', path: path, params: params);
    }
     /// Converts a given plain text to a QR code image. You can use the query
     /// parameters to change the size and style of the resulting image.
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