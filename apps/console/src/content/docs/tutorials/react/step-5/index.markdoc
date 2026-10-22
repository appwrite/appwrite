---
layout: tutorial
title: Add navigation 
description: Add navigation to your React applicating using Appwrite.
step: 5
---

In our app we want to have a navigation bar that is always visible. We will add it to the `App` component and use the `useUser` hook to show either:
- a logout button if the user is logged in.
- a login button if the user is not logged in.

Update the App component in `src/App.jsx`:

```client-web
import { Login } from "./pages/Login";
import { Home } from "./pages/Home";
import { UserProvider, useUser } from "./lib/context/user";

function App() {
  const isLoginPage = window.location.pathname === "/login";

  return (
    <div>
      <UserProvider>
        <Navbar /> {/* Add the navbar before page content */}
        <main>{isLoginPage ? <Login /> : <Home />}</main>
      </UserProvider>
    </div>
  );
}

function Navbar() {
  const user = useUser();

  return (
    <nav>
      <a href="/">Idea tracker</a>
      <div>
        {user.current ? (
          <>
            <span>{user.current.email}</span>
            <button type="button" onClick={() => user.logout()}>
              Logout
            </button>
          </>
        ) : (
          <a href="/login">Login</a>
        )}
      </div>
    </nav>
  );
}

export default App;
```
