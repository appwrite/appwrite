import android.content.Context;
import android.text.InputType;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Account;

public final class SignIn {
  private SignIn() {}

  public static View create(
      Context context, Runnable onSignedIn, Runnable onGoToSignUp) {
    LinearLayout layout = new LinearLayout(context);
    layout.setOrientation(LinearLayout.VERTICAL);
    int pad = (int) (24 * context.getResources().getDisplayMetrics().density);
    layout.setPadding(pad, pad, pad, pad);

    TextView heading = new TextView(context);
    heading.setText("Sign in");

    TextView error = new TextView(context);
    error.setVisibility(View.GONE);

    EditText email = new EditText(context);
    email.setHint("Email");
    email.setInputType(InputType.TYPE_CLASS_TEXT
        | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);

    EditText password = new EditText(context);
    password.setHint("Password");
    password.setInputType(InputType.TYPE_CLASS_TEXT
        | InputType.TYPE_TEXT_VARIATION_PASSWORD);

    Button submit = new Button(context);
    submit.setText("Sign in");
    submit.setAllCaps(false);
    submit.setOnClickListener(v -> {
      String emailValue = email.getText().toString();
      String passwordValue = password.getText().toString();
      if (emailValue.isEmpty() || passwordValue.isEmpty()) return;
      error.setVisibility(View.GONE);

      Account account = new Account(AppwriteClient.get(context));
      try {
        account.createEmailPasswordSession(emailValue, passwordValue,
            new CoroutineCallback<>((session, e) -> layout.post(() -> {
              if (e == null) {
                onSignedIn.run();
              } else {
                showError(error, e, "Sign in failed");
              }
            })));
      } catch (Exception ignored) {}
    });

    TextView hint = new TextView(context);
    hint.setText("No account?");

    Button goToSignUp = new Button(context);
    goToSignUp.setText("Sign up");
    goToSignUp.setAllCaps(false);
    goToSignUp.setOnClickListener(v -> onGoToSignUp.run());

    layout.addView(heading);
    layout.addView(error);
    layout.addView(email);
    layout.addView(password);
    layout.addView(submit);
    layout.addView(hint);
    layout.addView(goToSignUp);
    return layout;
  }

  private static void showError(TextView error, Throwable e, String fallback) {
    error.setText(e.getMessage() != null ? e.getMessage() : fallback);
    error.setVisibility(View.VISIBLE);
  }
}
