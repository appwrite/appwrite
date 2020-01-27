# Locale Examples

## GetCurrencies

```go
    package appwrite-getcurrencies

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

        // Create a new Locale service passing Client
        var srv := appwrite.Locale{
            client: &clt
        }

        // Call GetCurrencies method and handle results
        var res, err := srv.GetCurrencies()
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```