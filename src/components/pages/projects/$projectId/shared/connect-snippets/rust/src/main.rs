use appwrite::client::Client;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() {
    let client = Client::new()
        .set_endpoint(&std::env::var("APPWRITE_ENDPOINT").unwrap())
        .set_project(&std::env::var("APPWRITE_PROJECT_ID").unwrap())
        .set_key(&std::env::var("APPWRITE_API_KEY").unwrap());

    let project = Project::new(&client);

    // min: 8, uppercase, number, symbols
    let policy = project
        .update_password_strength_policy(8, true, true, true)
        .await
        .unwrap();

    println!("{:?}", policy);

    let policies = project.list_policies().await.unwrap();

    println!("{:?}", policies);
}
