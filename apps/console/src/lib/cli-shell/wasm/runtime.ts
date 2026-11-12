/**
 * The browser runtime for the Go Appwrite CLI.
 *
 * Replaces the almostnode container: instead of npm-installing a JavaScript CLI
 * and running it under a Node emulator, this fetches one `.wasm` module,
 * compiles it once, and instantiates a fresh instance per command.
 *
 * Per command, not per session, because a Go wasm instance runs exactly once --
 * `go.run()` resolves when `main` returns and the instance is spent. Compilation
 * is the expensive half and it is cached, so a second command pays only for
 * instantiation.
 */

import { createFsBridge } from './fs-bridge'
import { readCachedModuleBytes, writeCachedModuleBytes } from './module-cache'
import { splitCommand } from './split-command'
import { Vfs } from './vfs'
import { CLI_WASM_HOME, CLI_WASM_VERSION } from './constants'

export type WasmRunOptions = {
  cwd?: string
  onStdout?: (chunk: string) => void
  onStderr?: (chunk: string) => void
}

export type WasmRunResult = {
  stdout: string
  stderr: string
  exitCode: number
}

export type WasmCliContainer = {
  vfs: Vfs
  run(command: string, options?: WasmRunOptions): Promise<WasmRunResult>
}

type GoInstance = {
  argv: string[]
  env: Record<string, string>
  exit: (code: number) => void
  importObject: WebAssembly.Imports
  run(instance: WebAssembly.Instance): Promise<void>
  /**
   * Timers the Go runtime scheduled, by id.
   *
   * Private to wasm_exec.js, and reached into deliberately -- see
   * {@link clearPendingTimers}. Safe to depend on because the glue is published
   * with the module it belongs to, so the two never disagree about their shape.
   */
  _scheduledTimeouts: Map<number, ReturnType<typeof setTimeout>>
}

/**
 * Cancel timers left behind by a finished program.
 *
 * Go's scheduler asks the host for a timer whenever a goroutine sleeps or an
 * HTTP call sets a deadline, and `main` returning does not cancel the ones still
 * outstanding. When such a timer fires, its callback calls `_resume()` on an
 * instance that has already exited, and wasm_exec.js throws "Go program has
 * already exited" -- from a setTimeout callback, so it lands as an uncaught
 * error on the page rather than as a rejected promise the caller could handle.
 *
 * It only reproduces when something keeps the page alive long enough for the
 * timer to fire, which in the console means the very next command the user
 * types.
 */
function clearPendingTimers(go: GoInstance): void {
  for (const timer of go._scheduledTimeouts.values()) {
    clearTimeout(timer)
  }
  go._scheduledTimeouts.clear()
}

type GoConstructor = new () => GoInstance

let modulePromise: Promise<WebAssembly.Module> | null = null
let gluePromise: Promise<void> | null = null

/**
 * Compile the CLI module, from the IndexedDB cache when it is there.
 *
 * The artifact is ~22 MB, so the cache is the difference between a one-second
 * start and a ten-second one on a cold network.
 *
 * Keyed by the URL, which the build fingerprints with the artifact's content --
 * so a new CLI release misses the cache by construction. A hand-maintained
 * version key would be one more thing that can silently disagree with what is
 * actually being served.
 */
async function loadModule(
  url: string,
  onProgress?: (message: string) => void,
): Promise<WebAssembly.Module> {
  if (modulePromise) return modulePromise

  modulePromise = (async () => {
    const cached = await readCachedModuleBytes(url)
    if (cached) {
      onProgress?.(`Loaded cached Appwrite CLI ${CLI_WASM_VERSION}.`)
      return WebAssembly.compile(cached)
    }

    onProgress?.(
      `Downloading Appwrite CLI ${CLI_WASM_VERSION} (first run only)...`,
    )

    const response = await fetch(url)
    if (!response.ok) {
      throw new Error(
        `Failed to download the Appwrite CLI (${response.status}). Check your network connection and try again.`,
      )
    }

    const bytes = new Uint8Array(await response.arrayBuffer())
    // Compile before caching, so a truncated or corrupt download is never
    // written and then replayed on every reload.
    const compiled = await WebAssembly.compile(bytes)
    void writeCachedModuleBytes(url, bytes).catch(() => {})

    return compiled
  })().catch((error: unknown) => {
    // A failed load must not poison every later attempt.
    modulePromise = null
    throw error
  })

  return modulePromise
}

