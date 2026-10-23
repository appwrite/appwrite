import { describe, expect, test } from 'bun:test'
import {
  applyConsoleAction,
  type ConsoleProtocolHandlers,
} from '@/lib/assistant/console-dispatch'
import {
  normalizeConsolePath,
  resolveConsoleListHref,
  resolveConsoleResourceHref,
} from '@/lib/assistant/console-protocol'

const origin = 'https://cloud.appwrite.io'

// Each resolves to https://evil.example once the browser navigates: `\` parses
// as `/`, and tab, newline and CR are stripped before parsing.
const offOrigin = [
  '//evil.example/x',
  '///evil.example',
  '/\\evil.example',
  '/\\/evil.example',
  '\\\\evil.example',
  '/\t/evil.example',
  '/\n/evil.example',
  '/\r/evil.example',
]

function recordingHandlers(): {
  handlers: ConsoleProtocolHandlers
  paths: string[]
} {
  const paths: string[] = []
  const ignore = () => {}
  return {
    paths,
    handlers: {
      setTheme: ignore,
      navigate: ({ path }) => {
        paths.push(path)
      },
      openCreate: ignore,
      openDialog: ignore,
      showPane: ignore,
      toggleTerminal: ignore,
      refreshScopes: ignore,
    },
  }
}

describe('normalizeConsolePath', () => {
  test('keeps console paths and maps the engine project prefix', () => {
    expect(normalizeConsolePath('/projects/abc/databases?page=2')).toBe(
      '/projects/abc/databases?page=2',
    )
    expect(normalizeConsolePath('projects/abc')).toBe('/projects/abc')
    expect(normalizeConsolePath('/project/abc/auth')).toBe('/projects/abc/auth')
    expect(normalizeConsolePath('/project')).toBe('/projects')
    expect(normalizeConsolePath('  ')).toBe('')
  })

  test('refuses paths the browser resolves to another origin', () => {
    for (const path of offOrigin) {
      expect(new URL(path, origin).origin).not.toBe(origin)
      expect(normalizeConsolePath(path)).toBe('')
    }
    expect(normalizeConsolePath('/projects/abc\u0000')).toBe('')
  })
})

describe('applyConsoleAction navigate', () => {
  test('navigates to console paths only', () => {
    const { handlers, paths } = recordingHandlers()
    for (const path of [...offOrigin, '/project/abc']) {
      applyConsoleAction({ type: 'navigate', path }, handlers)
    }
    expect(paths).toEqual(['/projects/abc'])
  })
})

describe('resource links', () => {
  test('fall back to the built path when the explicit href leaves the origin', () => {
    expect(
      resolveConsoleResourceHref(
        { href: '//evil.example/x', resourceId: 'u1', fields: {} },
        'users',
        'p1',
      ),
    ).toBe('/projects/p1/auth/users/u1')
    expect(resolveConsoleListHref('/\\evil.example', 'users', 'p1')).toBe(
      '/projects/p1/auth',
    )
    expect(
      resolveConsoleResourceHref(
        { href: '//evil.example/x', resourceId: 'x', fields: {} },
        'unknown',
        null,
      ),
    ).toBeUndefined()
  })
})
