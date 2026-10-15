import type { CliShellSession } from './cli-shell-sessions'

/** Root session id for a session in a split group (self when root). */
export function getCliShellSessionRootId(
  sessionId: string,
  sessions: CliShellSession[],
): string {
  const session = sessions.find((item) => item.id === sessionId)
  if (!session?.parentSessionId) return sessionId
  return session.parentSessionId
}

/** Direct split children nested under a root session. */
export function getCliShellSplitChildren(
  rootId: string,
  sessions: CliShellSession[],
): CliShellSession[] {
  return sessions.filter((session) => session.parentSessionId === rootId)
}

/** Root session plus all of its split children. */
export function getCliShellSplitGroupSessionIds(
  rootId: string,
  sessions: CliShellSession[],
): string[] {
  return sessions
    .filter((session) => session.id === rootId || session.parentSessionId === rootId)
    .map((session) => session.id)
}

/** Session ids to remove when deleting a root or split child. */
export function collectCliShellSessionsToRemove(
  sessionId: string,
  sessions: CliShellSession[],
): string[] {
  const session = sessions.find((item) => item.id === sessionId)
  if (!session) return [sessionId]

  if (session.parentSessionId) {
    return [sessionId]
  }

  const childIds = getCliShellSplitChildren(sessionId, sessions).map(
    (child) => child.id,
  )
  return [sessionId, ...childIds]
}

export function normalizeCliShellSplitPaneIds(
  paneIds: string[],
  sessions: CliShellSession[],
): string[] {
  const valid = paneIds.filter((id) =>
    sessions.some((session) => session.id === id),
  )
  if (valid.length <= 1) return []
  return valid
}

/** Pane ids for the active root's split row (ordered, min 2 to show split layout). */
export function getCliShellVisiblePaneSessionIds(
  activeRootId: string,
  sessions: CliShellSession[],
  splitPaneSessionIds: string[],
): string[] {
  const groupIds = getCliShellSplitGroupSessionIds(activeRootId, sessions)
  if (groupIds.length <= 1) return []

  if (splitPaneSessionIds.length > 1) {
    const ordered = splitPaneSessionIds.filter((id) => groupIds.includes(id))
    if (ordered.length > 1) return ordered
  }

  return groupIds
}
