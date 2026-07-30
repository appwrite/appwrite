import { createMiddleware } from '@tanstack/react-start'
import {
  AI_CATALOG_CONTENT_TYPE,
  APPWRITE_AI_CATALOG_PATH,
  APPWRITE_AGENT_SKILLS_DISCOVERY_PATH,
  APPWRITE_MCP_SERVER_CARD_PATH,
  MCP_SERVER_CARD_CONTENT_TYPE,
  buildAgentSkillsDiscoveryDocument,
  buildAiCatalogDocument,
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
  [APPWRITE_AI_CATALOG_PATH]: {
    contentType: AI_CATALOG_CONTENT_TYPE,
    body: () => serializeDiscoveryJson(buildAiCatalogDocument()),
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
  throw discoveryJsonResponse(handler.body(), handler.contentType)
})
