# Storage Examples

## UpdateFile

```go
    package appwrite-updatefile

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

        // Call UpdateFile method and handle results
        var res, err := srv.UpdateFile("[FILE_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```