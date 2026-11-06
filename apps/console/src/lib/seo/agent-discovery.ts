/**
 * Machine-readable discovery documents for MCP Server Cards (SEP-1649 / SEP-2127)
 * and agent skills.
 *
 * @see https://github.com/modelcontextprotocol/modelcontextprotocol/issues/1649
 * @see https://github.com/modelcontextprotocol/experimental-ext-server-card
 */

import { MCP_SERVER_NAME, MCP_SERVER_URL } from '../config/mcp.ts'

/** SEP-1649 well-known Server Card location. */
export const APPWRITE_MCP_SERVER_CARD_PATH =
  '/.well-known/mcp/server-card.json'

/**
 * Domain-level AI Catalog (current discovery flow). Points clients at the
 * Server Card URL rather than inlining the card.
 */
export const APPWRITE_AI_CATALOG_PATH = '/.well-known/ai-catalog.json'

/** @deprecated Prefer {@link APPWRITE_MCP_SERVER_CARD_PATH}. */
export const APPWRITE_MCP_DISCOVERY_PATH = APPWRITE_MCP_SERVER_CARD_PATH

export const APPWRITE_AGENT_SKILLS_DISCOVERY_PATH =
  '/.well-known/agent-skills/index.json'
export const APPWRITE_MCP_DOCS_PATH = '/docs/tooling/ai/mcp-servers'

/** Canonical skills repository. */
export const APPWRITE_AGENT_SKILLS_REPO = 'https://github.com/appwrite/skills'
export const APPWRITE_AGENT_SKILLS_INSTALL = 'npx skills add appwrite/skills'

const SKILLS_RAW_BASE =
  'https://raw.githubusercontent.com/appwrite/skills/main/skills'

/** Published Server Card schema (SEP-2127 / experimental-ext-server-card). */
export const MCP_SERVER_CARD_SCHEMA =
  'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json'

/** Reverse-DNS server name (exactly one `/`). */
export const APPWRITE_MCP_SERVER_CARD_NAME = 'io.appwrite/mcp'

export const MCP_SERVER_CARD_CONTENT_TYPE =
  'application/mcp-server-card+json; charset=utf-8'
export const AI_CATALOG_CONTENT_TYPE =
  'application/ai-catalog+json; charset=utf-8'

/** CORS headers required by MCP Server Card / AI Catalog discovery. */
export const DISCOVERY_CORS_HEADERS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET',
  'Access-Control-Allow-Headers': 'Content-Type, If-None-Match, Accept',
  'Access-Control-Expose-Headers': 'ETag',
} as const

export type AgentSkillDiscoveryEntry = {
  name: string
  type: 'skill-md'
  description: string
  url: string
}

/**
 * Appwrite agent skills published in appwrite/skills.
 * Descriptions match SKILL.md frontmatter so discovery stays useful offline.
 */
