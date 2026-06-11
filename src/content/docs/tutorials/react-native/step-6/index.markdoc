---
layout: tutorial
title: Add database
description: Connect a database to your React Native application using Appwrite Web SDK.
step: 6
---
In this step, you'll set up a database to store ideas in Appwrite, configure permissions, then create a
context to manage ideas in your React Native app.

# Create table {% #create-table %}
In Appwrite, data is stored as a table of rows. Create a table in the [Appwrite Console](https://cloud.appwrite.io/) to store our ideas.

{% only_dark %}
![Create project screen](/images/docs/tutorials/dark/idea-tracker-table.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/tutorials/idea-tracker-table.avif)
{% /only_light %}

Create a new table with the following columns:
| Field       | Type   | Required |
|-------------|--------|----------|
| userId      | Varchar | Yes      |
| title       | Varchar | Yes      |
| description | Text    | No       |

## Configure permissions {% #configure-permissions %}
{% only_dark %}
![Table permissions screen](/images/docs/tutorials/dark/idea-tracker-permissions.avif)
{% /only_dark %}
{% only_light %}
![Table permissions screen](/images/docs/tutorials/idea-tracker-permissions.avif)
{% /only_light %}

Navigate to the **Settings** tab of your table, add the role **Any** and check the **Read** box.
Next, add a **Users** role and give them access to **Create** by checking those boxes.
These permissions apply to all rows in your new table.

Finally, enable **Row security** to allow further permissions to be set at the row level.
Remember to click the **Update** button to apply your changes.

## Ideas context {% #ideas-context %}

Now that you have a table to hold ideas, we can read and write to it from our app.
Like you did with the user data, we will create a React context to hold our ideas.
Create a new file `contexts/IdeasContext.jsx` and add the following code to it.

```client-react-native
import { ID, Permission, Role, Query } from "react-native-appwrite";
import { createContext, useContext, useEffect, useState } from "react";
import { tablesDB } from "../lib/appwrite";
import { toast } from "../lib/toast";

export const IDEAS_DATABASE_ID = "default"; // Replace with your database ID
export const IDEAS_TABLE_ID = "ideas-tracker"; // Replace with your table ID

const IdeasContext = createContext();

export function useIdeas() {
  return useContext(IdeasContext);
}

export function IdeasProvider(props) {
  const [ideas, setIdeas] = useState([]);

  async function add(idea) {
    const response = await tablesDB.createRow({
      databaseId: IDEAS_DATABASE_ID,
      tableId: IDEAS_TABLE_ID,
      rowId: ID.unique(),
      data: idea,
      permissions: [Permission.write(Role.user(idea.userId))]
    });
    toast('Ideas added');
    setIdeas((ideas) => [response, ...ideas].slice(0, 10));
  }

  async function remove(id) {
    await tablesDB.deleteRow({
      databaseId: IDEAS_DATABASE_ID,
      tableId: IDEAS_TABLE_ID,
      rowId: id
    });
    toast('Idea removed');
    setIdeas((ideas) => ideas.filter((idea) => idea.$id !== id));
    await init(); // Refetch ideas to ensure we have 10 items
  }

  async function init() {
    const response = await tablesDB.listRows({
      databaseId: IDEAS_DATABASE_ID,
      tableId: IDEAS_TABLE_ID,
      queries: [Query.orderDesc("$createdAt"), Query.limit(10)]
    });
    setIdeas(response.rows);
  }

  useEffect(() => {
    init();
  }, []);

  return (
    <IdeasContext.Provider value={{ current: ideas, add, remove }}>
      {props.children}
    </IdeasContext.Provider>
  );
}
```

Notice that new ideas have the added permission `Permission.write(Role.user(idea.userId))`.
This permission ensures that only the user who created the idea can modify it.

Remeber to add the `IdeasProvider` to your `App.js` file.

```client-react-native

import { StyleSheet, Text, View } from 'react-native';
import { UserProvider } from './contexts/UserContext';
import { IdeasProvider } from './contexts/IdeasContext'; // Add import
import { Router } from './lib/Router';

export default function App() {
  return (
    <UserProvider>
      <!-- Add the Ideas Provider -->
      <IdeasProvider>
        <Router />
      </IdeasProvider>
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