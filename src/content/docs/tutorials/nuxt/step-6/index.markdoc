---
layout: tutorial
title: Add database
description: Add databases and queries for ideas in your Nuxt project.
step: 6
---

In Appwrite, data is stored as a table of rows. 
Create a new database and table in the [Appwrite Console](https://cloud.appwrite.io/) to store the ideas.

{% only_dark %}
![Create table screen](/images/docs/tutorials/dark/idea-tracker-table.avif)
{% /only_dark %}
{% only_light %}
![Create table screen](/images/docs/tutorials/idea-tracker-table.avif)
{% /only_light %}

Create a new table with the following columns:
| Field       | Type   | Required | Size     |
|-------------|--------|----------|----------|
| userId      | Varchar | Yes      | 200      |
| title       | Varchar | Yes      | 200      |
| description | Text    | No       | -        |

Change the table's permissions in the settings to give access.

{% only_dark %}
![Table permissions screen](/images/docs/tutorials/dark/idea-tracker-permissions.avif)
{% /only_dark %}
{% only_light %}
![Table permissions screen](/images/docs/tutorials/idea-tracker-permissions.avif)
{% /only_light %}

Navigate to the **Settings** tab of your table, add the role **Any** and check the **Read** box.
Next, add a **Users** role and give them access to **Create**, **Update** and **Delete** by checking those boxes.

## Environment variables {% #environment-variables %}

Just like when we set up the connection to Appwrite in [step 3](/docs/tutorials/nuxt/step-3), we need to keep the variables with the table id secret.
Open the `.env` file and add your database id and your table id to it.

```
VITE_DATABASE_ID="YOUR_DATABASE_ID"
VITE_TABLE_ID="YOUR_TABLE_ID"
```

## Query methods {% #query-methods %}

Now that we have a table in the database to hold ideas, we can connect to it from our app.
Our users should be able to read, add and remove ideas.
We will add a new composable, `useIdeas`, to handle this functionality.

Create a new file in the composables directory, `useIdeas.js` and add the following code.

```ts
// composables/useIdeas.ts

import { ID, Query, Models} from "appwrite";
import { tablesDB } from "~/appwrite";
import { ref } from "vue";

const ideasDatabaseId: string = import.meta.env.VITE_DATABASE_ID;
const ideasTableId: string = import.meta.env.VITE_TABLE_ID;
const queryLimit: number = 10;

interface Idea extends Models.Row{
    title: string;
    description: string;
    userId: string;
}

const current = ref<Idea[] | null>(null); // Reference for the fetched data

export const useIdeas = () => {

    // Fetch the 10 most recent ideas from the database
    // Add the list to the current reference object
    const fetch = async (): Promise<void> => {
        const response = await tablesDB.listRows({
            databaseId: ideasDatabaseId,
            tableId: ideasTableId,
            queries: [Query.orderDesc("$createdAt"), Query.limit(queryLimit)]
        });
        current.value = response.rows as Idea[];
    };

    // Add new idea to the database,
    // Change the value of the current object
    const add = async (idea: Idea): Promise<void> => {
        const response = await tablesDB.createRow({
            databaseId: ideasDatabaseId,
            tableId: ideasTableId,
            rowId: ID.unique(),
            data: idea
        });
        current.value = [response, ...(current.value as Idea[])].slice(0, 10) as Idea[];
    };

    const remove = async (id: string): Promise<void> => {
        await tablesDB.deleteRow({
            databaseId: ideasDatabaseId,
            tableId: ideasTableId,
            rowId: id
        });
        await fetch(); // Refetch ideas to ensure we have 10 items
    };

    fetch();

    return {
        add,
        current,
        fetch,
        remove,
    };
};
```

Now we can call the `useIdeas` composable from the home page.
