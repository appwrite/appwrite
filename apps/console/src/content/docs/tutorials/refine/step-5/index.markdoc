---
layout: tutorial
title: Add database
description: Add a database to your React application using Appwrite Web SDK.
step: 5
---
# Create table {% #create-table %}
In Appwrite, data is stored as a table of rows. Create a table in the [Appwrite Console](https://cloud.appwrite.io/) to store our ideas.

{% only_dark %}
![Create project screen](/images/docs/tutorials/refine/posts-table-dark.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/tutorials/refine/posts-table-light.avif)
{% /only_light %}

Create a new table with the following columns:
| Field       | Type   | Required |
|-------------|--------|----------|
| title       | Varchar | Yes      |
| content     | Text    | Yes      |

## Connect database to the Refine app {% #blog-context %}

Now that you have a table to hold blog post contents, we can read and write to it from our app. 



To integrate Appwrite API with the Refine App,
- Add a resources array to the `<Refine />` component in `App.tsx`
- Include your `Appwrite Database ID` in both `dataProvider` and `liveProvider`, and specify the `Appwrite Table ID` in the resource name property.


```ts
import { dataProvider, liveProvider } from '@refinedev/appwrite';
...

<Refine
  dataProvider={dataProvider(appwriteClient, {
    databaseId: "<APPWRITE_DATABASE_ID>",
  })}
  liveProvider={liveProvider(appwriteClient, {
    databaseId: "<APPWRITE_DATABASE_ID>",
  })}
  authProvider={authProvider}
  routerProvider={routerProvider}
  resources={[
    {
      name: "<APPWRITE_TABLE_ID>", // resource name must be the same as APPWRITE_TABLE_ID.
      list: "/posts", // Means that the list action of this resource will be available at /posts in your app
      create: "/posts/create", // create action of this resource will be available at /posts/create
      edit: "/posts/edit/:id", // edit action of this resource will be available at /posts/edit/:id
      show: "/posts/show/:id", // show action of this resource will be available at /posts/show/:id
    },
  ]}
...
/>;
```

Key concepts to performing CRUD operations:

- A [resource](https://refine.dev/docs/api-reference/core/components/refine-config/#name) connects an API endpoint's entity with the app's pages, enabling them to interact with the API data. 
In the resource configuration, path definitions allow Refine to automatically recognize the resource's available actions and identify it based on the current path, eliminating the need for manual specification in hooks and components.

- The [data provider](https://refine.dev/docs/tutorial/understanding-dataprovider/index/#using-data-providers-in-refine) serves as your app's data layer, handling HTTP requests and managing data retrieval. Refine uses this through data hooks. 
With built-in support for Appwrite in Refine, you only need to pass your Appwrite database ID to the `dataProvider` property. 


{% info title="Note" %}
The [@refinedev/appwrite](https://www.npmjs.com/package/@refinedev/appwrite) package supports [Live/Realtime Provider](https://refine.dev/docs/api-reference/core/providers/live-provider/) natively.
{% /info %}




At this point, we created our Appwrite database and connected to the Refine App.

You can now register and login to the app.





