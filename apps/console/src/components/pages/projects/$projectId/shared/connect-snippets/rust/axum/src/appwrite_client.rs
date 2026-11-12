use appwrite::client::Client;

pub fn client() -> Client {
    Client::new()
        .set_endpoint(std::env::var("APPWRITE_ENDPOINT").unwrap())
        .set_project(std::env::var("APPWRITE_PROJECT_ID").unwrap())
        .set_key(std::env::var("APPWRITE_API_KEY").unwrap())
}
