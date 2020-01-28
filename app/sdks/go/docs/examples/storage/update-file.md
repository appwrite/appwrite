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

    var service := appwrite.Storage{
        client: &client
    }

    var response, error := service.UpdateFile("[FILE_ID]", [], [])

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}