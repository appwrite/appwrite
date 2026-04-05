package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetJWT("<YOUR_JWT>") // Your secret JSON Web Token

    service := messaging.NewMessaging(client)
    response, error := service.DeleteSubscriber(
        "<TOPIC_ID>",
        "<SUBSCRIBER_ID>",
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
