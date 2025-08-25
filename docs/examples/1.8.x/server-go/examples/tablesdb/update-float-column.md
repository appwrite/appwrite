package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/tablesdb"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := tablesdb.New(client)
    response, error := service.UpdateFloatColumn(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        "",
        false,
        0,
        tablesdb.WithUpdateFloatColumnMin(0),
        tablesdb.WithUpdateFloatColumnMax(0),
        tablesdb.WithUpdateFloatColumnNewKey(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
