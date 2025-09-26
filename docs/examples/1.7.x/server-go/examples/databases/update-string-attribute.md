package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/databases"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := databases.New(client)
    response, error := service.UpdateStringAttribute(
        "<DATABASE_ID>",
        "<COLLECTION_ID>",
        "",
        false,
        "<DEFAULT>",
        databases.WithUpdateStringAttributeSize(1),
        databases.WithUpdateStringAttributeNewKey(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
