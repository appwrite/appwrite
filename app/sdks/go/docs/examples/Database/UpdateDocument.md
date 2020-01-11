# Database Examples

## UpdateDocument

```go
    package appwrite-updatedocument

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

        // Call UpdateDocument method and handle results
        var res, err := srv.UpdateDocument("[COLLECTION_ID]", "[DOCUMENT_ID]", "{}")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```