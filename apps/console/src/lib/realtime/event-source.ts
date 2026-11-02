import { getActiveEnvironmentId } from '@/lib/active-environment'

/**
 * Where a console-channel event comes from, relative to what the Console shows
 * for `projectId`. Events name their project in a `projects.…` channel: the
 * project by `projects.<projectId>`, and an environment of it, whose own project
 * ID is internal, by `projects.<projectId>.environments.<environmentId>`. So:
 *
 * - `current`: an event of the context on screen: the project's own while the
 *   Console is in the project, or the environment's while it is in one.
 * - `other`: an event of a context not on screen: another project, the project
 *   while in one of its environments, or another environment.
 * - `unknown`: names no project.
 *
 * An environment copied from its project keeps the IDs of what was copied, so
 * a payload from one context can match cache entries of the other by `$id`.
 */
export type RealtimeEventSource = 'current' | 'other' | 'unknown'

/** The channel naming the context the Console is in for `projectId`. */
function realtimeContextChannel(projectId: string): string {
  const environmentId = getActiveEnvironmentId(projectId)
  return environmentId
    ? `projects.${projectId}.environments.${environmentId}`
    : `projects.${projectId}`
}

export function realtimeEventSource(
  projectId: string,
  channels: string[],
): RealtimeEventSource {
  const projectChannels = channels.filter((c) => c.startsWith('projects.'))
  if (projectChannels.length === 0) return 'unknown'

  return projectChannels.includes(realtimeContextChannel(projectId))
    ? 'current'
    : 'other'
}

/**
 * Whether the event is a `client.ping()` of the context on screen. The event
 * itself names the project the ping reached by its own ID, which for an
 * environment is internal, so the ping is told by its channel.
 */
export function isRealtimeContextPing(
  projectId: string,
  events: string[],
  channels: string[],
): boolean {
  return (
    realtimeEventSource(projectId, channels) === 'current' &&
    events.some((e) => /^projects\.[^.]+\.ping$/.test(e))
  )
}
