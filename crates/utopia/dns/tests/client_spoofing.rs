//! A UDP answer counts only when it comes from the queried server: a datagram
//! from another address that guesses the transaction id is dropped.

use std::time::Duration;

use tokio::net::UdpSocket;
use utopia_dns::Client;
use utopia_dns::message::{Message, Question};

#[tokio::test]
async fn an_answer_from_another_address_is_ignored() {
    let server = UdpSocket::bind("127.0.0.1:0").await.unwrap();
    let decoy = UdpSocket::bind("127.0.0.1:0").await.unwrap();
    let port = server.local_addr().unwrap().port();

    let serve = tokio::spawn(async move {
        let mut buffer = [0u8; 512];
        let (n, client) = server.recv_from(&mut buffer).await.unwrap();
        let query = Message::decode(&buffer[..n]).unwrap();
        // The decoy answers first, with the right id: NXDOMAIN.
        let spoofed = Message::response(query.header(), 3).build().unwrap().encode(None).unwrap();
        decoy.send_to(&spoofed, client).await.unwrap();
        tokio::time::sleep(Duration::from_millis(50)).await;
        let real = Message::response(query.header(), 0).build().unwrap().encode(None).unwrap();
        server.send_to(&real, client).await.unwrap();
    });

    let client = Client::new("127.0.0.1", port, Duration::from_secs(5), false).unwrap();
    let answer = client.query(&Message::query(Question::new("example.test", 1), Some(4242), true)).await.unwrap();
    serve.await.unwrap();

    assert_eq!(answer.header().response_code, 0, "the decoy's NXDOMAIN must not be taken as the answer");
}
