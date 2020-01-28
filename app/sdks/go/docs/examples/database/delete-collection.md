package main

import (
    "fmt"
    "os"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    // Create a Client
    var client := appwrite.Client{}

    // Set Client required headers
    client.SetProject("")
    client.SetKey("")

    // Create a new Database service passing Client
    var srv := appwrite.Database{
        client: &client
    }

    // Call DeleteCollection method and handle results
    var res, err := srv.DeleteCollection("[COLLECTION_ID]")
    if err != nil {
        panic(err)
    }

    fmt.Println(res)
}