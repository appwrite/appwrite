/**
 * The browser CLI runtime, exercised against the real artifact.
 *
 * The pieces that can silently be wrong here are not the ones a type checker
 * sees. Go's js/wasm syscall layer reads every field of a stat object with
 * `Value.Int()`, which traps on undefined rather than defaulting; it composes
 * open() flags from the values we hand it in `fs.constants`; and it decides
 * between fetch and raw sockets by looking at `process.argv0`. All three are
 * agreements with code we do not control, so they are asserted against the
 * actual module rather than a stub of it.
 *
 * Runs against the `appwrite-cli-wasm` package -- the same two files the app
 * loads, resolved from node_modules rather than from a copy, so a version bump
 * cannot leave the tests asserting against the artifact it replaced.
 */

import { describe, expect, test } from 'bun:test'
import { existsSync } from 'node:fs'
import { pathToFileURL } from 'node:url'
import { createWasmCliContainer } from '@/lib/cli-shell/wasm/runtime'
import { splitCommand } from '@/lib/cli-shell/wasm/split-command'
import { Vfs } from '@/lib/cli-shell/wasm/vfs'

const artifactPath = require.resolve('appwrite-cli-wasm/appwrite.wasm')
const gluePath = require.resolve('appwrite-cli-wasm/wasm_exec.js')
const hasArtifact = existsSync(artifactPath) && existsSync(gluePath)

const container = async () =>
  createWasmCliContainer({
    cwd: '/project',
    wasmUrl: pathToFileURL(artifactPath).href,
    wasmExecUrl: pathToFileURL(gluePath).href,
  })

describe('splitCommand', () => {
  test('splits on whitespace', () => {
    expect(splitCommand('users list --json')).toEqual([
      'users',
      'list',
      '--json',
    ])
  })

  test('keeps quoted JSON in one argument', () => {
    expect(splitCommand(`tablesdb create-row --data '{"a": 1}'`)).toEqual([
      'tablesdb',
      'create-row',
      '--data',
      '{"a": 1}',
    ])
  })

  test('keeps an empty quoted argument', () => {
    expect(splitCommand('users update --name ""')).toEqual([
      'users',
      'update',
      '--name',
      '',
    ])
  })
})

describe('Vfs', () => {
  test('round-trips a file through the directory it was written into', () => {
    const vfs = new Vfs()
    vfs.mkdirSync('/home/appwrite/.appwrite', { recursive: true })
    vfs.writeFileSync('/home/appwrite/.appwrite/prefs.json', '{"current":"x"}')

    expect(
      vfs.readFileSync('/home/appwrite/.appwrite/prefs.json', 'utf8'),
    ).toBe('{"current":"x"}')
    expect(vfs.readdirSync('/home/appwrite/.appwrite')).toEqual(['prefs.json'])
    expect(vfs.statSync('/home/appwrite/.appwrite').isDirectory()).toBe(true)
  })

  test('refuses a write into a directory that does not exist', () => {
    const vfs = new Vfs()
    expect(() => vfs.writeFileSync('/nope/file.json', 'x')).toThrow()
  })
})

describe.if(hasArtifact)('wasm runtime', () => {
  test('reports the version it was linked with', async () => {
    const cli = await container()
    const result = await cli.run('--version')

    expect(result.exitCode).toBe(0)
    expect(result.stdout).toContain('appwrite version')
  })

  test('reads a preferences file written through the sync API', async () => {
    const cli = await container()
    cli.vfs.mkdirSync('/home/appwrite/.appwrite', { recursive: true })
    cli.vfs.writeFileSync(
      '/home/appwrite/.appwrite/prefs.json',
      JSON.stringify({
        current: 'console',
        console: {
          endpoint: 'https://cloud.appwrite.io/v1',
          email: 'someone@example.com',
          cookie: 'a_session_console=secret',
        },
      }),
    )

    const result = await cli.run('sessions')

    // The session is listed, which it can only be if open/read/fstat/readdir all
    // answered -- the CLI reads this file through Go's os package, not ours.
    expect(result.stdout + result.stderr).toContain('someone@example.com')
  })

  test('stubs a host-bound command instead of failing to find it', async () => {
    const cli = await container()
    const result = await cli.run('push')
    const output = result.stdout + result.stderr

    expect(output.toLowerCase()).not.toContain('unknown command')
    expect(output).toContain('browser')
    expect(result.exitCode).not.toBe(0)
  })

  test('writes what the CLI writes back into the shared filesystem', async () => {
    const cli = await container()
    await cli.run('client --endpoint https://cloud.appwrite.io/v1')

    // `client` persists to prefs.json. Seeing it here proves the write path --
    // open with O_CREAT, write, rename -- lands in the same VFS the console reads.
    expect(cli.vfs.existsSync('/home/appwrite/.appwrite/prefs.json')).toBe(true)
  })

  test('separates stdout from stderr', async () => {
    const cli = await container()
    const chunks: { out: string[]; err: string[] } = { out: [], err: [] }

    await cli.run('whoami', {
      onStdout: (chunk) => chunks.out.push(chunk),
      onStderr: (chunk) => chunks.err.push(chunk),
    })

    // No session is configured, so this is the error path and it must not have
    // been written to stdout -- `--json` output is parsed by scripts.
    expect(chunks.err.join('')).toContain('login')
    expect(chunks.out.join('')).toBe('')
  })

  test('survives several commands in a row', async () => {
    const cli = await container()

    // The case that matters, and the one a single-command test cannot reach:
    // Go asks the host for a timer whenever a goroutine sleeps, and `main`
    // returning does not cancel the outstanding ones. A timer that fires after
    // its instance exited calls _resume() on a dead instance and throws from a
    // setTimeout callback -- uncatchable by the caller, fatal to the page. It
    // needs something to keep the runtime alive long enough to fire, which in
    // the console is simply the next command.
    for (const command of ['--version', 'help', 'whoami', 'sessions']) {
      await cli.run(command)
    }

    await new Promise((resolve) => setTimeout(resolve, 100))

    const result = await cli.run('--version')
    expect(result.exitCode).toBe(0)
  })

  test('leaves no globals behind after a run', async () => {
    const cli = await container()
    const before = {
      fs: 'fs' in globalThis,
      process: (globalThis as { process?: unknown }).process,
    }

    await cli.run('--version')

    expect('fs' in globalThis).toBe(before.fs)
    expect((globalThis as { process?: unknown }).process).toBe(before.process)
  })
})
