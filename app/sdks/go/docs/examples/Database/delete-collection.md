# Database Examples

## DeleteCollection

```go
    package appwrite-deletecollection

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

        // Call DeleteCollection method and handle results
        var res, err := srv.DeleteCollection("[COLLECTION_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```