package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/tables"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := tables.New(client)
    response, error := service.CreateEmailColumn(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        "",
        false,
        tables.WithCreateEmailColumnDefault("email@example.com"),
        tables.WithCreateEmailColumnArray(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
