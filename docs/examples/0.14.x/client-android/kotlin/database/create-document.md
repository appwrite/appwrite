import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import kotlinx.coroutines.GlobalScope
import kotlinx.coroutines.launch
import io.appwrite.Client
import io.appwrite.services.Database

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        val client = Client(applicationContext)
            .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
            .setProject("5df5acd0d48c2") // Your project ID

        val database = Database(client)

        GlobalScope.launch {
            val response = database.createDocument(
                collectionId = "[COLLECTION_ID]",
                documentId = "[DOCUMENT_ID]",
                data = mapOf( "a" to "b" ),
            )
            val json = response.body?.string()        
        }
    }
}