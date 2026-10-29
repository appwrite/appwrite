---
layout: tutorial
title: Add routing 
description: Add routing to your React Native applicating using Appwrite.
step: 5
---
In this step, you'll add some basic routing to your app. 
Based on the user's login status, you'll redirect them to the login page or the home page.

## Home page  {% #home-page %}

Create a new file `views/Home.jsx` and add the following stub code to it.
We'll update this page later to display the ideas posted by other users and allow the user to post their ideas.

```client-react-native
import React from 'react';
import { View, Text } from 'react-native';
import { useUser } from '../contexts/UserContext';


export default function HomeScreen() {
    const user = useUser();

    console.log(user.current);
    return (
        <View>
           <Text>
                Welcome, {user.current ? user.current.email : 'Please login'}
           </Text>
        </View>
    );
}
```

## Basic routing  {% #basic-routing %}
To handle basic routing, you can use the `react-navigation` library.
This router also consumes the `UserContext` to determine if the user is logged in or not to redirect the user automatically.

Create a file `lib/Router.jsx` and add the following code to it.  
```client-react-native
import { NavigationContainer } from '@react-navigation/native';
import LoginScreen from '../views/Login';
import HomeScreen from '../views/Home';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { useUser } from '../contexts/UserContext';

const Stack = createNativeStackNavigator();
export function Router() {
    const user = useUser();
    return (
        <NavigationContainer>
            <Stack.Navigator>
                {user.current == null ? (
                    <Stack.Screen
                        name="Login"
                        component={LoginScreen}
                        options={{ title: 'Login' }}
                    />
                ) : (
                    <Stack.Screen
                        name="Home"
                        component={HomeScreen}
                        options={{ title: 'Home' }}
                    />
                )}

            </Stack.Navigator>
        </NavigationContainer>
    );
}
```

We'll display this router in the `App.js` file.

```client-react-native

import { StyleSheet, Text, View } from 'react-native';
import { UserProvider } from './contexts/UserContext';
import { Router } from './lib/Router';

export default function App() {
  return (
    <UserProvider>
        <Router />
    </UserProvider >
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#fff',
    alignItems: 'center',
    justifyContent: 'center',
  },
});
```

## Test the routing  {% #test-routing %}
Now, if you run the app, you should see the login page.
If you create a new account, you should be logged in and redirected to the home page.

Run the app using the following command.
```sh
npm run start
```