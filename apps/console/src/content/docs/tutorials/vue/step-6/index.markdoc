---
layout: tutorial
title: Add database
description: Add data storage to your Vue.js project powered by Appwrite Cloud databases.
step: 6
---
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

## Ideas context {% #ideas-context %}

Now that you have a table to hold ideas, we can read and write to it from our app. Like we did with the user data, we will create a store hold our ideas.
Create a new file `src/lib/stores/ideas.js` and add the following code to it.

```client-web
import { ID, Query } from "appwrite";
import { databases } from "../appwrite";
import { reactive } from "vue";

export const IDEAS_DATABASE_ID = "<YOUR_DATABASE_ID>"; // Replace with your database ID
export const IDEAS_TABLE_ID = "<YOUR_TABLE_ID>"; // Replace with your table ID

export const ideas = reactive({
  current: [],
  async init() {
    const response = await tablesDB.listRows({
      databaseId: IDEAS_DATABASE_ID,
      tableId: IDEAS_TABLE_ID,
      queries: [Query.orderDesc("$createdAt"), Query.limit(10)]
    });
    this.current = response.rows;
  },
  async add(idea) {
    const response = await tablesDB.createRow({
      databaseId: IDEAS_DATABASE_ID,
      tableId: IDEAS_TABLE_ID,
      rowId: ID.unique(),
      data: idea
    });
    this.current = [response, ...this.current].slice(0, 10);
  },
  async remove(id) {
    await tablesDB.deleteRow({
      databaseId: IDEAS_DATABASE_ID,
      tableId: IDEAS_TABLE_ID,
      rowId: id
    });
    this.current = this.current.filter((idea) => idea.$id !== id);
    await this.init(); // Refetch ideas to ensure we have 10 items
  },
});
```

Note the init function, which uses `Query` to fetch the last 10 ideas from the database. We will call this function when the app starts to ensure we have some data to display.
