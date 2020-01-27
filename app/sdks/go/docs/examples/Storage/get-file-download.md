# Storage Examples

## GetFileDownload

```go
    package appwrite-getfiledownload

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

        // Call GetFileDownload method and handle results
        var res, err := srv.GetFileDownload("[FILE_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```