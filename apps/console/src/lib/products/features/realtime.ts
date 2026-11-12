import type { ProductFeatureContent } from '@/lib/products/features/types'

export const realtimeProductFeatures: ProductFeatureContent[] = [
  {
    id: 'subscribe',
    title: 'One connection, many subscriptions',
    description:
      'Create a Realtime client once and every subscription shares a single WebSocket. Add one with subscribe(), swap its channels or queries with update(), and drop it with unsubscribe(), all without reconnecting.',
    docsHref: '/docs/apis/realtime/subscribe',
    docsLabel: 'Subscribe docs',
    brandLight: 'purple',
  },
  {
    id: 'channels',
    title: 'Every service publishes to a channel',
    description:
      'Databases, Storage, Functions, Auth, teams, and presence all stream on the same socket. The Channel helper builds the string with a fluent API, so leave an ID blank for a wildcard or append .create() to narrow the stream.',
    docsHref: '/docs/apis/realtime/channels',
    docsLabel: 'Channels docs',
    layout: 'stacked',
    hideVisual: true,
    wideCompanion: true,
    brandLight: 'teal',
  },
  {
    id: 'queries',
    title: 'Filter events before they reach you',
    description:
      'Pass queries when you subscribe and Appwrite filters events on the server, so your callback only runs for updates that match. The methods are the ones you already use for lists, from equal to isNull and or.',
    docsHref: '/docs/apis/realtime/queries',
    docsLabel: 'Realtime queries docs',
  },
  {
    id: 'presences',
    title: 'Presence that is durable and live',
    description:
      'A presence record carries a user, a free-form status, optional metadata, and an expiry. List presences to see who is here, and subscribe for upsert, update, and delete events in milliseconds.',
    docsHref: '/docs/apis/realtime/presences',
    docsLabel: 'Presences docs',
    brandLight: 'pink',
  },
  {
    id: 'payload',
    title: 'A predictable payload on every event',
    description:
      'Every message carries the events that triggered it, the channels that can receive it, a server timestamp, and a payload matching the resource response model. Branch on the event names, then apply the payload.',
    docsHref: '/docs/apis/realtime/payload',
    docsLabel: 'Payload docs',
  },
  {
    id: 'permissions',
    title: 'Permission-aware subscriptions',
    description:
      'Subscriptions use the same permissions as the rest of Appwrite, so a user only receives updates for resources they can read. Realtime uses the session you had when you subscribed, so reconnect when it changes.',
    docsHref: '/docs/apis/realtime/authentication',
    docsLabel: 'Realtime authentication docs',
  },
]
