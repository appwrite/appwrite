---
layout: tutorial
title: Create CRUD pages
description: Add database queries and CRUD pages using Appwrite in your Refine application.
step: 6
---

We're going to add CRUD pages to our admin panel so you can list, create, and view blog posts records.


## List page {% #list-page %}


 First, create a listing page to show Appwrite API data in a table by copying the code below into `src/pages/posts` and saving it as `list.tsx`.

```ts
import { IResourceComponentsProps } from "@refinedev/core";
import {
  List,
  useTable,
  EditButton,
  ShowButton,
  getDefaultSortOrder,
  DeleteButton,
} from "@refinedev/antd";
import { Table, Space } from "antd";
import { IPost } from "../../interfaces";

export const PostList: React.FC<IResourceComponentsProps> = () => {
  const { tableProps, sorters } = useTable<IPost>({
    initialSorter: [
      {
        field: "$id",
        order: "asc",
      },
    ],
  });

  return (
    <List>
      <Table {...tableProps} rowKey="id">
        <Table.Column
          dataIndex="id"
          title="ID"
          sorter
          width={100}
          defaultSortOrder={getDefaultSortOrder("id", sorters)}
        />
        <Table.Column dataIndex="title" title="Title" sorter />

        <Table.Column<IPost>
          title="Actions"
          dataIndex="actions"
          fixed="right"
          render={(_, record) => (
            <Space>
              <EditButton hideText size="small" recordItemId={record.id} />
              <ShowButton hideText size="small" recordItemId={record.id} />
              <DeleteButton hideText size="small" recordItemId={record.id} />
            </Space>
          )}
        />
      </Table>
    </List>
  );
};
```

## Create page {% #create-page %}

 Create a new record page for the Appwrite API by copying the following code and saving it as `create.tsx`.


```ts
import { HttpError, IResourceComponentsProps } from "@refinedev/core";
import { Create, useForm } from "@refinedev/antd";
import { Form, Input } from "antd";
import { IPost, IPostVariables } from "../../interfaces";

export const PostCreate: React.FC<IResourceComponentsProps> = () => {
  const { formProps, saveButtonProps } = useForm<
    IPost,
    HttpError,
    IPostVariables
  >();

  return (
    <Create saveButtonProps={saveButtonProps}>
      <Form {...formProps} layout="vertical">
        <Form.Item
          label="Title"
          name="title"
          rules={[
            {
              required: true,
            },
          ]}
        >
          <Input />
        </Form.Item>

        <Form.Item
          label="Content"
          name="content"
          rules={[
            {
              required: true,
            },
          ]}
        >
          <Input.TextArea rows={5} />
        </Form.Item>
      </Form>
    </Create>
  );
};
```

## Edit page {% #edit-page %}

Create a page for editing a records with the following code and saving it as `edit.tsx`.


```ts
import React from "react";
import { HttpError, IResourceComponentsProps } from "@refinedev/core";
import { Edit, useForm } from "@refinedev/antd";
import { Form, Input } from "antd";
import { IPost, IPostVariables } from "../../interfaces";

export const PostEdit: React.FC<IResourceComponentsProps> = () => {
  const { formProps, saveButtonProps } = useForm<
    IPost,
    HttpError,
    IPostVariables
  >();

  return (
    <Edit saveButtonProps={saveButtonProps}>
      <Form {...formProps} layout="vertical">
        <Form.Item
          label="Title"
          name="title"
          rules={[
            {
              required: true,
            },
          ]}
        >
          <Input />
        </Form.Item>
        <Form.Item
          label="Content"
          name="content"
          rules={[
            {
              required: true,
            },
          ]}
        >
          <Input.TextArea rows={5} />
        </Form.Item>
      </Form>
    </Edit>
  );
};
```

## Show page {% #show-page %}

 Create a page for showig the records with the following code and saving it as `edit.tsx`.


