/**
 * Flag invisible Unicode in editorial content (NBSP, zero-width, BOM, bidi overrides).
 *
 * Usage:
 *   bun run lint:content
 *   bun run lint:content -- --fix
 *
 * Exit 1 when issues remain (for CI).
 */
import { readdirSync, readFileSync, writeFileSync } from 'fs'
import { join, relative } from 'path'

const CONTENT_DIR = join(import.meta.dirname, '../src/content')
const TEXT_EXTENSIONS = new Set(['.md', '.mdx', '.markdoc', '.txt'])

export const HIDDEN_CHARS: Record<number, string> = {
  0x00a0: 'NBSP',
  0x00ad: 'SOFT HYPHEN',
  0x034f: 'COMBINING GRAPHEME JOINER',
  0x180e: 'MONGOLIAN VOWEL SEPARATOR',
  0x200b: 'ZERO WIDTH SPACE',
  0x200c: 'ZERO WIDTH NON-JOINER',
  0x200d: 'ZERO WIDTH JOINER',
  0x202a: 'LEFT-TO-RIGHT EMBEDDING',
  0x202b: 'RIGHT-TO-LEFT EMBEDDING',
  0x202c: 'POP DIRECTIONAL FORMATTING',
  0x202d: 'LEFT-TO-RIGHT OVERRIDE',
  0x202e: 'RIGHT-TO-LEFT OVERRIDE',
  0x202f: 'NARROW NBSP',
  0x2060: 'WORD JOINER',
  0x2061: 'FUNCTION APPLICATION',
  0x2062: 'INVISIBLE TIMES',
  0x2063: 'INVISIBLE SEPARATOR',
  0x2064: 'INVISIBLE PLUS',
  0x2066: 'LEFT-TO-RIGHT ISOLATE',
  0x2067: 'RIGHT-TO-LEFT ISOLATE',
  0x2068: 'FIRST STRONG ISOLATE',
  0x2069: 'POP DIRECTIONAL ISOLATE',
  0xfeff: 'BOM / ZERO WIDTH NO-BREAK SPACE',
}

const REPLACE_WITH_SPACE = new Set([0x00a0, 0x202f])

export type HiddenCharHit = {
  line: number
  column: number
  codePoint: number
  name: string
}

export function findHiddenCharsInText(text: string): HiddenCharHit[] {
  const hits: HiddenCharHit[] = []
  let line = 1
  let column = 1
  for (const char of text) {
    const codePoint = char.codePointAt(0) ?? 0
    const name = HIDDEN_CHARS[codePoint]
    if (name) {
      hits.push({ line, column, codePoint, name })
    }
    if (char === '\n') {
      line += 1
      column = 1
    } else {
      column += 1
    }
  }
  return hits
}

export function fixHiddenChars(text: string): string {
  let result = ''
  for (const char of text) {
    const codePoint = char.codePointAt(0) ?? 0
    if (REPLACE_WITH_SPACE.has(codePoint)) {
      result += ' '
      continue
    }
    if (HIDDEN_CHARS[codePoint]) {
      continue
    }
    result += char
  }
  return result
}

function listContentFiles(dir: string): string[] {
  const files: string[] = []
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name)
    if (entry.isDirectory()) {
      files.push(...listContentFiles(path))
      continue
    }
    const ext = entry.name.slice(entry.name.lastIndexOf('.'))
    if (TEXT_EXTENSIONS.has(ext)) {
      files.push(path)
    }
  }
  return files
}

export function scanContentFiles(rootDir: string = CONTENT_DIR) {
  const issues: Array<{ file: string; hits: HiddenCharHit[] }> = []
  for (const file of listContentFiles(rootDir)) {
    const text = readFileSync(file, 'utf8')
    const hits = findHiddenCharsInText(text)
    if (hits.length > 0) {
      issues.push({ file, hits })
    }
  }
  return issues
}

function report(issues: Array<{ file: string; hits: HiddenCharHit[] }>, rootDir: string) {
  const total = issues.reduce((sum, issue) => sum + issue.hits.length, 0)
  console.error(`Hidden Unicode: ${total} character(s) in ${issues.length} file(s)\n`)
  for (const { file, hits } of issues) {
    const rel = relative(join(rootDir, '../..'), file)
    console.error(rel)
    for (const hit of hits.slice(0, 8)) {
      console.error(`  L${hit.line}:${hit.column}  U+${hit.codePoint.toString(16).toUpperCase().padStart(4, '0')}  ${hit.name}`)
    }
    if (hits.length > 8) {
      console.error(`  … and ${hits.length - 8} more`)
    }
  }
  console.error('\nReplace NBSP with a normal space. Strip zero-width and BOM characters.')
  console.error('Fix: bun run lint:content -- --fix')
}

function main() {
  const shouldFix = process.argv.includes('--fix')
  const issues = scanContentFiles(CONTENT_DIR)

  if (issues.length === 0) {
    console.log('Hidden Unicode: no issues found in src/content.')
    process.exit(0)
  }

  if (shouldFix) {
    for (const { file } of issues) {
      const text = readFileSync(file, 'utf8')
      writeFileSync(file, fixHiddenChars(text), 'utf8')
    }
    const remaining = scanContentFiles(CONTENT_DIR)
    if (remaining.length === 0) {
      console.log(`Hidden Unicode: fixed ${issues.length} file(s).`)
      process.exit(0)
    }
    report(remaining, CONTENT_DIR)
    process.exit(1)
  }

  report(issues, CONTENT_DIR)
  process.exit(1)
}

if (import.meta.main) {
  main()
}
