import { describe, expect, test } from 'bun:test'
import { readFileSync } from 'fs'
import { join } from 'path'

const ROOT = join(import.meta.dirname, '../..')

// Code that runs on the console server, plus its process config.
const SERVER_FILES = [
  ...new Bun.Glob('src/server/**/*.{ts,tsx}').scanSync({ cwd: ROOT }),
  ...new Bun.Glob('src/routes/_api/**/*.{ts,tsx}').scanSync({ cwd: ROOT }),
  'src/start.ts',
  'server.ts',
  'pm2.config.cjs',
]

describe('console server', () => {
  // Server functions and API routes answer anyone who calls them, so the
  // console server must not hold an Appwrite API key to act with.
  test('reads no Appwrite API key and uses no server SDK', () => {
    const offenders = SERVER_FILES.filter((file) => {
      const source = readFileSync(join(ROOT, file), 'utf8')
      return (
        source.includes('APPWRITE_API_KEY') ||
        source.includes("from 'node-appwrite'")
      )
    })
    expect(offenders).toEqual([])
  })
})
