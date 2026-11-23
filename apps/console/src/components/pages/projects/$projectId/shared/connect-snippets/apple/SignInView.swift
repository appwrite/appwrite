import Appwrite
import SwiftUI

struct SignInView: View {
  let onSignedIn: () -> Void
  let onGoToSignUp: () -> Void

  @State private var email = ""
  @State private var password = ""
  @State private var error = ""

  var body: some View {
    Text("Sign in")
    if !error.isEmpty {
      Text(error)
    }

    TextField("Email", text: $email)
      .keyboardType(.emailAddress)
      .textInputAutocapitalization(.never)
    SecureField("Password", text: $password)

    Button("Sign in", action: submit)

    HStack {
      Text("No account?")
      Button("Sign up", action: onGoToSignUp)
    }
  }

  private func submit() {
    guard !email.isEmpty, !password.isEmpty else { return }
    error = ""
    Task {
      do {
        _ = try await Account(client).createEmailPasswordSession(
          email: email,
          password: password
        )
        onSignedIn()
      } catch let error as AppwriteError {
        self.error = error.message
      } catch {
        self.error = "Sign in failed"
      }
    }
  }
}
