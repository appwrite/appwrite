# Database Examples

## GetCollection

```go
    package appwrite-getcollection

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

        // Create a new Database service passing Client
        var srv := appwrite.Database{
            client: &clt
        }

        // Call GetCollection method and handle results
        var res, err := srv.GetCollection("[COLLECTION_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```