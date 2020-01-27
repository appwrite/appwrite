# Storage Examples

## GetFile

```go
    package appwrite-getfile

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

        // Call GetFile method and handle results
        var res, err := srv.GetFile("[FILE_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```