import androidx.appcompat.app.AppCompatActivity<br/>
import android.os.Bundle<br/>
import kotlinx.coroutines.GlobalScope<br/>
import kotlinx.coroutines.launch<br/>
import io.appwrite.Client<br/>
import io.appwrite.services.Account<br/>

public class MainActivity extends AppCompatActivity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        Client client = new Client(getApplicationContext())
            .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
            .setProject("5df5acd0d48c2"); // Your project ID

        Account account = new Account(client);

        account.createAnonymousSession(new Continuation<Object>() {
            @NotNull
            @Override
            public CoroutineContext getContext() {
                return EmptyCoroutineContext.INSTANCE;
            }

            @Override
            public void resumeWith(@NotNull Object o) {
                String json = "";
                try {
                    if (o instanceof Result.Failure) {
                        Result.Failure failure = (Result.Failure) o;
                        throw failure.exception;
                    } else {
                            Response response = (Response) o;
                            json = response.body().string();
                        }                    
                    }
                } catch (Throwable th) {
                    Log.e("ERROR", th.toString());
                }
            }
        });
    }
}
