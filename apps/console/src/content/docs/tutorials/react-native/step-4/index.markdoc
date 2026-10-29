---
layout: tutorial
title: Add authentication
description: Add authentication to your React Native application.
step: 4
---

## User context {% #user-context %}

In React Native, you can use [context](https://reactjs.org/docs/context.html) to share data between components.
You can use a context and a custom hook to manage our user's data.

Create a new file `contexts/UserContext.jsx` and add the following code to it.

```client-react-native
import { ID } from "react-native-appwrite";
import { createContext, useContext, useEffect, useState } from "react";
import { account } from "../lib/appwrite";
import { toast } from "../lib/toast";


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
    toast('Welcome back. You are logged in');
  }

  async function logout() {
    await account.deleteSession({ sessionId: "current" });
    setUser(null);
    toast('Logged out');
  }

  async function register(email, password) {
    await account.create({
      userId: ID.unique(),
      email,
      password
    });
    await login(email, password);
    toast('Account created');
  }

  async function init() {
    try {
      const loggedIn = await account.get();
      setUser(loggedIn);
      toast('Welcome back. You are logged in');
    } catch (err) {
      setUser(null);
    }
  }

  useEffect(() => {
    init();
  }, []);

  return (
    <UserContext.Provider value={{ current: user, login, logout, register, toast }}>
      {props.children}
    </UserContext.Provider>
  );
}
```

Now, you can use the `useUser` hook to access the user's data from any component wrapped by this context's provider.

## Display toasts {% #display-toasts %}

For a better user experience, display toasts when the users perform an action, such as login, logout, create new ideas, etc.

We can do this by creating a new file `lib/toast.js` and adding the following code to it.

```client-react-native
import { ToastAndroid, Platform, AlertIOS } from 'react-native';

export function toast(msg) {
    if (Platform.OS === 'android') {
        ToastAndroid.show(msg, ToastAndroid.SHORT)
    } else {
        AlertIOS.alert(msg);
    }
}
```
## Login page {% #login-page %}

Create a new file `views/Login.jsx` and add the following code to it.
this page contains a basic form to allow the user to login or register.
Notice how this page consumes the `useUser` hook to access the user's data and perform login and register actions.

```client-react-native
import React, { useState } from 'react';
import { View, Text, TextInput, Button, StyleSheet } from 'react-native';
import { useUser } from '../contexts/UserContext';


export default function LoginScreen() {
    const user = useUser();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    return (
        <View style={styles.container}>
            <Text style={styles.header}>Login or register</Text>
            <TextInput
                style={styles.input}
                placeholder="Email"
                value={email}
                onChangeText={setEmail}
            />
            <TextInput
                style={styles.input}
                placeholder="Password"
                value={password}
                onChangeText={setPassword}
                secureTextEntry
            />
            <View style={styles.buttonContainer}>
                <Button
                    title="Login"
                    onPress={
                        () => {
                            user.login(email, password)
                        }
                    }
                />
                <Button
                    title="Register"
                    onPress={
                        () => {
                            user.register(email, password)
                        }
                    }
                />
            </View>
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        justifyContent: 'center',
        padding: 16,
    },
    header: {
        fontSize: 24,
        marginBottom: 20,
    },
    input: {
        height: 40,
        borderColor: 'gray',
        borderWidth: 1,
        marginBottom: 10,
        paddingLeft: 8,
    },
    buttonContainer: {
        flexDirection: 'row',
        justifyContent: 'space-between',
    },
});
```