export const APPWRITE_AGENT_SKILLS: AgentSkillDiscoveryEntry[] = [
  {
    name: 'appwrite-cli',
    type: 'skill-md',
    description:
      'Appwrite CLI skill. Use when managing Appwrite projects from the command line. Covers installation, login, project initialization, multi-file project configuration, deploying functions/sites/tables/buckets/teams/webhooks/topics, flag-based list queries, non-interactive CI/CD mode, and generating type-safe SDKs.',
    url: `${SKILLS_RAW_BASE}/appwrite-cli/SKILL.md`,
  },
  {
    name: 'appwrite-dart',
    type: 'skill-md',
    description:
      'Appwrite Dart SDK skill. Use when building Flutter apps (mobile, web, desktop) or server-side Dart applications with Appwrite. Covers client-side auth (email, OAuth), database queries, file uploads with native file handling, real-time subscriptions, and server-side admin via API keys for user management, database administration, storage, and functions.',
    url: `${SKILLS_RAW_BASE}/appwrite-dart/SKILL.md`,
  },
  {
    name: 'appwrite-dotnet',
    type: 'skill-md',
    description:
      'Appwrite .NET SDK skill. Use when building server-side C# or .NET applications with Appwrite, including ASP.NET and Blazor integrations. Covers user management, database/table CRUD, file storage, and functions via API keys.',
    url: `${SKILLS_RAW_BASE}/appwrite-dotnet/SKILL.md`,
  },
  {
    name: 'appwrite-go',
    type: 'skill-md',
    description:
      'Appwrite Go SDK skill. Use when building server-side Go applications with Appwrite. Covers user management, database/table CRUD, file storage, and functions via API keys. Uses per-service packages and functional options pattern.',
    url: `${SKILLS_RAW_BASE}/appwrite-go/SKILL.md`,
  },
  {
    name: 'appwrite-kotlin',
    type: 'skill-md',
    description:
      'Appwrite Kotlin SDK skill. Use when building native Android apps or server-side Kotlin/JVM backends with Appwrite. Covers client-side auth (email, OAuth with Activity integration), database queries, file uploads, real-time subscriptions with coroutine support, and server-side admin via API keys for user management, database administration, storage, and functions.',
    url: `${SKILLS_RAW_BASE}/appwrite-kotlin/SKILL.md`,
  },
  {
    name: 'appwrite-php',
    type: 'skill-md',
    description:
      'Appwrite PHP SDK skill. Use when building server-side PHP applications with Appwrite, including Laravel and Symfony integrations. Covers user management, database/table CRUD, file storage, and functions via API keys.',
    url: `${SKILLS_RAW_BASE}/appwrite-php/SKILL.md`,
  },
  {
    name: 'appwrite-python',
    type: 'skill-md',
    description:
      'Appwrite Python SDK skill. Use when building server-side Python applications with Appwrite, including Django, Flask, and FastAPI integrations. Covers user management, database/table CRUD, file storage, and functions via API keys.',
    url: `${SKILLS_RAW_BASE}/appwrite-python/SKILL.md`,
  },
  {
    name: 'appwrite-ruby',
    type: 'skill-md',
    description:
      'Appwrite Ruby SDK skill. Use when building server-side Ruby applications with Appwrite, including Rails and Sinatra integrations. Covers user management, database/table CRUD, file storage, and functions via API keys.',
    url: `${SKILLS_RAW_BASE}/appwrite-ruby/SKILL.md`,
  },
  {
    name: 'appwrite-rust',
    type: 'skill-md',
    description:
      'Appwrite Rust SDK skill. Use when building server-side Rust applications with Appwrite. Covers async client setup with API keys, user management, TablesDB database/table/row operations, file storage, function executions, permissions, queries, and error handling. Uses the crates.io `appwrite` package and Tokio.',
    url: `${SKILLS_RAW_BASE}/appwrite-rust/SKILL.md`,
  },
  {
    name: 'appwrite-swift',
    type: 'skill-md',
    description:
      'Appwrite Swift SDK skill. Use when building native iOS, macOS, watchOS, or tvOS apps, or server-side Swift applications with Appwrite. Covers client-side auth (email, OAuth), database queries, file uploads, real-time subscriptions with async/await, and server-side admin via API keys for user management, database administration, storage, and functions.',
    url: `${SKILLS_RAW_BASE}/appwrite-swift/SKILL.md`,
  },
  {
    name: 'appwrite-typescript',
    type: 'skill-md',
    description:
      'Appwrite TypeScript SDK skill. Use when building browser-based JavaScript/TypeScript apps, React Native mobile apps, or server-side Node.js/Deno backends with Appwrite. Covers client-side auth (email, OAuth, anonymous), database queries, file uploads, real-time subscriptions, and server-side admin via API keys for user management, database administration, storage, and functions.',
    url: `${SKILLS_RAW_BASE}/appwrite-typescript/SKILL.md`,
  },
]

/**
 * MCP Server Card for the hosted Appwrite MCP server.
 * Schema: https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json
 * (description maxLength: 100)
 */
export function buildMcpServerCard(origin: string = 'https://appwrite.io') {
  return {
    $schema: MCP_SERVER_CARD_SCHEMA,
    name: APPWRITE_MCP_SERVER_CARD_NAME,
    title: 'Appwrite',
    description:
      'Backend API for auth, databases, storage, functions, messaging, and hosting.',
    version: '1.0.0',
    websiteUrl: `${origin}${APPWRITE_MCP_DOCS_PATH}`,
    repository: {
      url: 'https://github.com/appwrite/mcp',
      source: 'github',
    },
    remotes: [
      {
        type: 'streamable-http' as const,
        url: MCP_SERVER_URL,
        headers: [
          {
            name: 'Authorization',
            description:
              'OAuth 2.0 access token or Appwrite API key as a Bearer token.',
            isRequired: true,
            isSecret: true,
            value: 'Bearer {token}',
            variables: {
              token: {
                description:
                  'OAuth access token from the Appwrite authorization server, or a project API key.',
                isRequired: true,
                isSecret: true,
              },
            },
          },
        ],
      },
    ],
    _meta: {
      'io.appwrite/mcp': {
        serverName: MCP_SERVER_NAME,
        documentationMarkdown: `${origin}${APPWRITE_MCP_DOCS_PATH}.md`,
      },
    },
  }
}

/** @deprecated Use {@link buildMcpServerCard}. */
export function buildMcpDiscoveryDocument(origin: string = 'https://appwrite.io') {
  return buildMcpServerCard(origin)
}

/** Domain AI Catalog advertising the Appwrite MCP Server Card. */
export function buildAiCatalogDocument(origin: string = 'https://appwrite.io') {
  return {
    specVersion: '1.0',
    entries: [
      {
        identifier: 'urn:air:appwrite.io:mcp:appwrite',
        type: 'application/mcp-server-card+json',
        url: `${origin}${APPWRITE_MCP_SERVER_CARD_PATH}`,
      },
    ],
  }
}

export function buildAgentSkillsDiscoveryDocument() {
  return {
    $schema: 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
    skills: APPWRITE_AGENT_SKILLS,
  }
}

export function serializeDiscoveryJson(value: unknown): string {
  return `${JSON.stringify(value, null, 2)}\n`
}

export function discoveryJsonResponse(
  body: string,
  contentType: string,
): Response {
  return new Response(body, {
    headers: {
      'Content-Type': contentType,
      'Cache-Control': 'public, max-age=3600',
      ...DISCOVERY_CORS_HEADERS,
    },
  })
}
