package main

import (
    "fmt"
    "os"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    var client := appwrite.Client{}

    client.SetProject("")
    client.SetKey("")

    var service := appwrite.Database{
        client: &client
    }

    var response, error := service.UpdateCollection("[COLLECTION_ID]", "[NAME]", [], [])

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}