---
layout: tutorial
title: Add database
description: Add a database to your React application using Appwrite Web SDK.
step: 6
---
## Create database {% #create-database %}
To store your ideas, you need to create a database first.

1. Go to the Databases section in your Appwrite Console
2. Click *Create Database*
3. Give it a name and ID. For this tutorial, we'll use `Ideas Tracker` as the name and `ideas-tracker` as the ID.
4. You'll need to remember the database ID as you'll need it later.

# Create table {% #create-table %}
In Appwrite, data is stored as a table of rows. Create a table in the [Appwrite Console](https://cloud.appwrite.io/) to store our ideas.

{% only_dark %}
![Create project screen](/images/docs/tutorials/dark/idea-tracker-table.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/tutorials/idea-tracker-table.avif)
{% /only_light %}

## Columns

Create a new table with the following columns:
| Field       | Type   | Required | Size   |
|-------------|--------|----------|--------|
| userId      | Varchar | Yes      |  50    |
| title       | Varchar | Yes      |  25    |
| description | Text    | No       |  -     |

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

## Ideas context {% #ideas-context %}

Now that you have a table to hold ideas, we can read and write to it from our app. Like we did with the user data, we will create a React context to hold our ideas.
Create a new file `src/lib/context/ideas.jsx` and add the following code to it.

```client-web
import { createContext, useContext, useEffect, useState } from "react";
import { databases } from "../appwrite";
import { ID, Query } from "appwrite";

export const IDEAS_DATABASE_ID = "<YOUR_DATABASE_ID>"; // Replace with your database ID
export const IDEAS_TABLE_ID = "<YOUR_TABLE_ID>"; // Replace with your table ID

const IdeasContext = createContext();

export function useIdeas() {
  return useContext(IdeasContext);
}

export function IdeasProvider(props) {
  const [ideas, setIdeas] = useState([]);

  async function add(idea) {
    try {
      const response = await tablesDB.createRow({
        databaseId: IDEAS_DATABASE_ID,
        tableId: IDEAS_TABLE_ID,
        rowId: ID.unique(),
        data: idea
      });
      setIdeas((ideas) => [response, ...ideas].slice(0, 10));
    } catch (err) {
      console.log(err) // handle error or show user a message
    }
  }

  async function remove(id) {
    try {
      await tablesDB.deleteRow({
        databaseId: IDEAS_DATABASE_ID,
        tableId: IDEAS_TABLE_ID,
        rowId: id
      });
      setIdeas((ideas) => ideas.filter((idea) => idea.$id !== id));
      await init();
    } catch (err) {
      console.log(err)
    }
  }

  async function init() {
    try {
      const response = await tablesDB.listRows({
        databaseId: IDEAS_DATABASE_ID,
        tableId: IDEAS_TABLE_ID,
        queries: [Query.orderDesc("$createdAt"), Query.limit(10)]
      });
      setIdeas(response.rows);
    } catch (err) {
      console.log(err)
    }
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
