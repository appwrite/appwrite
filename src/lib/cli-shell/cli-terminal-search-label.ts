export type CliTerminalSearchResults = {
  resultIndex: number
  resultCount: number
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
