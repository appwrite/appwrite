package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithJWT("<YOUR_JWT>") // Your secret JSON Web Token
    )

    service := messaging.New(client)
    response, error := service.DeleteSubscriber(
        "<TOPIC_ID>",
        "<SUBSCRIBER_ID>",
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
