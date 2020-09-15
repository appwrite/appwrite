

import 'package:dio/dio.dart';
import 'package:meta/meta.dart';

import "../client.dart";
import '../enums.dart';
import "../service.dart";

class Avatars extends Service {
    Avatars(Client client): super(client);

     /// Get Browser Icon
     ///
     /// You can use this endpoint to show different browser icons to your users.
     /// The code argument receives the browser code as it appears in your user
     /// /account/sessions endpoint. Use width, height and quality arguments to
     /// change the output settings.
     ///
    String getBrowser({@required String code, int width = 100, int height = 100, int quality = 100}) {
        final String path = '/avatars/browsers/{code}'.replaceAll(RegExp('{code}'), code);

        final Map<String, dynamic> params = {
            'width': width,
            'height': height,
            'quality': quality,
            'project': client.config['project'],
        };

        params.keys.forEach((key) {if (params[key] is int || params[key] is double) {
          params[key] = params[key].toString();
        }});
        
        Uri endpoint = Uri.parse(client.endPoint);
        Uri location = new Uri(scheme: endpoint.scheme,
          host: endpoint.host,
          port: endpoint.port,
          path: endpoint.path + path,
          queryParameters:params,
        );

        return location.toString();
    }

     /// Get Credit Card Icon
     ///
     /// Need to display your users with your billing method or their payment
     /// methods? The credit card endpoint will return you the icon of the credit
     /// card provider you need. Use width, height and quality arguments to change
     /// the output settings.
     ///
    String getCreditCard({@required String code, int width = 100, int height = 100, int quality = 100}) {
        final String path = '/avatars/credit-cards/{code}'.replaceAll(RegExp('{code}'), code);

        final Map<String, dynamic> params = {
            'width': width,
            'height': height,
            'quality': quality,
            'project': client.config['project'],
        };

        params.keys.forEach((key) {if (params[key] is int || params[key] is double) {
          params[key] = params[key].toString();
        }});
        
        Uri endpoint = Uri.parse(client.endPoint);
        Uri location = new Uri(scheme: endpoint.scheme,
          host: endpoint.host,
          port: endpoint.port,
          path: endpoint.path + path,
          queryParameters:params,
        );

        return location.toString();
    }

     /// Get Favicon
     ///
     /// Use this endpoint to fetch the favorite icon (AKA favicon) of a  any remote
     /// website URL.
     ///
    String getFavicon({@required String url}) {
        final String path = '/avatars/favicon';

        final Map<String, dynamic> params = {
            'url': url,
            'project': client.config['project'],
        };

        params.keys.forEach((key) {if (params[key] is int || params[key] is double) {
          params[key] = params[key].toString();
        }});
        
        Uri endpoint = Uri.parse(client.endPoint);
        Uri location = new Uri(scheme: endpoint.scheme,
          host: endpoint.host,
          port: endpoint.port,
          path: endpoint.path + path,
          queryParameters:params,
        );

        return location.toString();
    }

     /// Get Country Flag
     ///
     /// You can use this endpoint to show different country flags icons to your
     /// users. The code argument receives the 2 letter country code. Use width,
     /// height and quality arguments to change the output settings.
     ///
    String getFlag({@required String code, int width = 100, int height = 100, int quality = 100}) {
        final String path = '/avatars/flags/{code}'.replaceAll(RegExp('{code}'), code);

        final Map<String, dynamic> params = {
            'width': width,
            'height': height,
            'quality': quality,
            'project': client.config['project'],
        };

        params.keys.forEach((key) {if (params[key] is int || params[key] is double) {
          params[key] = params[key].toString();
        }});
        
        Uri endpoint = Uri.parse(client.endPoint);
        Uri location = new Uri(scheme: endpoint.scheme,
          host: endpoint.host,
          port: endpoint.port,
          path: endpoint.path + path,
          queryParameters:params,
        );

        return location.toString();
    }

     /// Get Image from URL
     ///
     /// Use this endpoint to fetch a remote image URL and crop it to any image size
     /// you want. This endpoint is very useful if you need to crop and display
     /// remote images in your app or in case you want to make sure a 3rd party
     /// image is properly served using a TLS protocol.
     ///
    String getImage({@required String url, int width = 400, int height = 400}) {
        final String path = '/avatars/image';

        final Map<String, dynamic> params = {
            'url': url,
            'width': width,
            'height': height,
            'project': client.config['project'],
        };

        params.keys.forEach((key) {if (params[key] is int || params[key] is double) {
          params[key] = params[key].toString();
        }});
        
        Uri endpoint = Uri.parse(client.endPoint);
        Uri location = new Uri(scheme: endpoint.scheme,
          host: endpoint.host,
          port: endpoint.port,
          path: endpoint.path + path,
          queryParameters:params,
        );

        return location.toString();
    }

     /// Get User Initials
     ///
     /// Use this endpoint to show your user initials avatar icon on your website or
     /// app. By default, this route will try to print your logged-in user name or
     /// email initials. You can also overwrite the user name if you pass the 'name'
     /// parameter. If no name is given and no user is logged, an empty avatar will
     /// be returned.
     /// 
     /// You can use the color and background params to change the avatar colors. By
     /// default, a random theme will be selected. The random theme will persist for
     /// the user's initials when reloading the same theme will always return for
     /// the same initials.
     ///
    String getInitials({String name = '', int width = 500, int height = 500, String color = '', String background = ''}) {
        final String path = '/avatars/initials';

        final Map<String, dynamic> params = {
            'name': name,
            'width': width,
            'height': height,
            'color': color,
            'background': background,
            'project': client.config['project'],
        };

        params.keys.forEach((key) {if (params[key] is int || params[key] is double) {
          params[key] = params[key].toString();
        }});
        
        Uri endpoint = Uri.parse(client.endPoint);
        Uri location = new Uri(scheme: endpoint.scheme,
          host: endpoint.host,
          port: endpoint.port,
          path: endpoint.path + path,
          queryParameters:params,
        );

        return location.toString();
    }

     /// Get QR Code
     ///
     /// Converts a given plain text to a QR code image. You can use the query
     /// parameters to change the size and style of the resulting image.
     ///
    String getQR({@required String text, int size = 400, int margin = 1, bool download = false}) {
        final String path = '/avatars/qr';

        final Map<String, dynamic> params = {
            'text': text,
            'size': size,
            'margin': margin,
            'download': download,
            'project': client.config['project'],
        };

        params.keys.forEach((key) {if (params[key] is int || params[key] is double) {
          params[key] = params[key].toString();
        }});
        
        Uri endpoint = Uri.parse(client.endPoint);
        Uri location = new Uri(scheme: endpoint.scheme,
          host: endpoint.host,
          port: endpoint.port,
          path: endpoint.path + path,
          queryParameters:params,
        );

        return location.toString();
    }
}