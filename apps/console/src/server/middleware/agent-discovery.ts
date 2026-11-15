import { createMiddleware } from '@tanstack/react-start'
import { A2A_AGENT_CARD_PATH, buildAgentCard } from '@/lib/a2a/agent'
import {
  APPWRITE_AI_CATALOG_PATH,
  APPWRITE_ARD_PATH,
  ARD_CONTENT_TYPE,
  APPWRITE_AGENT_SKILLS_DISCOVERY_PATH,
  APPWRITE_API_CATALOG_PATH,
  API_CATALOG_CONTENT_TYPE,
  DISCOVERY_CORS_HEADERS,
  APPWRITE_MCP_DOCS_SERVER_CARD_PATH,
  APPWRITE_MCP_SERVER_CARD_PATH,
  MCP_SERVER_CARD_CONTENT_TYPE,
  buildAgentSkillsDiscoveryDocument,
  buildAiCatalogDocument,
  buildArdDocument,
  buildApiCatalogDocument,
  buildMcpDocsServerCard,
  buildMcpServerCard,
  discoveryJsonResponse,
  serializeDiscoveryJson,
} from '@/lib/seo/agent-discovery'
import { trackServerPageview } from '@/lib/server-analytics'

const DISCOVERY_HANDLERS: Record<
  string,
  { contentType: string; body: () => string }
> = {
  [APPWRITE_MCP_SERVER_CARD_PATH]: {
    contentType: MCP_SERVER_CARD_CONTENT_TYPE,
    body: () => serializeDiscoveryJson(buildMcpServerCard()),
  },
  [APPWRITE_MCP_DOCS_SERVER_CARD_PATH]: {
    contentType: MCP_SERVER_CARD_CONTENT_TYPE,
    body: () => serializeDiscoveryJson(buildMcpDocsServerCard()),
  },
  [APPWRITE_ARD_PATH]: {
    contentType: ARD_CONTENT_TYPE,
    body: () => serializeDiscoveryJson(buildArdDocument()),
  },
  [APPWRITE_AI_CATALOG_PATH]: {
    contentType: ARD_CONTENT_TYPE,
    body: () => serializeDiscoveryJson(buildAiCatalogDocument()),
  },
  [A2A_AGENT_CARD_PATH]: {
    contentType: 'application/json; charset=utf-8',
    body: () => serializeDiscoveryJson(buildAgentCard()),
  },
  [APPWRITE_API_CATALOG_PATH]: {
    contentType: API_CATALOG_CONTENT_TYPE,
    body: () => serializeDiscoveryJson(buildApiCatalogDocument()),
  },
  [APPWRITE_AGENT_SKILLS_DISCOVERY_PATH]: {
    contentType: 'application/json; charset=utf-8',
    body: () => serializeDiscoveryJson(buildAgentSkillsDiscoveryDocument()),
  },
}

/**
 * Serve MCP Server Card / AI Catalog / agent-skills discovery with CORS and
 * server-side pageviews (SEP-1649 / SEP-2127).
 */
export const agentDiscoveryMiddleware = createMiddleware({
  type: 'request',
}).server(async ({ request, pathname, next }) => {
  if (request.method === 'OPTIONS' && DISCOVERY_HANDLERS[pathname]) {
    throw new Response(null, {
      status: 204,
      headers: {
        ...DISCOVERY_CORS_HEADERS,
        'Access-Control-Max-Age': '86400',
      },
    })
  }

  const handler = DISCOVERY_HANDLERS[pathname]
  if (!handler) {
    return next()
  }

  trackServerPageview(request, { format: 'json' })
  const response = discoveryJsonResponse(handler.body(), handler.contentType)
  if (pathname === APPWRITE_API_CATALOG_PATH) {
    // RFC 9727 §2: GET and HEAD carry the api-catalog relation.
    response.headers.set(
      'Link',
      `<${APPWRITE_API_CATALOG_PATH}>; rel="api-catalog"`,
    )
  }
  throw response
})
