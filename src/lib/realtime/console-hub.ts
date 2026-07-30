/**
 * Single console Realtime WebSocket shared app-wide.
 *
 * The Appwrite SDK keeps one WebSocket per `Realtime` client and supports
 * multiple independent `subscribe()` calls on that socket. Each hub listener
 * gets its own SDK subscription with its own channels - do not merge channels
 * into one subscription and reconnect on every listener change; that pattern
 * drops channels (e.g. `presences`) during debounced resyncs and prevents
 * events from reaching subscribers.
 */

import type { RealtimeResponseEvent } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

type HubListener = {
  channels: string[]
  handler: (event: RealtimeResponseEvent<unknown>) => void
  subscription: { close: () => Promise<void> } | null
}

const listeners = new Map<symbol, HubListener>()

/** Serialize listener attach/detach so subscribe/close calls do not overlap. */
let opChain: Promise<void> = Promise.resolve()

function runExclusive(fn: () => Promise<void>): Promise<void> {
  const next = opChain.then(() => fn())
  opChain = next.catch(() => {})
  return next
}

async function attachListener(id: symbol): Promise<void> {
  const listener = listeners.get(id)
  if (!listener) return

  if (listener.subscription) {
    await listener.subscription.close()
    listener.subscription = null
  }

  const realtime = sdk.getConsoleRealtime()
  listener.subscription = await realtime.subscribe(listener.channels, listener.handler)
}

async function detachListener(id: symbol): Promise<void> {
  const listener = listeners.get(id)
  if (!listener) return

  if (listener.subscription) {
    await listener.subscription.close()
    listener.subscription = null
  }

  listeners.delete(id)
}

export type ConsoleRealtimeHubUnregister = () => Promise<void>

/**
 * Register for console realtime on the shared socket. Each listener owns a
 * dedicated SDK subscription for its channels; the socket stays open while
 * any subscription is active.
 */
export async function registerConsoleRealtimeListener(
  channels: string[],
  handler: (event: RealtimeResponseEvent<unknown>) => void,
): Promise<ConsoleRealtimeHubUnregister> {
  const id = Symbol('console-realtime-listener')

  await runExclusive(async () => {
    listeners.set(id, { channels: [...channels], handler, subscription: null })
    await attachListener(id)
  })

  return async () => {
    await runExclusive(async () => {
      await detachListener(id)
    })
  }
}
