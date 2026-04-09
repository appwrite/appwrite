/**
 * Second console Realtime WebSocket on the project's regional API host (project=console).
 *
 * Mirrors {@link ./console-hub.ts} but connects to `getProjectApiEndpoint(projectId)` so
 * region-routed events are received alongside the main `getBaseEndpoint()` console socket.
 * When regional and base URLs are the same (no cached region, self-hosted, etc.), registration
 * is a no-op to avoid duplicate sockets and duplicate events.
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
}

type HubState = {
  listeners: Map<symbol, RegionalHubListener>
  realtime: Realtime | null
  activeSubscription: { close: () => Promise<void> } | null
  activeChannelSignature: string
  opChain: Promise<void>
  debounceTimer: ReturnType<typeof setTimeout> | null
}

const hubStates = new Map<string, HubState>()

const CHANNEL_STABILIZE_MS = 150

function normalizeEndpoint(url: string): string {
  return url.replace(/\/$/, '').toLowerCase()
}

function getOrCreateHub(endpointKey: string): HubState {
  let hub = hubStates.get(endpointKey)
  if (!hub) {
    hub = {
      listeners: new Map(),
      realtime: null,
      activeSubscription: null,
      activeChannelSignature: '',
      opChain: Promise.resolve(),
      debounceTimer: null,
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

function cancelDebounce(hub: HubState): void {
  if (hub.debounceTimer !== null) {
    clearTimeout(hub.debounceTimer)
    hub.debounceTimer = null
  }
}

function mergedChannels(listeners: Map<symbol, RegionalHubListener>): string[] {
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

function dispatchToHubListeners(
  hub: HubState,
  event: RealtimeResponseEvent<unknown>,
): void {
  for (const { handler } of hub.listeners.values()) {
    handler(event)
  }
}

function anchorProjectId(hub: HubState): string | null {
  const first = hub.listeners.values().next().value
  return first?.projectId ?? null
}

async function resyncSubscription(
  endpointKey: string,
  hub: HubState,
): Promise<void> {
  const channels = mergedChannels(hub.listeners)
  if (channels.length === 0) {
    cancelDebounce(hub)
    if (hub.activeSubscription) {
      await hub.activeSubscription.close()
      hub.activeSubscription = null
    }
    hub.activeChannelSignature = ''
    hub.realtime = null
    hubStates.delete(endpointKey)
    return
  }

  const projectId = anchorProjectId(hub)
  if (!projectId) {
    return
  }

  const nextSig = signatureForChannels(channels)
  if (nextSig === hub.activeChannelSignature && hub.activeSubscription) {
    return
  }

  if (hub.activeSubscription) {
    await hub.activeSubscription.close()
    hub.activeSubscription = null
  }

  if (!hub.realtime) {
    hub.realtime = createRegionalConsoleRealtime(projectId)
  }

  hub.activeChannelSignature = nextSig
  hub.activeSubscription = await hub.realtime.subscribe(
    channels,
    (event: {
      events: string[]
      channels: string[]
      payload: unknown
    }) => {
      dispatchToHubListeners(hub, event as RealtimeResponseEvent<unknown>)
    },
  )
}

async function scheduleResyncFromCurrentState(
  endpointKey: string,
  hub: HubState,
): Promise<void> {
  cancelDebounce(hub)

  if (hub.listeners.size === 0) {
    await resyncSubscription(endpointKey, hub)
    return
  }

  if (!hub.activeSubscription) {
    await resyncSubscription(endpointKey, hub)
    return
  }

  hub.debounceTimer = window.setTimeout(() => {
    hub.debounceTimer = null
    void runExclusive(hub, async () => {
      await resyncSubscription(endpointKey, hub)
    })
  }, CHANNEL_STABILIZE_MS)
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
    })
    await scheduleResyncFromCurrentState(endpointKey, hub)
  })

  return async () => {
    await runExclusive(hub, async () => {
      hub.listeners.delete(id)
      await scheduleResyncFromCurrentState(endpointKey, hub)
    })
  }
}
