# Database Examples

## CreateCollection

```go
    package appwrite-createcollection

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

        // Call CreateCollection method and handle results
        var res, err := srv.CreateCollection("[NAME]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```