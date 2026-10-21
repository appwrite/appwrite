import type { CliShellContainer } from './types'
import {
  APPWRITE_CLI_SUBCOMMANDS,
  APPWRITE_CLI_TOPICS,
} from './cli-appwrite-commands'
import { CLI_PROJECT_CWD } from './constants'

type CliVfs = Pick<
  CliShellContainer['vfs'],
  'existsSync' | 'readdirSync' | 'statSync'
>

export type TabCompletionContext = {
  vfs: CliVfs | null
  cwd?: string
  homeDir?: string
  pathDirs?: string[]
}

export type TabCompletionResult =
  | { kind: 'none' }
  | { kind: 'apply'; replacement: string; suffix: '' | ' ' | '/' }
  | { kind: 'list'; matches: string[] }

const DEFAULT_HOME = '/home/user'
const DEFAULT_PATH_DIRS = [
  '/usr/local/bin',
  '/usr/bin',
  '/bin',
  '/node_modules/.bin',
]

const SHELL_BUILTINS = [
  'alias',
  'cd',
  'echo',
  'env',
  'export',
  'false',
  'pwd',
  'set',
  'source',
  'test',
  'true',
  'unset',
  'which',
  'cat',
  'clear',
  'cls',
  'cp',
  'grep',
  'head',
  'ls',
  'mkdir',
  'mv',
  'node',
  'npm',
  'npx',
  'rm',
  'tail',
  'touch',
] as const

const FILE_ARG_COMMANDS = new Set([
  'cat',
  'cd',
  'cp',
  'head',
  'ls',
  'mkdir',
  'mv',
  'node',
  'npm',
  'npx',
  'rm',
  'tail',
  'touch',
])

export function getWordBounds(
  input: string,
  cursor: number,
): { start: number; end: number; word: string } {
  const safeCursor = Math.max(0, Math.min(cursor, input.length))
  let start = safeCursor
  while (start > 0 && !/\s/.test(input[start - 1] ?? '')) start--
  let end = safeCursor
  while (end < input.length && !/\s/.test(input[end] ?? '')) end--
  return { start, end, word: input.slice(start, end) }
}

function getTokenIndex(input: string, cursor: number): number {
  const before = input.slice(0, cursor)
  let count = 0
  let inWord = false
  for (const char of before) {
    if (/\s/.test(char)) {
      inWord = false
    } else if (!inWord) {
      count++
      inWord = true
    }
  }
  // Cursor after whitespace is completing the next token (e.g. `appwrite ` → topics).
  if (cursor > 0 && /\s/.test(input[cursor - 1] ?? '') && !inWord) {
    return count
  }
  return Math.max(0, count - 1)
}

function getFirstToken(input: string): string {
  const match = input.trim().match(/^(\S+)/)
  return match?.[1] ?? ''
}

function normalizePath(value: string): string {
  if (!value) return '.'
  const isAbsolute = value.startsWith('/')
  const parts = value.split('/').filter(Boolean)
  const resolved: string[] = []
  for (const part of parts) {
    if (part === '..') {
      if (resolved.length > 0 && resolved[resolved.length - 1] !== '..') {
        resolved.pop()
      } else if (!isAbsolute) {
        resolved.push('..')
      }
    } else if (part !== '.') {
      resolved.push(part)
    }
  }
  const joined = resolved.join('/')
  if (isAbsolute) return `/${joined}` || '/'
  return joined || '.'
}

function joinPath(...segments: string[]): string {
  return normalizePath(segments.filter(Boolean).join('/'))
}

function longestCommonPrefix(values: string[]): string {
  if (values.length === 0) return ''
  let prefix = values[0] ?? ''
  for (const value of values.slice(1)) {
    let index = 0
    while (
      index < prefix.length &&
      index < value.length &&
      prefix[index] === value[index]
    ) {
      index++
    }
    prefix = prefix.slice(0, index)
    if (!prefix) break
  }
  return prefix
}

function listPathCompletions(
  vfs: CliVfs,
  cwd: string,
  homeDir: string,
  partial: string,
): { replacement: string; isDir: boolean }[] {
  const tildeExpanded = partial.startsWith('~')
    ? `${homeDir}${partial.slice(1)}`
    : partial

  let dirPath: string
  let prefix: string
  let displayPrefix: string

  if (partial.includes('/')) {
    const slashIndex = tildeExpanded.lastIndexOf('/')
    const dirPart = slashIndex === 0 ? '/' : tildeExpanded.slice(0, slashIndex)
    prefix = tildeExpanded.slice(slashIndex + 1)
    displayPrefix = partial.slice(0, partial.lastIndexOf('/') + 1)

    if (partial.startsWith('/') || partial.startsWith('~')) {
      dirPath = normalizePath(dirPart || '/')
    } else {
      dirPath = joinPath(cwd, dirPart)
    }
  } else {
    dirPath = cwd
    prefix = partial
    displayPrefix = ''
  }

  if (!vfs.existsSync(dirPath)) return []
  try {
    if (!vfs.statSync(dirPath).isDirectory()) return []
  } catch {
    return []
  }

  return vfs
    .readdirSync(dirPath)
    .filter((name) => name.startsWith(prefix))
    .sort()
    .map((name) => {
      const fullPath = joinPath(dirPath, name)
      let isDir = false
      try {
        isDir = vfs.statSync(fullPath).isDirectory()
      } catch {
        isDir = false
      }
      return {
        replacement: `${displayPrefix}${name}`,
        isDir,
      }
    })
}

