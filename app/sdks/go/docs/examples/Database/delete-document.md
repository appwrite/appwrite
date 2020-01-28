# Database Examples

## DeleteDocument

```go
    package appwrite-deletedocument

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

        // Create a new Database service passing Client
        var srv := appwrite.Database{
            client: &clt
        }

        // Call DeleteDocument method and handle results
        var res, err := srv.DeleteDocument("[COLLECTION_ID]", "[DOCUMENT_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```