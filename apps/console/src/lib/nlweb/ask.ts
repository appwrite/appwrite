import { getDocsSearchHref, searchDocs } from '@/lib/docs/search'

export const NLWEB_VERSION = '0.55'

const RESPONSE_FORMAT = 'conversational_search'
const RESULT_LIMIT = 10

const HEADERS = {
  'Access-Control-Allow-Origin': '*',
  'Cache-Control': 'no-store',
}

type FailureCode = 'INVALID_QUERY' | 'NO_RESULTS' | 'UNSUPPORTED_FORMAT'

type AskRequest = {
  query?: { text?: unknown }
  prefer?: { streaming?: unknown; response_format?: unknown }
}

type Failure = { code: FailureCode; message: string }

function failure(code: FailureCode, message: string): Failure {
  return { code, message }
}

function buildResults(text: string, origin: string) {
  return searchDocs(text, RESULT_LIMIT).map((entry) => ({
    '@type': 'TechArticle',
    name: entry.title,
    description: entry.description,
    url: new URL(getDocsSearchHref(entry.slug), origin).toString(),
    articleSection: entry.breadcrumbs.join(' / '),
  }))
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { ...HEADERS, 'Content-Type': 'application/json; charset=utf-8' },
  })
}

function event(name: string, data: unknown): string {
  return `event: ${name}\ndata: ${JSON.stringify(data)}\n\n`
}

function stream(results: unknown[] | Failure): Response {
  const meta = Array.isArray(results)
    ? {
        response_type: 'answer',
        response_format: RESPONSE_FORMAT,
        version: NLWEB_VERSION,
      }
    : { response_type: 'failure', version: NLWEB_VERSION }
  const events = [event('start', { _meta: { ...meta, streaming: true } })]
  if (Array.isArray(results)) {
    results.forEach((item, index) =>
      events.push(event('result', { index, item })),
    )
  } else {
    events.push(event('error', { _meta: meta, error: results }))
  }
  events.push(event('complete', { _meta: meta }))
  return new Response(events.join(''), {
    headers: { ...HEADERS, 'Content-Type': 'text/event-stream; charset=utf-8' },
  })
}

/** Answers an NLWeb v0.55 `/ask` request from the docs search index. */
export async function answerAsk(request: Request): Promise<Response> {
  let body: AskRequest
  try {
    body = (await request.json()) as AskRequest
  } catch {
    return json(
      {
        _meta: { response_type: 'failure', version: NLWEB_VERSION },
        error: failure('INVALID_QUERY', 'The request body must be JSON.'),
      },
      400,
    )
  }

  const streaming =
    body?.prefer?.streaming === true ||
    (request.headers.get('accept') ?? '').includes('text/event-stream')
  const text =
    typeof body?.query?.text === 'string' ? body.query.text.trim() : ''
  const format = body?.prefer?.response_format

  let results: unknown[] | Failure
  if (!text) {
    results = failure('INVALID_QUERY', 'query.text is required.')
  } else if (format !== undefined && format !== RESPONSE_FORMAT) {
    results = failure(
      'UNSUPPORTED_FORMAT',
      `Only ${RESPONSE_FORMAT} is supported.`,
    )
  } else {
    const found = buildResults(text, new URL(request.url).origin)
    results = found.length
      ? found
      : failure('NO_RESULTS', 'No Appwrite docs pages match the query.')
  }

  if (streaming) return stream(results)
  if (!Array.isArray(results)) {
    return json({
      _meta: { response_type: 'failure', version: NLWEB_VERSION },
      error: results,
    })
  }
  return json({
    _meta: {
      response_type: 'answer',
      response_format: RESPONSE_FORMAT,
      version: NLWEB_VERSION,
    },
    results,
  })
}

export function preflightAsk(): Response {
  return new Response(null, {
    status: 204,
    headers: {
      ...HEADERS,
      'Access-Control-Allow-Methods': 'POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Accept',
    },
  })
}
