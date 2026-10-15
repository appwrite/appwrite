use appwrite::client::Client;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() {
    let client = Client::new()
        .set_endpoint(std::env::var("APPWRITE_ENDPOINT").unwrap())
        .set_project(std::env::var("APPWRITE_PROJECT_ID").unwrap())
        .set_key(std::env::var("APPWRITE_API_KEY").unwrap());

    let project = Project::new(&client);

    let policy = project
        // min, uppercase, lowercase, number, symbols
        .update_password_strength_policy(
            Some(8),
            Some(true),
            None,
            Some(true),
            Some(true),
        )
        .await
        .unwrap();

    println!("{}", serde_json::to_string_pretty(&policy).unwrap());

    let policies = project.list_policies(None, None).await.unwrap();

    println!("{}", serde_json::to_string_pretty(&policies).unwrap());
}
