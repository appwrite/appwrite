import android.app.Activity;
import android.os.Bundle;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;

import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Account;

public class MainActivity extends Activity {
  @Override
  protected void onCreate(Bundle savedInstanceState) {
    super.onCreate(savedInstanceState);
    showHome();
  }

  void showHome() {
    setContentView(createHome());
  }

  void showSignIn() {
    setContentView(SignIn.create(this, this::showHome, this::showSignUp));
  }

  void showSignUp() {
    setContentView(SignUp.create(this, this::showHome, this::showSignIn));
  }

  private View createHome() {
    LinearLayout layout = new LinearLayout(this);
    layout.setOrientation(LinearLayout.VERTICAL);
    int pad = (int) (24 * getResources().getDisplayMetrics().density);
    layout.setPadding(pad, pad, pad, pad);

    TextView status = new TextView(this);
    status.setText("Loading...");
    layout.addView(status);

    Account account = new Account(AppwriteClient.get(this));
    try {
      account.get(new CoroutineCallback<>((user, error) ->
          layout.post(() -> {
            layout.removeAllViews();
            if (user == null) {
              addSignedOutHome(layout);
            } else {
              addSignedInHome(layout, user.getName());
            }
          })));
    } catch (Exception ignored) {}
    return layout;
  }

  private void addSignedOutHome(LinearLayout layout) {
    TextView cta = new TextView(this);
    cta.setText("Sign in to get started.");
    layout.addView(cta);

    Button signIn = new Button(this);
    signIn.setText("Sign in");
    signIn.setAllCaps(false);
    signIn.setOnClickListener(v -> showSignIn());
    layout.addView(signIn);

    Button signUp = new Button(this);
    signUp.setText("Sign up");
    signUp.setAllCaps(false);
    signUp.setOnClickListener(v -> showSignUp());
    layout.addView(signUp);
  }

  private void addSignedInHome(LinearLayout layout, String name) {
    TextView greeting = new TextView(this);
    greeting.setText("Hello, " + name);
    layout.addView(greeting);

    Button signOut = new Button(this);
    signOut.setText("Sign out");
    signOut.setAllCaps(false);
    signOut.setOnClickListener(v -> {
      Account account = new Account(AppwriteClient.get(this));
      try {
        account.deleteSession("current",
            new CoroutineCallback<>((result, error) ->
                layout.post(() -> {
                  if (error == null) showHome();
                })));
      } catch (Exception ignored) {}
    });
    layout.addView(signOut);
  }
}
