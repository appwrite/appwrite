package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/functions"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithSession("")
)

service := functions.New(client)

response, error := service.CreateExecution(
    "<FUNCTION_ID>",
    functions.WithCreateExecutionBody("<BODY>"),
    functions.WithCreateExecutionAsync(false),
    functions.WithCreateExecutionPath("<PATH>"),
    functions.WithCreateExecutionMethod("GET"),
    functions.WithCreateExecutionHeaders(map[string]interface{}{}),
    functions.WithCreateExecutionScheduledAt("<SCHEDULED_AT>"),
)
