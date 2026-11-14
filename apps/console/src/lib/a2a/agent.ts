import { getDocsSearchHref, searchDocs } from '@/lib/docs/search'

/** A2A v1.0 Agent Card location (spec §8.2). */
export const A2A_AGENT_CARD_PATH = '/.well-known/agent-card.json'
export const A2A_ENDPOINT_PATH = '/a2a'

const ORIGIN = 'https://appwrite.io'
const RESULT_LIMIT = 5
// Search cost grows with every query token, so oversized questions are refused.
const TEXT_LIMIT = 500

const HEADERS = {
  'Access-Control-Allow-Origin': '*',
  'Cache-Control': 'no-store',
}

type Version = '0.3' | '1.0'

type RpcRequest = {
  jsonrpc?: unknown
  id?: unknown
  method?: unknown
  params?: { message?: { contextId?: unknown; parts?: unknown } }
}

type RpcError = { code: number; message: string }

const TASK_NOT_FOUND: RpcError = { code: -32001, message: 'Task not found' }
const PUSH_NOT_SUPPORTED: RpcError = {
  code: -32003,
  message: 'Push notifications are not supported',
}
const EXTENDED_CARD_NOT_CONFIGURED: RpcError = {
  code: -32007,
  message: 'No extended agent card is configured',
}

/** Methods this agent answers without ever creating a task. */
const NO_TASK_METHODS: Record<Version, Record<string, RpcError>> = {
  '1.0': {
    GetTask: TASK_NOT_FOUND,
    CancelTask: TASK_NOT_FOUND,
    SubscribeToTask: TASK_NOT_FOUND,
    CreateTaskPushNotificationConfig: PUSH_NOT_SUPPORTED,
    GetTaskPushNotificationConfig: PUSH_NOT_SUPPORTED,
    ListTaskPushNotificationConfigs: PUSH_NOT_SUPPORTED,
    DeleteTaskPushNotificationConfig: PUSH_NOT_SUPPORTED,
    GetExtendedAgentCard: EXTENDED_CARD_NOT_CONFIGURED,
  },
  '0.3': {
    'tasks/get': TASK_NOT_FOUND,
    'tasks/cancel': TASK_NOT_FOUND,
    'tasks/resubscribe': TASK_NOT_FOUND,
    'tasks/pushNotificationConfig/set': PUSH_NOT_SUPPORTED,
    'tasks/pushNotificationConfig/get': PUSH_NOT_SUPPORTED,
    'tasks/pushNotificationConfig/list': PUSH_NOT_SUPPORTED,
    'tasks/pushNotificationConfig/delete': PUSH_NOT_SUPPORTED,
    'agent/getAuthenticatedExtendedCard': EXTENDED_CARD_NOT_CONFIGURED,
  },
}

const SEND_METHODS: Record<Version, { send: string; stream: string }> = {
  '1.0': { send: 'SendMessage', stream: 'SendStreamingMessage' },
  '0.3': { send: 'message/send', stream: 'message/stream' },
}

/**
 * A2A Agent Card. The 1.0 fields come first; `url`, `protocolVersion` and
 * `preferredTransport` keep 0.3 clients working.
 */
export function buildAgentCard() {
  const url = `${ORIGIN}${A2A_ENDPOINT_PATH}`
  return {
    name: 'Appwrite Docs',
    description:
      'Answers questions about Appwrite by finding the most relevant pages in the Appwrite documentation.',
    supportedInterfaces: [
      { url, protocolBinding: 'JSONRPC', protocolVersion: '1.0' },
      { url, protocolBinding: 'JSONRPC', protocolVersion: '0.3' },
    ],
    provider: { organization: 'Appwrite', url: ORIGIN },
    version: '1.0.0',
    documentationUrl: `${ORIGIN}/docs`,
    capabilities: {
      streaming: true,
      pushNotifications: false,
      extendedAgentCard: false,
    },
    defaultInputModes: ['text/plain'],
    defaultOutputModes: ['text/plain', 'application/json'],
    skills: [
      {
        id: 'search-docs',
        name: 'Search the Appwrite docs',
        description:
          'Finds the Appwrite documentation pages that best match a question and returns their titles, summaries and links.',
        tags: ['appwrite', 'documentation', 'search', 'backend'],
        examples: ['How do I add OAuth login?', 'Deploy a function from Git'],
      },
    ],
    url,
    protocolVersion: '0.3.0',
    preferredTransport: 'JSONRPC',
  }
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { ...HEADERS, 'Content-Type': 'application/json; charset=utf-8' },
  })
}

