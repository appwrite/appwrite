import type { ProductId } from '@/lib/products/types'
import type { ToolHandler } from '@/lib/webmcp/calls'

export type SiteToolsBridge = {
  /** Registers the tools; does nothing while they are registered. */
  register(): void
  unregister(): void
  /** Answers calls with these handlers until the returned cleanup runs. Calls made before a connect wait for it. */
  connect(handlers: Record<string, ToolHandler>): () => void
}

declare global {
  interface Window {
    __appwriteSiteTools?: SiteToolsBridge
  }
  interface Navigator {
    /** Pre-spec Chrome builds expose WebMCP here instead of on `document`. */
    readonly modelContext?: WebMCP.ModelContext
  }
}

/**
 * appwrite.io's WebMCP tools, as an agent sees them. SITE_TOOLS_SCRIPT runs
 * this inline in the page, so the tools register while the HTML parses and
 * scanners read the literal definitions; it is serialized with toString(), so
 * it must not use anything defined outside itself. Each tool forwards to the
 * handlers useSiteTools connects from ./actions once the app loads.
 */
export function installSiteTools(): void {
  const modelContext = document.modelContext ?? navigator.modelContext
  if (!modelContext || window.__appwriteSiteTools) return

  let handlers: Record<string, ToolHandler> | null = null
  const waiting: Array<() => void> = []
  let controller: AbortController | null = null
  const ignore = () => {}
  const call = async (
    name: string,
    input: Record<string, unknown>,
    options: WebMCP.ToolExecuteCallbackOptions,
  ) => {
    while (!handlers)
      await new Promise<void>((resolve) => waiting.push(resolve))
    return handlers[name](input, options)
  }

  const register = () => {
    if (controller) return
    controller = new AbortController()
    const options = { signal: controller.signal }
    modelContext
      .registerTool(
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
                description:
                  'Maximum number of pages to return. Defaults to 5.',
              },
            },
            required: ['query'],
            additionalProperties: false,
          },
          annotations: { readOnlyHint: true },
          execute: (input, options) => call('search_docs', input, options),
        },
        options,
      )
      .catch(ignore)

    modelContext
      .registerTool(
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
          execute: (input, options) => call('get_doc_page', input, options),
        },
        options,
      )
      .catch(ignore)

    modelContext
      .registerTool(
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
          execute: (input, options) => call('get_pricing', input, options),
        },
        options,
      )
      .catch(ignore)

    modelContext
      .registerTool(
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
          execute: (input, options) =>
            call('get_product_overview', input, options),
        },
        options,
      )
      .catch(ignore)

    modelContext
      .registerTool(
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
          execute: (input, options) => call('list_changelog', input, options),
        },
        options,
      )
      .catch(ignore)

    modelContext
      .registerTool(
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
          execute: (input, options) =>
            call('search_community_threads', input, options),
        },
        options,
      )
      .catch(ignore)

    modelContext
      .registerTool(
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
          execute: (input, options) => call('navigate_to_page', input, options),
        },
        options,
      )
      .catch(ignore)

    modelContext
      .registerTool(
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
          execute: (input, options) => call('open_sign_up', input, options),
        },
        options,
      )
      .catch(ignore)
  }

  window.__appwriteSiteTools = {
    register,
    unregister: () => {
      controller?.abort()
      controller = null
    },
    connect: (next) => {
      handlers = next
      for (const resolve of waiting.splice(0)) resolve()
      return () => {
        if (handlers === next) handlers = null
      }
    },
  }
  register()
}

/** Inline script for public-site pages; see installSiteTools. */
export const SITE_TOOLS_SCRIPT = `(${installSiteTools.toString()})()`
