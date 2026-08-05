use appwrite::client::Client;

fn main() {
    let client = Client::new()
        .set_endpoint(&std::env::var("APPWRITE_ENDPOINT").unwrap())
        .set_project(&std::env::var("APPWRITE_PROJECT_ID").unwrap())
        .set_key(&std::env::var("APPWRITE_API_KEY").unwrap());

    // Use the client with Appwrite services, e.g. account or databases
}
