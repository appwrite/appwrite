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
    response, error := service.UpdateIntegerColumn(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        "",
        false,
        0,
        tables.WithUpdateIntegerColumnMin(0),
        tables.WithUpdateIntegerColumnMax(0),
        tables.WithUpdateIntegerColumnNewKey(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
