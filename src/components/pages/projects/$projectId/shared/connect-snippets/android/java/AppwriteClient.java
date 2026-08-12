import android.content.Context;

import io.appwrite.Client;

public final class AppwriteClient {
  private static volatile Client instance;

  private AppwriteClient() {}

  public static Client get(Context context) {
    if (instance == null) {
      synchronized (AppwriteClient.class) {
        if (instance == null) {
          // The endpoint is passed through the constructor instead of
          // setEndpoint(): the SDK's Client exposes both a fluent
          // setEndpoint() and a generated property setter with the same
          // signature, which javac cannot disambiguate.
          String endpoint = BuildConfig.APPWRITE_ENDPOINT;
          instance = new Client(
                  context.getApplicationContext(),
                  endpoint,
                  endpoint.replaceFirst("http", "ws"))
              .setProject(BuildConfig.APPWRITE_PROJECT_ID);
        }
      }
    }
    return instance;
  }
}
