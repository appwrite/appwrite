# Storage Examples

## DeleteFile

```go
    package appwrite-deletefile

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
        clt.SetKey("")

        // Create a new Storage service passing Client
        var srv := appwrite.Storage{
            client: &clt
        }

        // Call DeleteFile method and handle results
        var res, err := srv.DeleteFile("[FILE_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```