function listPathExecutables(
  vfs: CliVfs | null,
  pathDirs: string[],
): string[] {
  if (!vfs) return []
  const names = new Set<string>()
  for (const dir of pathDirs) {
    if (!vfs.existsSync(dir)) continue
    try {
      if (!vfs.statSync(dir).isDirectory()) continue
    } catch {
      continue
    }
    for (const entry of vfs.readdirSync(dir)) {
      if (entry.startsWith('.')) continue
      names.add(entry)
    }
  }
  return [...names].sort()
}

function shouldCompletePaths(
  command: string,
  tokenIndex: number,
  word: string,
): boolean {
  if (word.startsWith('-')) return false
  if (/^(~|\.\.?\/|\/)/.test(word) || word.includes('/')) return true
  if (tokenIndex === 0) return false
  if (command === 'appwrite') return false
  return FILE_ARG_COMMANDS.has(command)
}

function completeFromCandidates(
  word: string,
  candidates: string[],
  listOnly: boolean,
): TabCompletionResult {
  const matches = candidates.filter((candidate) => candidate.startsWith(word))
  if (matches.length === 0) return { kind: 'none' }

  if (matches.length === 1) {
    const match = matches[0] ?? ''
    return {
      kind: 'apply',
      replacement: match,
      suffix: ' ',
    }
  }

  const prefix = longestCommonPrefix(matches)
  if (prefix.length > word.length) {
    return { kind: 'apply', replacement: prefix, suffix: '' }
  }

  if (listOnly) {
    return { kind: 'list', matches }
  }

  return { kind: 'none' }
}

export function tabComplete(
  input: string,
  cursor: number,
  context: TabCompletionContext,
  listOnly = false,
): TabCompletionResult {
  const { start, end, word } = getWordBounds(input, cursor)
  if (start !== end && word.startsWith('-')) {
    return { kind: 'none' }
  }

  const tokenIndex = getTokenIndex(input, cursor)
  const command = getFirstToken(input)
  const cwd = context.cwd ?? CLI_PROJECT_CWD
  const homeDir = context.homeDir ?? DEFAULT_HOME
  const pathDirs = context.pathDirs ?? DEFAULT_PATH_DIRS
  const vfs = context.vfs

  if (command === 'appwrite') {
    if (tokenIndex === 1) {
      return completeFromCandidates(word, [...APPWRITE_CLI_TOPICS], listOnly)
    }
    if (tokenIndex === 2) {
      const topic = input.trim().split(/\s+/)[1] ?? ''
      const subcommands = APPWRITE_CLI_SUBCOMMANDS[topic] ?? []
      if (subcommands.length > 0) {
        return completeFromCandidates(word, [...subcommands], listOnly)
      }
    }
    if (tokenIndex === 0) {
      if (listOnly && word === 'appwrite') {
        return { kind: 'list', matches: [...APPWRITE_CLI_TOPICS] }
      }
      return completeFromCandidates(word, ['appwrite'], listOnly)
    }
    return { kind: 'none' }
  }

  if (tokenIndex === 0) {
    const commands = [
      'appwrite',
      ...SHELL_BUILTINS,
      ...listPathExecutables(vfs, pathDirs),
    ]
    const unique = [...new Set(commands)].sort()
    return completeFromCandidates(word, unique, listOnly)
  }

  if (shouldCompletePaths(command, tokenIndex, word) && vfs) {
    const pathMatches = listPathCompletions(vfs, cwd, homeDir, word)
    if (pathMatches.length === 0) return { kind: 'none' }

    if (pathMatches.length === 1) {
      const match = pathMatches[0]
      if (!match) return { kind: 'none' }
      return {
        kind: 'apply',
        replacement: match.replacement,
        suffix: match.isDir ? '/' : ' ',
      }
    }

    const replacements = pathMatches.map((match) => match.replacement)
    const prefix = longestCommonPrefix(replacements)
    if (prefix.length > word.length) {
      return { kind: 'apply', replacement: prefix, suffix: '' }
    }

    if (listOnly) {
      return { kind: 'list', matches: replacements }
    }

    return { kind: 'none' }
  }

  return { kind: 'none' }
}

export function applyTabCompletion(
  input: string,
  cursor: number,
  result: Extract<TabCompletionResult, { kind: 'apply' }>,
): { input: string; cursor: number } {
  const { start, end } = getWordBounds(input, cursor)
  const suffixChar = result.suffix ?? ''
  const nextInput =
    input.slice(0, start) + result.replacement + suffixChar + input.slice(end)
  const nextCursor = start + result.replacement.length + suffixChar.length
  return { input: nextInput, cursor: nextCursor }
}
