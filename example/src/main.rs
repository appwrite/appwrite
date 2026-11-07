//! A tiny Utopia HTTP server. `GET /example` answers with plain text.
//!
//!   cargo run -p example

use std::net::SocketAddr;
use std::sync::Arc;

use utopia_http::{App, Http, ServerOptions, serve};

fn main() {
    let mut http = Http::new("UTC");
    http.get("/example").expect("route").inject("response").action(async |scope| {
        scope.response.text("Hello");
    });

    let addr = SocketAddr::from(([127, 0, 0, 1], 8080));
    println!("listening on http://{addr}/example");
    utopia_async::run(async move {
        serve(addr, Arc::new(App(Arc::new(http))), ServerOptions::default(), async {
            let _ = utopia_async::signal::ctrl_c().await;
        })
        .await
    })
    .expect("server");
}
