# Storage Examples

## CreateFile

```go
    package appwrite-createfile

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

        // Call CreateFile method and handle results
        var res, err := srv.CreateFile(file)
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```