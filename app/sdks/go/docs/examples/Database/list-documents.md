# Database Examples

## ListDocuments

```go
    package appwrite-listdocuments

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

        // Call ListDocuments method and handle results
        var res, err := srv.ListDocuments("[COLLECTION_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```