import 'dart:io';

import 'package:cookie_jar/cookie_jar.dart';
import 'package:dio/adapter.dart';
import 'package:dio/dio.dart';
import 'package:dio_cookie_manager/dio_cookie_manager.dart';

import 'enums.dart';

class Client {
    String endPoint;
    Map<String, String> headers;
    bool selfSigned;
    final Dio http;

    Client({this.endPoint = 'https://appwrite.io/v1', this.selfSigned = false, Dio http}) : this.http = http ?? Dio() {
        this.headers = {
            'content-type': 'application/json',
            'x-sdk-version': 'appwrite:dart:0.0.10',
        };

        assert(endPoint.startsWith(RegExp("http://|https://")), "endPoint $endPoint must start with 'http'");
        this.http.options.baseUrl = this.endPoint;
        this.http.options.validateStatus = (status) => status != 404;
        this.http.interceptors.add(CookieManager(CookieJar()));
    }

     /// Your project ID
    Client setProject(value) {
        addHeader('X-Appwrite-Project', value);
        return this;
    }
     /// Your secret API key
    Client setKey(value) {
        addHeader('X-Appwrite-Key', value);
        return this;
    }
    Client setLocale(value) {
        addHeader('X-Appwrite-Locale', value);
        return this;
    }
    Client setMode(value) {
        addHeader('X-Appwrite-Mode', value);
        return this;
    }
    Client setSelfSigned({bool status = true}) {
        selfSigned = status;
        return this;
    }

    Client setEndpoint(String endPoint) {
        this.endPoint = endPoint;
        this.http.options.baseUrl = this.endPoint;
        return this;
    }

    Client addHeader(String key, String value) {
        headers[key] = value;
        
        return this;
    }

    Future<Response> call(HttpMethod method, {String path = '', Map<String, String> headers = const {}, Map<String, dynamic> params = const {}}) {
        if(selfSigned) {
            // Allow self signed requests
            (http.httpClientAdapter as DefaultHttpClientAdapter).onHttpClientCreate = (HttpClient client) {
                client.badCertificateCallback = (X509Certificate cert, String host, int port) => true;
                return client;
            };
        }

        // Origin is hardcoded for testing
        Options options = Options(
            headers: {...this.headers, ...headers, "Origin": "http://localhost"},
            method: method.name(),
        );

        if (method == HttpMethod.get) {
            return http.get(path, queryParameters: params, options: options);
        } else {
            return http.request(path, data: params, options: options);
        }
    }
}