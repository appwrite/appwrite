/**
 * Second console Realtime WebSocket on the project's regional API host (project=console).
 *
 * Mirrors {@link ./console-hub.ts} but connects to `getProjectApiEndpoint(projectId)` so
 * region-routed events are received alongside the main `getBaseEndpoint()` console socket.
 * When regional and base URLs are the same (no cached region, self-hosted, etc.), registration
 * is a no-op to avoid duplicate sockets and duplicate events.
 *
 * Each listener gets its own SDK subscription (see console-hub for rationale).
 */

import type { RealtimeResponseEvent, Realtime } from '@appwrite.io/console'
import {
  createRegionalConsoleRealtime,
  getBaseEndpoint,
  getProjectApiEndpoint,
} from '@/lib/appwrite/sdk'

type RegionalHubListener = {
  projectId: string
  channels: string[]
  handler: (event: RealtimeResponseEvent<unknown>) => void
  subscription: { close: () => Promise<void> } | null
}

type HubState = {
  listeners: Map<symbol, RegionalHubListener>
  realtime: Realtime | null
  opChain: Promise<void>
}

const hubStates = new Map<string, HubState>()

function normalizeEndpoint(url: string): string {
  return url.replace(/\/$/, '').toLowerCase()
}

function getOrCreateHub(endpointKey: string): HubState {
  let hub = hubStates.get(endpointKey)
  if (!hub) {
    hub = {
      listeners: new Map(),
      realtime: null,
      opChain: Promise.resolve(),
    }
    hubStates.set(endpointKey, hub)
  }
  return hub
}

function runExclusive(hub: HubState, fn: () => Promise<void>): Promise<void> {
  const next = hub.opChain.then(() => fn())
  hub.opChain = next.catch(() => {})
  return next
}

function anchorProjectId(hub: HubState): string | null {
  const first = hub.listeners.values().next().value
  return first?.projectId ?? null
}

async function attachListener(
  endpointKey: string,
  hub: HubState,
  id: symbol,
): Promise<void> {
  const listener = hub.listeners.get(id)
  if (!listener) return

  if (listener.subscription) {
    await listener.subscription.close()
    listener.subscription = null
  }

  const projectId = anchorProjectId(hub)
  if (!projectId) return

  if (!hub.realtime) {
    hub.realtime = createRegionalConsoleRealtime(projectId)
  }

  listener.subscription = await hub.realtime.subscribe(listener.channels, listener.handler)
}

async function detachListener(
  endpointKey: string,
  hub: HubState,
  id: symbol,
): Promise<void> {
  const listener = hub.listeners.get(id)
  if (!listener) return

  if (listener.subscription) {
    await listener.subscription.close()
    listener.subscription = null
  }

  hub.listeners.delete(id)

  if (hub.listeners.size === 0) {
    hub.realtime = null
    hubStates.delete(endpointKey)
  }
}

export type RegionalConsoleRealtimeHubUnregister = () => Promise<void>

/**
 * Register for console realtime on the regional host for `projectId`.
 * No-op (unregister is empty) when regional URL matches the main console endpoint.
 */
export async function registerRegionalConsoleRealtimeListener(
  projectId: string,
  channels: string[],
  handler: (event: RealtimeResponseEvent<unknown>) => void,
): Promise<RegionalConsoleRealtimeHubUnregister> {
  const regionalUrl = normalizeEndpoint(getProjectApiEndpoint(projectId))
  const baseUrl = normalizeEndpoint(getBaseEndpoint())
  if (regionalUrl === baseUrl) {
    return async () => {}
  }

  const endpointKey = regionalUrl
  const hub = getOrCreateHub(endpointKey)
  const id = Symbol('regional-console-realtime-listener')

  await runExclusive(hub, async () => {
    hub.listeners.set(id, {
      projectId,
      channels: [...channels],
      handler,
      subscription: null,
    })
    await attachListener(endpointKey, hub, id)
  })

  return async () => {
    await runExclusive(hub, async () => {
      await detachListener(endpointKey, hub, id)
    })
  }
}