/**
 * Load Go's runtime glue, which defines `globalThis.Go`.
 *
 * Loaded lazily, and only ever after the fs/process/path globals are in place:
 * wasm_exec.js installs ENOSYS stubs for whichever of those three is missing
 * when it runs, and a page-wide `process` stub is exactly the kind of global
 * that makes a bundled library think it is running under Node.
 *
 * A script element in the browser, so the page's CSP can allow it as ordinary
 * same-origin script rather than needing `unsafe-eval`. Outside a document --
 * the unit tests -- there is no element to append, so it is fetched and
 * evaluated instead.
 */
async function loadGlue(url: string): Promise<void> {
  if (gluePromise) return gluePromise

  gluePromise = (async () => {
    if (typeof document !== 'undefined') {
      await new Promise<void>((resolve, reject) => {
        const script = document.createElement('script')
        script.src = url
        script.onload = () => resolve()
        script.onerror = () =>
          reject(new Error(`Failed to load the Appwrite CLI runtime: ${url}`))
        document.head.append(script)
      })
      return
    }

    const response = await fetch(url)
    if (!response.ok) {
      throw new Error(
        `Failed to load the Appwrite CLI runtime (${response.status}): ${url}`,
      )
    }
    new Function(await response.text())()
  })().catch((error: unknown) => {
    gluePromise = null
    throw error
  })

  return gluePromise
}

type InstalledGlobals = {
  restore(): void
}

function installGlobals(values: Record<string, unknown>): InstalledGlobals {
  const scope = globalThis as Record<string, unknown>
  const previous = new Map<string, { present: boolean; value: unknown }>()

  for (const [key, value] of Object.entries(values)) {
    previous.set(key, { present: key in scope, value: scope[key] })
    scope[key] = value
  }

  return {
    restore() {
      for (const [key, before] of previous) {
        if (before.present) {
          scope[key] = before.value
          continue
        }
        delete scope[key]
      }
    },
  }
}

export type CreateContainerOptions = {
  cwd: string
  env?: Record<string, string>
  onProgress?: (message: string) => void
  /** Where to fetch the module from; see asset-urls.ts. */
  wasmUrl: string
  /** Where to fetch Go's glue from. Must be from the same build as the module. */
  wasmExecUrl: string
}

export async function createWasmCliContainer(
  options: CreateContainerOptions,
): Promise<WasmCliContainer> {
  const vfs = new Vfs()
  const compiled = await loadModule(options.wasmUrl, options.onProgress)

  vfs.mkdirSync(options.cwd, { recursive: true })
  vfs.mkdirSync(`${CLI_WASM_HOME}/.appwrite`, { recursive: true })

  // Serialised deliberately. The fs/process globals are page-wide for the
  // duration of a run, so two concurrent runs would share one cwd and one set
  // of file descriptors. The terminal runs one command at a time anyway; this
  // makes that a property of the runtime rather than of the caller.
  let queue: Promise<unknown> = Promise.resolve()

  async function execute(
    command: string,
    runOptions: WasmRunOptions = {},
  ): Promise<WasmRunResult> {
    const argv = splitCommand(command)
    if (argv.length === 0) {
      return { stdout: '', stderr: '', exitCode: 0 }
    }

    let stdout = ''
    let stderr = ''

    const bridge = createFsBridge({
      vfs,
      cwd: runOptions.cwd ?? options.cwd,
      onStdout: (chunk) => {
        stdout += chunk
        runOptions.onStdout?.(chunk)
      },
      onStderr: (chunk) => {
        stderr += chunk
        runOptions.onStderr?.(chunk)
      },
    })

    const globals = installGlobals({
      fs: bridge.fs,
      process: bridge.process,
      path: bridge.path,
    })

    try {
      await loadGlue(options.wasmExecUrl)

      const Go = (globalThis as { Go?: GoConstructor }).Go
      if (!Go) {
        throw new Error('The Appwrite CLI runtime failed to load.')
      }

      const go = new Go()
      go.argv = ['appwrite', ...argv]
      go.env = {
        HOME: CLI_WASM_HOME,
        // TERM is what makes the CLI's renderer emit colour. The terminal this
        // output lands in is xterm.js, which wants the escapes.
        TERM: 'xterm-256color',
        ...options.env,
      }

      let exitCode = 0
      go.exit = (code: number) => {
        exitCode = code
      }

      const instance = await WebAssembly.instantiate(compiled, go.importObject)

      try {
        await go.run(instance)
      } finally {
        clearPendingTimers(go)
      }

      return { stdout, stderr, exitCode }
    } finally {
      globals.restore()
    }
  }

  return {
    vfs,
    run(command, runOptions) {
      const result = queue.then(() => execute(command, runOptions))
      // The queue must survive a failed command, or one error stops the shell.
      queue = result.catch(() => undefined)
      return result
    },
  }
}
