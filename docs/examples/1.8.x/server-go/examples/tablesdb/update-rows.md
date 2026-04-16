package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/tablesdb"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithKey("<YOUR_API_KEY>")
)

service := tablesdb.New(client)

response, error := service.UpdateRows(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    tablesdb.WithUpdateRowsData(map[string]interface{}{
        "username": "walter.obrien",
        "email": "walter.obrien@example.com",
        "fullName": "Walter O'Brien",
        "age": 33,
        "isAdmin": false
    }),
    tablesdb.WithUpdateRowsQueries([]interface{}{}),
    tablesdb.WithUpdateRowsTransactionId("<TRANSACTION_ID>"),
)
