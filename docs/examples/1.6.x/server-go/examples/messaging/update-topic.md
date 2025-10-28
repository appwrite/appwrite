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
    client.SetKey("<YOUR_API_KEY>") // Your secret API key

    service := messaging.NewMessaging(client)
    response, error := service.UpdateTopic(
        "<TOPIC_ID>",
        messaging.WithUpdateTopicName("<NAME>"),
        messaging.WithUpdateTopicSubscribe(interface{}{"any"}),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
