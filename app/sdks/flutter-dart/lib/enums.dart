enum HttpMethod { get, post, put, delete, patch }

extension HttpMethodString on HttpMethod {
  String name() {
    return this.toString().split('.').last.toUpperCase();
  }
}

enum OrderType { asc, desc }

extension OrderTypeString on OrderType {
  String name() {
    return this.toString().split('.').last.toUpperCase();
  }
}
