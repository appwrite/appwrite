---
layout: tutorial
title: Add database
description: Add databases and queries to store user data in you SvelteKit project.
step: 6
---
# Create table {% #create-table %}
In Appwrite, data is stored as a table of rows.
Create a table inside a database in the [Appwrite Console](https://cloud.appwrite.io/) to store our ideas.

{% only_dark %}
![Create project screen](/images/docs/tutorials/dark/idea-tracker-table.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/tutorials/idea-tracker-table.avif)
{% /only_light %}

Create the following columns within the table:
| Field       | Type   | Size | Required |
| ----------- | ------ | ---- | -------- |
| userId      | Varchar | 36   | Yes      |
| title       | Varchar | 128  | Yes      |
| description | Text    | -    | No       |

For this tutorial, we'll also set the permissions to allow any users to read and write to the table.
In a real-world scenario, you would probably want to restrict access to a certain group of users.

## Managing ideas {% #getting-ideas %}

Now that you have a table to hold ideas, we can read and write to it from our app.

Let's create some methods to manage ideas in our app.

Create a new file `src/lib/ideas.js` and add the following code:

```client-web
import { ID, Query } from 'appwrite';
import { tablesDB } from '$lib/appwrite';

const IDEAS_DATABASE_ID = '<YOUR_DATABASE_ID>'; // Replace with your database ID
const IDEAS_TABLE_ID = '<YOUR_TABLE_ID>'; // Replace with your table ID

export async function getIdeas() {
	return await tablesDB.listRows({
		databaseId: IDEAS_DATABASE_ID,
		tableId: IDEAS_TABLE_ID,
		// Use a query to show the latest ideas first
		queries: [Query.orderDesc('$createdAt')]
	});
}

export async function addIdea(userId, title, description) {
	await tablesDB.createRow({
		databaseId: IDEAS_DATABASE_ID,
		tableId: IDEAS_TABLE_ID,
		rowId: ID.unique(),
		data: {
			userId,
			title,
			description
		}
	});
}

export async function deleteIdea(id) {
	await tablesDB.deleteRow({
		databaseId: IDEAS_DATABASE_ID,
		tableId: IDEAS_TABLE_ID,
		rowId: id
	});
}
```

Remember to use a store to hold data returned from Appwrite Databases, so your components can be updated when the data changes.