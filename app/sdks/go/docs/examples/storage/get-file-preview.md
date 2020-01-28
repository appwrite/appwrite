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

    // Create a new Storage service passing Client
    var srv := appwrite.Storage{
        client: &client
    }

    // Call GetFilePreview method and handle results
    var res, err := srv.GetFilePreview("[FILE_ID]")
    if err != nil {
        panic(err)
    }

    fmt.Println(res)
}