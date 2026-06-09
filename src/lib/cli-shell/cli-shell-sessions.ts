export type CliShellSession = {
  id: string
  name: string
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
