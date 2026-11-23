mod appwrite_client;

use appwrite::models::{PolicyList, PolicyPasswordStrength};
use appwrite::services::Project;
use axum::extract::State;
use axum::http::StatusCode;
use axum::routing::patch;
use axum::{Json, Router};

#[tokio::main]
async fn main() {
    let project = Project::new(&appwrite_client::client());

    let app = Router::new()
        .route("/v1/policies", patch(update_policy).get(list_policies))
        .with_state(project);

    let listener = tokio::net::TcpListener::bind("0.0.0.0:3000")
        .await
        .unwrap();

    println!("Listening on http://localhost:3000");

    axum::serve(listener, app).await.unwrap();
}

async fn update_policy(
    State(project): State<Project>,
) -> Result<Json<PolicyPasswordStrength>, StatusCode> {
    project
        // min, uppercase, lowercase, number, symbols
        .update_password_strength_policy(
            Some(8),
            Some(true),
            None,
            Some(true),
            Some(true),
        )
        .await
        .map(Json)
        .map_err(|_| StatusCode::BAD_GATEWAY)
}

async fn list_policies(
    State(project): State<Project>,
) -> Result<Json<PolicyList>, StatusCode> {
    project
        .list_policies(None, None)
        .await
        .map(Json)
        .map_err(|_| StatusCode::BAD_GATEWAY)
}
