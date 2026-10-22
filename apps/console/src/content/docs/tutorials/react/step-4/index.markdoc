---
layout: tutorial
title: Add authentication
description: Add authentication to your react application.
step: 4
---

## User context {% #user-context %}

In React, you can use [context](https://reactjs.org/docs/context.html) to share data between components.
We'll use context and a custom hook to manage our user's data.

Create a new file `src/lib/context/user.jsx` and add the following code to it.

```client-web
import { ID } from "appwrite";
import { createContext, useContext, useEffect, useState } from "react";
import { account } from "../appwrite";

const UserContext = createContext();

export function useUser() {
  return useContext(UserContext);
}

export function UserProvider(props) {
  const [user, setUser] = useState(null);

  async function login(email, password) {
    const loggedIn = await account.createEmailPasswordSession({
        email,
        password
    });
    setUser(loggedIn);
    window.location.replace("/");
  }

  async function logout() {
    await account.deleteSession({
        sessionId: "current"
    });
    setUser(null);
  }

  async function register(email, password) {
    await account.create({
        userId: ID.unique(),
        email,
        password
    });
    await login(email, password);
  }

  async function init() {
    try {
      const loggedIn = await account.get();
      setUser(loggedIn);
    } catch (err) {
      setUser(null);
    }
  }

  useEffect(() => {
    init();
  }, []);

  return (
    <UserContext.Provider value={{ current: user, login, logout, register }}>
      {props.children}
    </UserContext.Provider>
  );
};
```

Now, we can use the `useUser` hook to access the user's data from any component. However, we first need to wrap our app with the `UserProvider`.

## Basic routing  {% #basic-routing %}

First, wrap the `main` element with the `UserProvider` component.

Update `src/App.jsx` to the following code.

```client-web
import { Home } from "./pages/Home";
import { UserProvider } from "./lib/context/user";

function App() {
  const isLoginPage = window.location.pathname === "/login";

  return (
    <div>
      <UserProvider>
        <main>Home page</main>
      </UserProvider>
    </div>
  );
}

export default App;
```

Then, optionally render the `Login` component if the path is `/login`, otherwise render the `Home` component.

Update `src/App.jsx` to the following code.

```client-web
import { Login } from "./pages/Login";
import { Home } from "./pages/Home";
import { UserProvider } from "./lib/context/user";

function App() {
  const isLoginPage = window.location.pathname === "/login";

  return (
    <div>
      <UserProvider>
        <main>{isLoginPage ? <Login /> : <Home />}</main>
      </UserProvider>
    </div>
  );
}

export default App;
```

## Home page  {% #home-page %}

Create a new file `src/pages/Home.jsx` and add the following stub code to it.

```js
// We'll complete this component later
export function Home() {
  return (
    <p>I'm the home page</p>
  );
}
```

## Login page {% #login-page %}

Finally, we are able to create the login page. Users will be able to login or register from this page, so we'll need to add two buttons.

Create a new file `src/pages/Login.jsx` and add the following code to it.

```client-web
import { useState } from "react";
import { useUser } from "../lib/context/user";

export function Login() {
  const user = useUser();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");

  return (
    <section>
      <h1>Login or register</h1>
      <form>
        <input
          type="email"
          placeholder="Email"
          value={email}
          onChange={(event) => {
            setEmail(event.target.value);
          }}
        />
        <input
          type="password"
          placeholder="Password"
          value={password}
          onChange={(event) => {
            setPassword(event.target.value);
          }}
        />
        <div>
          <button
            className="button"
            type="button"
            onClick={() => user.login(email, password)}
          >
            Login
          </button>
          <button
            className="button"
            type="button"
            onClick={() => user.register(email, password)}
          >
            Register
          </button>
        </div>
      </form>
    </section>
  );
}
```