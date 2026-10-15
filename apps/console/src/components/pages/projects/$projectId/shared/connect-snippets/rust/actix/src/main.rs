mod appwrite_client;

use actix_web::{web, App, HttpResponse, HttpServer, Responder};
use appwrite::services::Project;

#[actix_web::main]
async fn main() -> std::io::Result<()> {
    let project = web::Data::new(Project::new(&appwrite_client::client()));

    println!("Listening on http://localhost:3000");

    HttpServer::new(move || {
        App::new()
            .app_data(project.clone())
            .route("/v1/policies", web::patch().to(update_policy))
            .route("/v1/policies", web::get().to(list_policies))
    })
    .bind(("0.0.0.0", 3000))?
    .run()
    .await
}

async fn update_policy(project: web::Data<Project>) -> impl Responder {
    let policy = project
        // min, uppercase, lowercase, number, symbols
        .update_password_strength_policy(
            Some(8),
            Some(true),
            None,
            Some(true),
            Some(true),
        )
        .await;

    match policy {
        Ok(policy) => HttpResponse::Ok().json(policy),
        Err(_) => HttpResponse::BadGateway().finish(),
    }
}

async fn list_policies(project: web::Data<Project>) -> impl Responder {
    match project.list_policies(None, None).await {
        Ok(policies) => HttpResponse::Ok().json(policies),
        Err(_) => HttpResponse::BadGateway().finish(),
    }
}
