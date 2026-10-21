import type { SearchAddon } from '@xterm/addon-search'
import type { ISearchOptions } from '@xterm/addon-search'
import type { Terminal } from '@xterm/xterm'
import { getTerminalBufferText } from './cli-terminal-buffer'

export type CliTerminalSearchRunResult = {
  found: boolean
  matchCount: number
  resultIndex: number
}

export type CliTerminalSearchNavigation = 'next' | 'previous' | 'first' | 'last'

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

export function countTextSearchMatches(
  text: string,
  query: string,
  caseSensitive = false,
): number {
  const trimmed = query.trim()
  if (!trimmed || !text) return 0

  const flags = caseSensitive ? 'g' : 'gi'
  const matches = text.match(new RegExp(escapeRegExp(trimmed), flags))
  return matches?.length ?? 0
}

export function countTerminalSearchMatches(
  terminal: Terminal,
  query: string,
  caseSensitive = false,
): number {
  return countTextSearchMatches(
    getTerminalBufferText(terminal),
    query,
    caseSensitive,
  )
}

function resolveNavigationResultIndex(
  direction: CliTerminalSearchNavigation,
  addonResultCount: number,
  addonResultIndex: number,
  matchCount: number,
  hasMatch: boolean,
): number {
  if (addonResultCount > 0 && addonResultIndex >= 0) {
    return addonResultIndex
  }
  if (!hasMatch || matchCount <= 0) return -1
  if (direction === 'last') return matchCount - 1
  return 0
}

/** Navigate an existing search match within a terminal session. */
export function navigateCliTerminalSearch(
  terminal: Terminal,
  searchAddon: SearchAddon,
  query: string,
  options: ISearchOptions,
  direction: CliTerminalSearchNavigation,
): CliTerminalSearchRunResult {
  const trimmed = query.trim()
  if (!trimmed) {
    return { found: false, matchCount: 0, resultIndex: -1 }
  }

  let addonResultCount = 0
  let addonResultIndex = -1
  const resultsDisposable = searchAddon.onDidChangeResults((results) => {
    addonResultCount = results.resultCount
    addonResultIndex = results.resultIndex
  })

  try {
    if (direction === 'first' || direction === 'last') {
      searchAddon.clearDecorations()
      terminal.clearSelection()
    }

    let found = false
    switch (direction) {
      case 'next':
        found = searchAddon.findNext(trimmed, options)
        break
      case 'previous':
        found = searchAddon.findPrevious(trimmed, options)
        break
      case 'first':
        found =
          searchAddon.findNext(trimmed, options) ||
          searchAddon.findPrevious(trimmed, options)
        break
      case 'last':
        found =
          searchAddon.findPrevious(trimmed, options) ||
          searchAddon.findNext(trimmed, options)
        break
    }

    const bufferMatchCount = countTerminalSearchMatches(
      terminal,
      trimmed,
      options.caseSensitive ?? false,
    )
    const matchCount = Math.max(bufferMatchCount, addonResultCount)
    const hasMatch = found || matchCount > 0

    return {
      found: hasMatch,
      matchCount,
      resultIndex: resolveNavigationResultIndex(
        direction,
        addonResultCount,
        addonResultIndex,
        matchCount,
        hasMatch,
      ),
    }
  } finally {
    resultsDisposable.dispose()
  }
}

/** Run a terminal search from the full scrollback, not only below the input cursor. */
export function runCliTerminalSearch(
  terminal: Terminal,
  searchAddon: SearchAddon,
  query: string,
  options: ISearchOptions,
): CliTerminalSearchRunResult {
  const trimmed = query.trim()
  if (!trimmed) {
    searchAddon.clearDecorations()
    return { found: false, matchCount: 0, resultIndex: -1 }
  }

  terminal.clearSelection()

  let addonResultCount = 0
  let addonResultIndex = -1
  const resultsDisposable = searchAddon.onDidChangeResults((results) => {
    addonResultCount = results.resultCount
    addonResultIndex = results.resultIndex
  })

  try {
    const found =
      searchAddon.findNext(trimmed, options) ||
      searchAddon.findPrevious(trimmed, options)

    const bufferMatchCount = countTerminalSearchMatches(
      terminal,
      trimmed,
      options.caseSensitive ?? false,
    )
    const matchCount = Math.max(bufferMatchCount, addonResultCount)
    const hasMatch = found || matchCount > 0

    return {
      found: hasMatch,
      matchCount,
      resultIndex: resolveNavigationResultIndex(
        'first',
        addonResultCount,
        addonResultIndex,
        matchCount,
        hasMatch,
      ),
    }
  } finally {
    resultsDisposable.dispose()
  }
}
