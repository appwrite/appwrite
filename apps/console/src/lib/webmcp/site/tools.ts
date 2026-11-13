import type { ProductId } from '@/lib/products/types'
import { registerTools } from '@/lib/webmcp/register'
import {
  getDocPage,
  getPricing,
  getProductOverview,
  listChangelog,
  navigateToPage,
  searchCommunityThreads,
  searchDocs,
  type Navigate,
} from '@/lib/webmcp/site/actions'

/**
 * appwrite.io's WebMCP tools, as an agent sees them. Keep every definition an
 * object literal passed straight to registerTool(): agent-readiness scanners
 * read them from the shipped bundle. Behaviour lives in ./actions.
 */
export function registerSiteTools(navigate: Navigate): () => void {
  return registerTools((modelContext, options) => [
    modelContext.registerTool(
      {
        name: 'search_docs',
        title: 'Search Appwrite docs',
        description:
          'Search the Appwrite documentation and return the best-matching pages with their titles, summaries, and URLs.',
        inputSchema: {
          type: 'object',
          properties: {
            query: {
              type: 'string',
              minLength: 2,
              maxLength: 200,
              description:
                'What to look for, such as "create a row" or "OAuth with Google".',
            },
            limit: {
              type: 'integer',
              minimum: 1,
              maximum: 10,
              description: 'Maximum number of pages to return. Defaults to 5.',
            },
          },
          required: ['query'],
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true },
        execute: (input) => searchDocs(input),
      },
      options,
    ),

    modelContext.registerTool(
      {
        name: 'get_doc_page',
        title: 'Read an Appwrite docs page',
        description:
          'Return the Markdown source of one Appwrite documentation page, given its path from search_docs.',
        inputSchema: {
          type: 'object',
          properties: {
            path: {
              type: 'string',
              pattern: '^/docs(/[A-Za-z0-9._-]+)*/?$',
              description:
                'Docs page path, such as /docs/products/auth/email-password.',
            },
          },
          required: ['path'],
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true },
        execute: (input, { signal }) => getDocPage(input, signal),
      },
      options,
    ),

    modelContext.registerTool(
      {
        name: 'get_pricing',
        title: 'Get Appwrite Cloud pricing',
        description:
          'Return Appwrite Cloud plans, prices, included usage, and add-on rates as Markdown.',
        inputSchema: {
          type: 'object',
          properties: {},
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true },
        execute: (_input, { signal }) => getPricing(signal),
      },
      options,
    ),

    modelContext.registerTool(
      {
        name: 'get_product_overview',
        title: 'Get an Appwrite product overview',
        description:
          'Return what one Appwrite product does, its main features, and its docs link, as Markdown.',
        inputSchema: {
          type: 'object',
          properties: {
            product: {
              type: 'string',
              enum: [
                'auth',
                'databases',
                'postgres',
                'storage',
                'functions',
                'messaging',
                'realtime',
                'sites',
                'analytics',
                'firewall',
              ] satisfies ProductId[],
              description: 'Product to describe.',
            },
          },
          required: ['product'],
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true },
        execute: (input, { signal }) => getProductOverview(input, signal),
      },
      options,
    ),

    modelContext.registerTool(
      {
        name: 'list_changelog',
        title: 'List recent Appwrite changes',
        description:
          'Return the newest Appwrite changelog entries, newest first, with links to each entry.',
        inputSchema: {
          type: 'object',
          properties: {
            limit: {
              type: 'integer',
              minimum: 1,
              maximum: 20,
              description: 'Number of entries to return. Defaults to 5.',
            },
          },
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true },
        execute: (input, { signal }) => listChangelog(input, signal),
      },
      options,
    ),

    modelContext.registerTool(
      {
        name: 'search_community_threads',
        title: 'Search Appwrite community threads',
        description:
          'Search Appwrite Discord support threads written by community members and return matching threads with summaries and links.',
        inputSchema: {
          type: 'object',
          properties: {
            query: {
              type: 'string',
              minLength: 2,
              maxLength: 200,
              description:
                'Problem or topic to look for, such as "CORS error on login".',
            },
            limit: {
              type: 'integer',
              minimum: 1,
              maximum: 10,
              description:
                'Maximum number of threads to return. Defaults to 5.',
            },
          },
          required: ['query'],
          additionalProperties: false,
        },
        annotations: { readOnlyHint: true, untrustedContentHint: true },
        execute: (input) => searchCommunityThreads(input),
      },
      options,
    ),

    modelContext.registerTool(
      {
        name: 'navigate_to_page',
        title: 'Open a page on appwrite.io',
        description:
          'Open a page on this site in the current tab, such as a docs page, product page, or /pricing.',
        inputSchema: {
          type: 'object',
          properties: {
            path: {
              type: 'string',
              pattern: '^/(?!/)',
              description:
                'Site path to open, such as /docs/quick-starts/react or /pricing.',
            },
          },
          required: ['path'],
          additionalProperties: false,
        },
        execute: (input) => navigateToPage(input, navigate),
      },
      options,
    ),

    modelContext.registerTool(
      {
        name: 'open_sign_up',
        title: 'Open Appwrite Cloud sign-up',
        description:
          'Open the Appwrite Cloud sign-up page in the current tab so the person can create a free account.',
        inputSchema: {
          type: 'object',
          properties: {},
          additionalProperties: false,
        },
        execute: () => navigateToPage({ path: '/sign-up' }, navigate),
      },
      options,
    ),
  ])
}
