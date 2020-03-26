enum HttpMethod {
    get, post, put, delete, patch
}

extension HttpMethodString on HttpMethod{
    String name(){
        return this.toString().split('.').last.toUpperCase();
    }
}