```ts
import { useShow, IResourceComponentsProps } from "@refinedev/core";

import { Show, MarkdownField } from "@refinedev/antd";
import { Typography } from "antd";
import { IPost } from "../../interfaces";

const { Title, Text } = Typography;

export const PostShow: React.FC<IResourceComponentsProps> = () => {
  const { queryResult } = useShow<IPost>();
  const { data, isLoading } = queryResult;
  const record = data?.data;

  return (
    <Show isLoading={isLoading}>
      <Title level={5}>Id</Title>
      <Text>{record?.id}</Text>

      <Title level={5}>Title</Title>
      <Text>{record?.title}</Text>

      <Title level={5}>Content</Title>
      <MarkdownField value={record?.content} />
    </Show>
  );
};
```

## Interfaces {% #interfaces %}

 We need to add interfaces to `src/interfaces/index.d.ts` file.


```ts
export interface IFile {
  name: string;
  percent: number;
  size: number;
  status: "error" | "success" | "done" | "uploading" | "removed";
  type: string;
  uid: string;
  url: string;
}

export interface IPost {
  id: string;
  title: string;
  content: string;
}

export interface IPostVariables {
  id: string;
  title: string;
  content: string;
}
```

## Connect pages to the App

Finally, import the pages into `App.tsx` and define them in the `<Route>` components. 

Simply, paste the following into `App.tsx`.


```ts
import { Authenticated, Refine } from "@refinedev/core";
import { dataProvider, liveProvider } from "@refinedev/appwrite";
import {
  AuthPage,
  ErrorComponent,
  RefineThemes,
  ThemedLayoutV2,
  useNotificationProvider,
} from "@refinedev/antd";
import routerProvider, {
  CatchAllNavigate,
  DocumentTitleHandler,
  NavigateToResource,
  UnsavedChangesNotifier,
} from "@refinedev/react-router-v6";
import "@refinedev/antd/dist/reset.css";

import { App as AntdApp, ConfigProvider } from "antd";
import { BrowserRouter, Outlet, Route, Routes } from "react-router-dom";

import { appwriteClient } from "./utility";
import { authProvider } from "./authProvider";
import { PostCreate, PostEdit, PostList, PostShow } from "./pages/posts";

const App: React.FC = () => (
  <BrowserRouter>
    <ConfigProvider theme={RefineThemes.Blue}>
      <AntdApp>
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
              name: "<APPWRITE_TABLE_ID>",
              list: "/posts",
              create: "/posts/create",
              edit: "/posts/edit/:id",
              show: "/posts/show/:id",
              meta: {
                label: "Posts",
              },
            },
          ]}
          notificationProvider={useNotificationProvider}
          options={{
            liveMode: "auto",
            syncWithLocation: true,
            warnWhenUnsavedChanges: true,
          }}
        >
          <Routes>
            <Route
              element={
                <Authenticated 
                  fallback={<CatchAllNavigate to="/login" />}
                >
                  <ThemedLayoutV2>
                    <Outlet />
                  </ThemedLayoutV2>
                </Authenticated>
              }
            >
              <Route
                index
                element={
                  <NavigateToResource 
                    resource="<APPWRITE_TABLE_ID>" 
                  />
                }
              />

              <Route path="/posts">
                <Route index element={<PostList />} />
                <Route path="create" element={<PostCreate />} />
                <Route path="edit/:id" element={<PostEdit />} />
                <Route path="show/:id" element={<PostShow />} />
              </Route>
            </Route>

            <Route
              element={
                <Authenticated fallback={<Outlet />}>
                  <NavigateToResource
                    resource="<APPWRITE_TABLE_ID>" 
                  />
                </Authenticated>
              }
            >
              <Route
                path="/login"
                element={<AuthPage forgotPasswordLink={false} />}
              />
              <Route path="/register" 
                element={<AuthPage type="register" />} 
              />
            </Route>

            <Route
              element={
                <Authenticated>
                  <ThemedLayoutV2>
                    <Outlet />
                  </ThemedLayoutV2>
                </Authenticated>
              }
            >
              <Route path="*" element={<ErrorComponent />} />
            </Route>
          </Routes>
          <UnsavedChangesNotifier />
          <DocumentTitleHandler />
        </Refine>
      </AntdApp>
    </ConfigProvider>
  </BrowserRouter>
);

export default App;
```

