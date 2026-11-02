import { getActiveEnvironmentId } from '@/lib/active-environment'

/**
 * Where a console-channel event comes from, relative to what the Console shows
 * for `projectId`. Events name their project in a `projects.<id>` channel. The
 * project itself is named by its ID, but an environment of it by an internal
 * project ID the Console never learns. So:
 *
 * - `current`: the project's own event while the Console is in the project.
 * - `other`: a context not on screen: another project or environment while in
 *   the project, or the project itself while in one of its environments.
 * - `unknown`: names no project, or, inside an environment, a project ID that
 *   may be the environment's.
 *
 * An environment copied from its project keeps the IDs of what was copied, so
 * a payload from one context can match cache entries of the other by `$id`.
 */
export type RealtimeEventSource = 'current' | 'other' | 'unknown'

export function realtimeEventSource(
  projectId: string,
  channels: string[],
): RealtimeEventSource {
  const projectChannels = channels.filter((c) => c.startsWith('projects.'))
  if (projectChannels.length === 0) return 'unknown'

  const projectChannel = `projects.${projectId}`
  const namesProject = projectChannels.some(
    (c) => c === projectChannel || c.startsWith(`${projectChannel}.`),
  )

  if (getActiveEnvironmentId(projectId)) {
    return namesProject ? 'other' : 'unknown'
  }
  return namesProject ? 'current' : 'other'
}
