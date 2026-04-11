/**
 * Single console Realtime WebSocket shared app-wide.
 *
 * The Appwrite SDK reconnects on every subscribe()/close() when slots or channels
 * change. Multiple features (project cache invalidation, assistant, etc.) must
 * not each call realtime.subscribe() - that stacks slots and forces reconnects.
 * This hub keeps one SDK subscription with the union of all requested channels
 * and dispatches events to every listener.
 *
 * Listener changes that grow the channel set while a socket already exists are
 * debounced so async-loaded deps (auth, project teamId, etc.) do not each open a
 * new WebSocket.
 */

import type { RealtimeResponseEvent } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

type HubListener = {
  channels: string[]
  handler: (event: RealtimeResponseEvent<unknown>) => void
}

const listeners = new Map<symbol, HubListener>()

let activeSubscription: { close: () => Promise<void> } | null = null
let activeChannelSignature = ''

/** Serialize listener mutations and immediate resyncs (debounced flushes enqueue here too). */
let opChain: Promise<void> = Promise.resolve()

let debounceTimer: ReturnType<typeof setTimeout> | null = null

const CHANNEL_STABILIZE_MS = 150

function runExclusive(fn: () => Promise<void>): Promise<void> {
  const next = opChain.then(() => fn())
  opChain = next.catch(() => {})
  return next
}

function cancelDebounce(): void {
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer)
    debounceTimer = null
  }
}

function mergedChannels(): string[] {
  const set = new Set<string>()
  for (const { channels } of listeners.values()) {
    for (const ch of channels) {
      set.add(ch)
    }
  }
  return [...set]
}

function signatureForChannels(channels: string[]): string {
  return [...new Set(channels)].sort().join('\0')
}

function dispatchToListeners(
  event: RealtimeResponseEvent<unknown>,
): void {
  for (const { handler } of listeners.values()) {
    handler(event)
  }
}

async function resyncSubscription(): Promise<void> {
  const channels = mergedChannels()
  if (channels.length === 0) {
    if (activeSubscription) {
      await activeSubscription.close()
      activeSubscription = null
    }
    activeChannelSignature = ''
    return
  }

  const nextSig = signatureForChannels(channels)
  if (nextSig === activeChannelSignature && activeSubscription) {
    return
  }

  if (activeSubscription) {
    await activeSubscription.close()
    activeSubscription = null
  }

  activeChannelSignature = nextSig
  const realtime = sdk.getConsoleRealtime()
  activeSubscription = await realtime.subscribe(
    channels,
    dispatchToListeners as (event: {
      events: string[]
      channels: string[]
      payload: unknown
    }) => void,
  )
}

/**
 * Apply current listener map to the socket. First connection and full teardown
 * run immediately; channel expansion while connected is debounced.
 */
async function scheduleResyncFromCurrentState(): Promise<void> {
  cancelDebounce()

  if (listeners.size === 0) {
    await resyncSubscription()
    return
  }

  if (!activeSubscription) {
    await resyncSubscription()
    return
  }

  debounceTimer = window.setTimeout(() => {
    debounceTimer = null
    void runExclusive(async () => {
      await resyncSubscription()
    })
  }, CHANNEL_STABILIZE_MS)
}

export type ConsoleRealtimeHubUnregister = () => Promise<void>

/**
 * Register for console realtime. The socket uses the union of all listeners'
 * channels; reconnects when that union changes (debounced when already online).
 */
export async function registerConsoleRealtimeListener(
  channels: string[],
  handler: (event: RealtimeResponseEvent<unknown>) => void,
): Promise<ConsoleRealtimeHubUnregister> {
  const id = Symbol('console-realtime-listener')

  await runExclusive(async () => {
    listeners.set(id, { channels: [...channels], handler })
    await scheduleResyncFromCurrentState()
  })

  return async () => {
    await runExclusive(async () => {
      listeners.delete(id)
      await scheduleResyncFromCurrentState()
    })
  }
}
