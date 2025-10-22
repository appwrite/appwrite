package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/tablesdb"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithSession("")
)

service := tablesdb.New(client)

response, error := service.GetRow(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    "<ROW_ID>",
    tablesdb.WithGetRowQueries([]interface{}{}),
    tablesdb.WithGetRowTransactionId("<TRANSACTION_ID>"),
)
