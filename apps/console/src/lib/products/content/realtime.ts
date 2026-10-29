import type { ProductPageContent } from '@/lib/products/types'

export const realtimeProductContent: ProductPageContent = {
  id: 'realtime',
  metaTitle: 'Realtime WebSocket API',
  metaDescription:
    'Subscribe to Appwrite events over one WebSocket connection. Realtime brings type-safe channels, server-side query filters, live presence, and permission-aware events to every Appwrite service.',
  hero: {
    title: 'Everything in Appwrite is realtime',
    description:
      'Rows, files, function executions, sessions, teams, and presence all emit events on the same WebSocket. Subscribe once, get changes in milliseconds instead of polling, and only ever receive what the user can read.',
    stats: [
      { value: 'Every service', label: 'Not just databases' },
      { value: '1 socket', label: 'Shared by all subscriptions' },
      { value: 'Milliseconds', label: 'From write to subscriber' },
      { value: 'Queries', label: 'Filtered server-side' },
      { value: 'Presence', label: 'Live online status' },
    ],
  },
  faq: [
    {
      question: 'What is Appwrite Realtime?',
      answer:
        'Realtime is a third protocol for talking to Appwrite, alongside REST and GraphQL. Instead of requesting new data over HTTP, you subscribe once and the server pushes new data to every connected client over a WebSocket as soon as it changes. Subscriptions cover events from all of Appwrite services, not just databases.',
      links: [
        { label: 'Realtime overview', href: '/docs/apis/realtime' },
        { label: 'REST API', href: '/docs/apis/rest' },
        { label: 'GraphQL API', href: '/docs/apis/graphql' },
      ],
    },
    {
      question: 'How many WebSocket connections does my app open?',
      answer:
        'Client SDKs use a single WebSocket per Realtime client for all subscriptions. Adding one with subscribe(), replacing its channels or queries with update(), and dropping it with unsubscribe() all apply on the existing socket where supported, so there is no full reconnect. The connection closes when you call realtime.disconnect().',
      links: [{ label: 'Subscribe docs', href: '/docs/apis/realtime/subscribe' }],
    },
    {
      question: 'Which resources can I subscribe to?',
      answer:
        'Every service publishes channels: account and sessions, teams and memberships, rows, files, function executions, and presences. The Channel helper class builds the channel string for you with a fluent API, so you can target one row or every row in a table. Leave an ID blank to subscribe with a wildcard, and append .create(), .update(), or .delete() to narrow the stream to a single event type.',
      links: [
        { label: 'Channels docs', href: '/docs/apis/realtime/channels' },
        { label: 'Events reference', href: '/docs/apis/events' },
      ],
    },
    {
      question: 'Can I filter events before they reach my callback?',
      answer:
        'Yes. Pass queries as a third parameter when you subscribe and Appwrite filters events server-side, so your callback only runs for updates that match. Realtime supports Query.equal, Query.notEqual, the greater than and less than comparisons, Query.isNull, Query.isNotNull, Query.and, and Query.or.',
      links: [{ label: 'Realtime queries docs', href: '/docs/apis/realtime/queries' }],
    },
    {
      question: 'What does a Realtime message look like?',
      answer:
        'Every message carries four properties: events (the Appwrite events that triggered the update), channels (the channels that can receive it), timestamp (an ISO 8601 time in UTC from the server), and payload (the same data as the matching response model). Branch on the event names in events to decide how to update your UI.',
      links: [
        { label: 'Payload docs', href: '/docs/apis/realtime/payload' },
        { label: 'Events reference', href: '/docs/apis/events' },
      ],
    },
    {
      question: 'Can a user receive updates for data they cannot read?',
      answer:
        'No. Every subscription is secured by the same permissions system used by rows, files, and presences, so a user only receives updates for resources they have permission to access. Granting read to Role.any() is what makes a resource stream to any client, including visitors who are not signed in.',
      links: [
        { label: 'Realtime authentication docs', href: '/docs/apis/realtime/authentication' },
        { label: 'Permissions', href: '/docs/advanced/security/permissions' },
      ],
    },
    {
      question: 'What happens when the user signs in or out?',
      answer:
        'Realtime authenticates with the session that existed when the subscription was created. If you authenticate after subscribing, that subscription will not receive updates for the new user, so create the session first. When a user signs out and another signs in, call realtime.disconnect() and subscribe again for the new session.',
      links: [
        { label: 'Realtime authentication docs', href: '/docs/apis/realtime/authentication' },
      ],
    },
    {
      question: 'How does presence work?',
      answer:
        'A presence is a short-lived record tied to a user, with a userId, a free-form status, optional metadata, and an expiresAt timestamp. It is durable, so you can list presences at any time, and live, so every change fires upsert, update, and delete events. Keep a record alive with a heartbeat, or use realtime.upsertPresence() so it is removed when the connection closes.',
      links: [
        { label: 'Presences docs', href: '/docs/apis/realtime/presences' },
        { label: 'Auth presences', href: '/docs/products/auth/presences' },
      ],
    },
    {
      question: 'Can I use Realtime from a Server SDK with an API key?',
      answer:
        'Not today. Realtime subscriptions are a client SDK feature and are not offered for Server SDKs with an API key. Presence records are the exception: they are also a regular HTTP resource, so server code can write them with an API key that has the presences.write scope and clients will see the change live.',
      links: [
        { label: 'Realtime overview', href: '/docs/apis/realtime' },
        { label: 'Presences docs', href: '/docs/apis/realtime/presences' },
      ],
    },
    {
      question: 'Can I point the SDK at a custom WebSocket endpoint?',
      answer:
        'Yes. The SDK derives the Realtime endpoint from your Appwrite endpoint, which is wss://<REGION>.cloud.appwrite.io/v1/realtime by default. If you run Appwrite behind a custom proxy and moved the Realtime route, call setEndpointRealtime on the client with your own value.',
      links: [{ label: 'Custom endpoint docs', href: '/docs/apis/realtime/custom-endpoint' }],
    },
  ],
  cta: {
    title: 'Start building with Realtime',
    description:
      'Subscribe to your first channel and watch rows, files, executions, and presence updates arrive as they happen.',
  },
}
