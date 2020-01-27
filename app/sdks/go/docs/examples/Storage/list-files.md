# Storage Examples

## ListFiles

```go
    package appwrite-listfiles

    import (
        "fmt"
        "os"
        "github.com/appwrite/sdk-for-go"
    )

    func main() {
        // Create a Client
        var clt := appwrite.Client{}

        // Set Client required headers
        clt.SetProject("")

        // Create a new Storage service passing Client
        var srv := appwrite.Storage{
            client: &clt
        }

        // Call ListFiles method and handle results
        var res, err := srv.ListFiles()
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```