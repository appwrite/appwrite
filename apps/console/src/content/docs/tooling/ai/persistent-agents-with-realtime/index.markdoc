---
layout: article
title: Persistent Agents with Realtime
description: Building persistent AI agents using Appwrite Realtime.
---

AI agents that maintain conversation history across sessions provide more contextual and personalized responses. By storing LLM responses in Appwrite Databases and subscribing to changes through Realtime, you can build chat applications where multiple clients receive updates instantly.

## Architecture

1. **Store messages**: Save user messages and LLM responses in an Appwrite table
2. **Subscribe to changes**: Use Realtime to listen for new messages
3. **Maintain context**: Load conversation history to provide context to the LLM

## Set up the messages table

Create a table to store conversation messages with the following columns:

| Key | Type | Description |
| --- | --- | --- |
| `conversationId` | varchar | Groups messages by conversation |
| `role` | varchar | Either "user" or "assistant" |
| `content` | longtext | The message content |
| `$createdAt` | automatic | Timestamp for ordering |

## Store messages in the database

When a user sends a message or the LLM responds, save it to the database:

```js
import { Client, TablesDB, ID } from 'node-appwrite';

const client = new Client()
  .setEndpoint(process.env.APPWRITE_ENDPOINT ?? 'https://<REGION>.cloud.appwrite.io/v1')
  .setProject(process.env.APPWRITE_FUNCTION_PROJECT_ID)
  .setKey(process.env.APPWRITE_API_KEY);

const tablesDB = new TablesDB(client);

// Save user message
await tablesDB.createRow({
  databaseId: process.env.DATABASE_ID,
  tableId: process.env.MESSAGES_TABLE_ID,
  rowId: ID.unique(),
  data: {
    conversationId: conversationId,
    role: 'user',
    content: userMessage,
  }
});

// Generate LLM response
const response = await generateLLMResponse(userMessage, conversationHistory);

// Save assistant message
await tablesDB.createRow({
  databaseId: process.env.DATABASE_ID,
  tableId: process.env.MESSAGES_TABLE_ID,
  rowId: ID.unique(),
  data: {
    conversationId: conversationId,
    role: 'assistant',
    content: response,
  }
});
```

## Subscribe to messages with Realtime

On the client side, subscribe to the messages table to receive updates in real time. Use the `Channel` helper to build type-safe channel subscriptions and realtime queries to filter messages by conversation server-side.

```js
import { Client, Realtime, Channel, Query } from 'appwrite';

const client = new Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

// Subscribe to new messages for the current conversation
const subscription = await realtime.subscribe(
  Channel.tablesdb('<DATABASE_ID>').table('<MESSAGES_TABLE_ID>').row().create(),
  (response) => {
    displayMessage(response.payload);
  },
  [Query.equal('conversationId', [currentConversationId])]
);
```

The `Channel` helper provides a fluent API for building channel strings, replacing manual string concatenation. The `.create()` event filter ensures the callback only fires for new messages, not updates or deletes.

By passing a `Query.equal()` filter, messages are filtered server-side so the callback only receives messages for the current conversation. This removes the need for manual filtering in your callback and reduces unnecessary processing on the client.

## Load conversation history for context

Before generating an LLM response, load recent messages to provide context:

```js
import { Query } from 'node-appwrite';

const { rows } = await tablesDB.listRows({
  databaseId: process.env.DATABASE_ID,
  tableId: process.env.MESSAGES_TABLE_ID,
  queries: [
    Query.equal('conversationId', conversationId),
    Query.orderAsc('$createdAt'),
    Query.limit(10),
  ]
});

const conversationHistory = rows.map((row) => ({
  role: row.role,
  content: row.content,
}));
```

## Generate responses with context

Pass the conversation history to the LLM:

```js
const response = await fetch('https://api.openai.com/v1/chat/completions', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${process.env.OPENAI_API_KEY}`,
  },
  body: JSON.stringify({
    model: 'gpt-4',
    messages: conversationHistory,
  }),
});

const data = await response.json();
const assistantMessage = data.choices[0].message.content;
```

## Benefits

- **Persistence**: Conversations survive page refreshes and app restarts
- **Multi-device sync**: Users can continue conversations on different devices
- **Real-time updates**: Multiple users or clients see messages instantly
- **Audit trail**: All messages are stored and can be reviewed later
