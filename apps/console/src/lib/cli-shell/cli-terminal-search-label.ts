export type CliTerminalSearchResults = {
  resultIndex: number
  resultCount: number
}

export function aggregateCliTerminalSearchResults(
  paneIds: string[],
  resultsBySession: Map<string, CliTerminalSearchResults>,
  activeSessionId: string | null,
): CliTerminalSearchResults & { activeSessionId: string | null } {
  let totalCount = 0
  let globalIndex = -1
  let resolvedActiveId = activeSessionId

  for (const id of paneIds) {
    const results = resultsBySession.get(id)
    const count = results?.resultCount ?? 0
    if (
      resolvedActiveId &&
      id === resolvedActiveId &&
      results &&
      results.resultIndex >= 0
    ) {
      globalIndex = totalCount + results.resultIndex
    }
    totalCount += count
  }

  if (globalIndex < 0 && totalCount > 0) {
    let prefix = 0
    for (const id of paneIds) {
      const results = resultsBySession.get(id)
      const count = results?.resultCount ?? 0
      if (count > 0) {
        globalIndex =
          prefix + (results!.resultIndex >= 0 ? results!.resultIndex : 0)
        resolvedActiveId = id
        break
      }
      prefix += count
    }
  }

  return {
    resultIndex: globalIndex,
    resultCount: totalCount,
    activeSessionId: resolvedActiveId,
  }
}

export function resolveGlobalMatchPosition(
  globalIndex: number,
  paneIds: string[],
  resultsBySession: Map<string, CliTerminalSearchResults>,
): { sessionId: string; localIndex: number } | null {
  let prefix = 0
  for (const id of paneIds) {
    const count = resultsBySession.get(id)?.resultCount ?? 0
    if (globalIndex >= prefix && globalIndex < prefix + count) {
      return { sessionId: id, localIndex: globalIndex - prefix }
    }
    prefix += count
  }
  return null
}

export function formatCliTerminalSearchLabel(
  query: string,
  results: CliTerminalSearchResults | null,
): string {
  if (!query.trim()) return ''
  if (!results || results.resultCount === 0) return 'No results'
  if (results.resultIndex < 0) {
    return `${results.resultCount} matches`
  }
  return `${results.resultIndex + 1} of ${results.resultCount}`
}
