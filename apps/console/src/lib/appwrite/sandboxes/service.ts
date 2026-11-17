/**
 * Hand-written Sandboxes client: the sandboxes API is Cloud only and has not
 * shipped in a Console SDK release yet, so the generated SDK carries no
 * `Sandboxes` service. Keep it in step with Cloud's
 * `src/Appwrite/Cloud/Platform/Modules/Sandboxes`, and delete this folder for
 * the generated one once it ships.
 */

import type { Client } from '@appwrite.io/console'

import type {
  Sandbox,
  SandboxExecution,
  SandboxFileList,
  SandboxImage,
  SandboxList,
  SandboxOutput,
  SandboxProcess,
  SandboxProcessList,
} from './models'

export class Sandboxes {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  /** List the project's sandboxes. */
  list(
    params: { queries?: string[]; total?: boolean } = {},
  ): Promise<SandboxList> {
    const payload: Record<string, unknown> = {}
    if (typeof params.queries !== 'undefined') {
      payload['queries'] = params.queries
    }
    if (typeof params.total !== 'undefined') {
      payload['total'] = params.total
    }
    return this.client.call(
      'GET',
      this.uri('/sandboxes'),
      this.headers(),
      payload,
    )
  }

  /**
   * Create a sandbox. It starts in `creating` and turns `ready` once the
   * orchestrator has claimed a workspace for it.
   */
  create(params: {
    sandboxId: string
    image: SandboxImage
    specification?: string
    timeout?: number
    idleTimeout?: number
    variables?: Record<string, string>
  }): Promise<Sandbox> {
    const payload: Record<string, unknown> = {
      sandboxId: params.sandboxId,
      image: params.image,
    }
    for (const key of [
      'specification',
      'timeout',
      'idleTimeout',
      'variables',
    ] as const) {
      if (typeof params[key] !== 'undefined') payload[key] = params[key]
    }
    return this.client.call(
      'POST',
      this.uri('/sandboxes'),
      this.json(),
      payload,
    )
  }

  get(params: { sandboxId: string }): Promise<Sandbox> {
    return this.client.call(
      'GET',
      this.uri(this.sandbox(params.sandboxId)),
      this.headers(),
      {},
    )
  }

  delete(params: { sandboxId: string }): Promise<unknown> {
    return this.client.call(
      'DELETE',
      this.uri(this.sandbox(params.sandboxId)),
      this.json(),
      {},
    )
  }

  /** Run a command to completion. 409 while another command runs. */
  createExecution(params: {
    sandboxId: string
    command: string
    timeout?: number
  }): Promise<SandboxExecution> {
    const payload: Record<string, unknown> = { command: params.command }
    if (typeof params.timeout !== 'undefined')
      payload['timeout'] = params.timeout
    return this.client.call(
      'POST',
      this.uri(`${this.sandbox(params.sandboxId)}/executions`),
      this.json(),
      payload,
    )
  }

  /** List a directory of the sandbox workspace. */
  listFiles(params: {
    sandboxId: string
    path: string
  }): Promise<SandboxFileList> {
    return this.client.call(
      'GET',
      this.uri(`${this.sandbox(params.sandboxId)}/files`),
      this.headers(),
      { path: params.path },
    )
  }

  /** Upload a whole file (at most 5 MB), creating its parent directories. */
  createFile(params: {
    sandboxId: string
    path: string
    file: File
  }): Promise<unknown> {
    return this.client.call(
      'POST',
      this.uri(`${this.sandbox(params.sandboxId)}/files`),
      { ...this.headers(), 'content-type': 'multipart/form-data' },
      { path: params.path, file: params.file },
    )
  }

  /** Download a file's bytes. */
  getFileDownload(params: {
    sandboxId: string
    path: string
  }): Promise<ArrayBuffer> {
    return this.client.call(
      'GET',
      this.uri(`${this.sandbox(params.sandboxId)}/files/download`),
      this.headers(),
      { path: params.path },
      'arrayBuffer',
    )
  }

  /** Delete a file or a whole directory tree. */
  deleteFile(params: { sandboxId: string; path: string }): Promise<unknown> {
    return this.client.call(
      'DELETE',
      this.uri(`${this.sandbox(params.sandboxId)}/files`),
      this.json(),
      { path: params.path },
    )
  }

  /**
   * Start a background process, or with `pty` a terminal of `cols` by `rows`.
   * An empty command on a terminal is an interactive shell.
   */
  createProcess(params: {
    sandboxId: string
    command?: string
    pty?: boolean
    cols?: number
    rows?: number
  }): Promise<SandboxProcess> {
    const payload: Record<string, unknown> = {}
    for (const key of ['command', 'pty', 'cols', 'rows'] as const) {
      if (typeof params[key] !== 'undefined') payload[key] = params[key]
    }
    return this.client.call(
      'POST',
      this.uri(`${this.sandbox(params.sandboxId)}/processes`),
      this.json(),
      payload,
    )
  }

  listProcesses(params: { sandboxId: string }): Promise<SandboxProcessList> {
    return this.client.call(
      'GET',
      this.uri(`${this.sandbox(params.sandboxId)}/processes`),
      this.headers(),
      {},
    )
  }

  getProcess(params: {
    sandboxId: string
    processId: string
  }): Promise<SandboxProcess> {
    return this.client.call(
      'GET',
      this.uri(this.process(params.sandboxId, params.processId)),
      this.headers(),
      {},
    )
  }

  /** Kill a process's whole tree if it still runs, and forget it. */
  deleteProcess(params: {
    sandboxId: string
    processId: string
  }): Promise<unknown> {
    return this.client.call(
      'DELETE',
      this.uri(this.process(params.sandboxId, params.processId)),
      this.json(),
      {},
    )
  }

  /** Output at or after `offset`, waiting up to `wait` seconds (at most 30) for some. */
  getProcessOutput(params: {
    sandboxId: string
    processId: string
    offset?: number
    wait?: number
  }): Promise<SandboxOutput> {
    const payload: Record<string, unknown> = {}
    if (typeof params.offset !== 'undefined') payload['offset'] = params.offset
    if (typeof params.wait !== 'undefined') payload['wait'] = params.wait
    return this.client.call(
      'GET',
      this.uri(`${this.process(params.sandboxId, params.processId)}/output`),
      this.headers(),
      payload,
    )
  }

  /** Write to a process's stdin, or type into its terminal. 409 once it has exited. */
  createProcessInput(params: {
    sandboxId: string
    processId: string
    data: string
  }): Promise<unknown> {
    return this.client.call(
      'POST',
      this.uri(`${this.process(params.sandboxId, params.processId)}/input`),
      this.json(),
      { data: params.data },
    )
  }

  /** Resize a process's terminal. 400 for a process without one. */
  updateProcessSize(params: {
    sandboxId: string
    processId: string
    cols: number
    rows: number
  }): Promise<SandboxProcess> {
    return this.client.call(
      'PATCH',
      this.uri(`${this.process(params.sandboxId, params.processId)}/size`),
      this.json(),
      { cols: params.cols, rows: params.rows },
    )
  }

  private sandbox(sandboxId: string): string {
    return `/sandboxes/${encodeURIComponent(sandboxId)}`
  }

  private process(sandboxId: string, processId: string): string {
    return `${this.sandbox(sandboxId)}/processes/${encodeURIComponent(processId)}`
  }

  private uri(apiPath: string): URL {
    return new URL(this.client.config.endpoint + apiPath)
  }

  private headers(): Record<string, string> {
    return {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }
  }

  private json(): Record<string, string> {
    return { ...this.headers(), 'content-type': 'application/json' }
  }
}
