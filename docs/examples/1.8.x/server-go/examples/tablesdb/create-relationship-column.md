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
    response, error := service.CreateRelationshipColumn(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        "<RELATED_TABLE_ID>",
        "oneToOne",
        tablesdb.WithCreateRelationshipColumnTwoWay(false),
        tablesdb.WithCreateRelationshipColumnKey(""),
        tablesdb.WithCreateRelationshipColumnTwoWayKey(""),
        tablesdb.WithCreateRelationshipColumnOnDelete("cascade"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