function failure(id: unknown, error: RpcError, status = 200): Response {
  return json({ jsonrpc: '2.0', id: id ?? null, error }, status)
}

function readVersion(request: Request): Version | null {
  const value = (
    request.headers.get('a2a-version') ??
    new URL(request.url).searchParams.get('A2A-Version') ??
    ''
  ).trim()
  if (value === '' || value.startsWith('0.3')) return '0.3'
  if (value.startsWith('1.0')) return '1.0'
  return null
}

function readText(params: RpcRequest['params']): string | RpcError {
  const parts = params?.message?.parts
  if (!Array.isArray(parts) || parts.length === 0) {
    return { code: -32602, message: 'message.parts is required' }
  }
  const text = parts
    .map((part: { text?: unknown }) =>
      typeof part?.text === 'string' ? part.text : '',
    )
    .join(' ')
    .trim()
  if (!text) {
    return {
      code: -32005,
      message: 'Only text parts are supported',
    }
  }
  if (text.length > TEXT_LIMIT) {
    return {
      code: -32602,
      message: `The question must be at most ${TEXT_LIMIT} characters`,
    }
  }
  return text
}

function buildReply(text: string, contextId: string, version: Version) {
  const results = searchDocs(text, RESULT_LIMIT).map((entry) => ({
    title: entry.title,
    description: entry.description,
    section: entry.breadcrumbs.join(' / '),
    url: `${ORIGIN}${getDocsSearchHref(entry.slug)}`,
  }))
  const summary = results.length
    ? [
        `Appwrite docs pages for "${text}":`,
        ...results.map(
          (result, index) =>
            `${index + 1}. ${result.title}: ${result.url}\n   ${result.description}`,
        ),
      ].join('\n')
    : `No Appwrite docs pages match "${text}". Try different keywords, or browse ${ORIGIN}/docs.`

  if (version === '1.0') {
    return {
      messageId: crypto.randomUUID(),
      contextId,
      role: 'ROLE_AGENT',
      parts: [
        { text: summary, mediaType: 'text/plain' },
        { data: { results }, mediaType: 'application/json' },
      ],
    }
  }
  return {
    kind: 'message',
    messageId: crypto.randomUUID(),
    contextId,
    role: 'agent',
    parts: [
      { kind: 'text', text: summary },
      { kind: 'data', data: { results } },
    ],
  }
}

/** Answers A2A JSON-RPC requests (v1.0, plus 0.3 for clients without an A2A-Version header). */
export async function answerA2a(request: Request): Promise<Response> {
  let body: RpcRequest
  try {
    body = (await request.json()) as RpcRequest
  } catch {
    return failure(null, { code: -32700, message: 'Invalid JSON payload' })
  }
  if (body?.jsonrpc !== '2.0' || typeof body.method !== 'string') {
    return failure(body?.id, {
      code: -32600,
      message: 'Request payload validation error',
    })
  }

  const version = readVersion(request)
  if (!version) {
    return failure(body.id, {
      code: -32009,
      message: 'Supported A2A versions are 1.0 and 0.3',
    })
  }

  const method = body.method
  const methods = SEND_METHODS[version]
  if (method === 'ListTasks' && version === '1.0') {
    return json({
      jsonrpc: '2.0',
      id: body.id,
      result: { tasks: [], nextPageToken: '', pageSize: 0, totalSize: 0 },
    })
  }
  const unsupported = NO_TASK_METHODS[version][method]
  if (unsupported) return failure(body.id, unsupported)
  if (method !== methods.send && method !== methods.stream) {
    return failure(body.id, { code: -32601, message: 'Method not found' })
  }

  const text = readText(body.params)
  if (typeof text !== 'string') return failure(body.id, text)

  const contextId =
    typeof body.params?.message?.contextId === 'string'
      ? body.params.message.contextId
      : crypto.randomUUID()
  const reply = buildReply(text, contextId, version)
  const result = version === '1.0' ? { message: reply } : reply

  if (method === methods.stream) {
    return new Response(
      `data: ${JSON.stringify({ jsonrpc: '2.0', id: body.id, result })}\n\n`,
      {
        headers: {
          ...HEADERS,
          'Content-Type': 'text/event-stream; charset=utf-8',
        },
      },
    )
  }
  return json({ jsonrpc: '2.0', id: body.id, result })
}

export function preflightA2a(): Response {
  return new Response(null, {
    status: 204,
    headers: {
      ...HEADERS,
      'Access-Control-Allow-Methods': 'POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Accept, A2A-Version',
    },
  })
}
