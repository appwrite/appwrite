export type CliShellSession = {
  id: string
  name: string
  /** Set when this session was created by splitting a root terminal. */
  parentSessionId?: string | null
}

export function createCliShellSessionId(): string {
  return crypto.randomUUID()
}

export function formatCliShellSessionName(index: number): string {
  return `Terminal ${index}`
}

export function createDefaultCliShellSession(index = 1): CliShellSession {
  return {
    id: createCliShellSessionId(),
    name: formatCliShellSessionName(index),
  }
}

export function nextCliShellSessionName(sessions: CliShellSession[]): string {
  const used = new Set(sessions.map((session) => session.name))
  let index = sessions.length + 1
  while (used.has(formatCliShellSessionName(index))) {
    index += 1
  }
  return formatCliShellSessionName(index)
}

export function createInitialCliShellSessionState(): {
  sessions: CliShellSession[]
  activeSessionId: string
} {
  const session = createDefaultCliShellSession()
  return { sessions: [session], activeSessionId: session.id }
}

/** Reorder root sessions while keeping each root's split children grouped beneath it. */
export function reorderCliShellRootSessions(
  sessions: CliShellSession[],
  fromRootIndex: number,
  toRootIndex: number,
): CliShellSession[] {
  const rootSessions = sessions.filter((session) => !session.parentSessionId)
  if (
    fromRootIndex === toRootIndex ||
    fromRootIndex < 0 ||
    toRootIndex < 0 ||
    fromRootIndex >= rootSessions.length ||
    toRootIndex >= rootSessions.length
  ) {
    return sessions
  }

  const reorderedRoots = [...rootSessions]
  const [moved] = reorderedRoots.splice(fromRootIndex, 1)
  reorderedRoots.splice(toRootIndex, 0, moved)

  const next: CliShellSession[] = []
  for (const root of reorderedRoots) {
    next.push(root)
    next.push(
      ...sessions.filter((session) => session.parentSessionId === root.id),
    )
  }
  return next
}

export function resolveCliShellSessionState(
  saved: { sessions: CliShellSession[]; activeSessionId: string } | null,
): {
  sessions: CliShellSession[]
  activeSessionId: string
} {
  if (saved && saved.sessions.length > 0) {
    const activeExists = saved.sessions.some(
      (session) => session.id === saved.activeSessionId,
    )
    return {
      sessions: saved.sessions,
      activeSessionId: activeExists
        ? saved.activeSessionId
        : saved.sessions[0].id,
    }
  }
  return createInitialCliShellSessionState()
}
