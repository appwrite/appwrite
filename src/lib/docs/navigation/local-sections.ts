import type { DocsSectionNavConfig } from './sections'

/**
 * Docs section navigation maintained in vibes (not imported from the website repo).
 * Survives `bun run import:docs` without being overwritten.
 */
export const DOCS_LOCAL_SECTION_NAVS: DocsSectionNavConfig[] = [
  {
    prefix: 'partners/oauth-connect',
    parent: {
      href: '/docs/partners',
      label: 'OAuth connect',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/partners/oauth-connect',
          },
          {
            label: 'Setup',
            href: '/docs/partners/oauth-connect/setup',
          },
          {
            label: 'Scopes',
            href: '/docs/partners/oauth-connect/scopes',
          },
        ],
      },
    ],
  },
  {
    prefix: 'partners/org-api-keys',
    parent: {
      href: '/docs/partners',
      label: 'Org API keys',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/partners/org-api-keys',
          },
          {
            label: 'Scopes',
            href: '/docs/partners/org-api-keys/scopes',
          },
        ],
      },
    ],
  },
  {
    prefix: 'partners/organizations',
    parent: {
      href: '/docs/partners',
      label: 'Organization',
    },
    navigation: [
      {
        label: 'Partners API',
        items: [
          {
            label: 'Overview',
            href: '/docs/partners/organizations',
          },
          {
            label: 'Manage organizations',
            href: '/docs/partners/organizations/manage',
          },
          {
            label: 'Members and roles',
            href: '/docs/partners/organizations/members',
          },
        ],
      },
    ],
  },
  {
    prefix: 'partners/projects',
    parent: {
      href: '/docs/partners',
      label: 'Project',
    },
    navigation: [
      {
        label: 'Partners API',
        items: [
          {
            label: 'Overview',
            href: '/docs/partners/projects',
          },
          {
            label: 'Create projects',
            href: '/docs/partners/projects/create',
          },
          {
            label: 'Manage resources',
            href: '/docs/partners/projects/resources',
          },
        ],
      },
    ],
  },
  {
    prefix: 'products/domains',
    parent: {
      href: '/docs',
      label: 'Domains',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/domains',
          },
          {
            label: 'Quick start',
            href: '/docs/products/domains/quick-start',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Registration',
            href: '/docs/products/domains/registration',
          },
          {
            label: 'Renewal',
            href: '/docs/products/domains/renewal',
          },
          {
            label: 'DNS records',
            href: '/docs/products/domains/dns',
          },
          {
            label: 'DNS presets',
            href: '/docs/products/domains/presets',
          },
          {
            label: 'Pricing',
            href: '/docs/products/domains/pricing',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'Register a domain',
            href: '/docs/products/domains/register',
          },
          {
            label: 'Transfer a domain',
            href: '/docs/products/domains/transfer',
          },
          {
            label: 'Add external domain',
            href: '/docs/products/domains/external',
          },
          {
            label: 'Manage DNS records',
            href: '/docs/products/domains/manage-dns',
          },
          {
            label: 'Connect to products',
            href: '/docs/products/domains/connect',
          },
          {
            label: 'Change organization',
            href: '/docs/products/domains/change-organization',
          },
          {
            label: 'Delete a domain',
            href: '/docs/products/domains/delete',
          },
        ],
      },
    ],
  },
  {
    prefix: 'products/network',
    parent: {
      href: '/docs',
      label: 'Network',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/network',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Regions',
            href: '/docs/products/network/regions',
          },
          {
            label: 'Edges',
            href: '/docs/products/network/edges',
          },
          {
            label: 'CDN',
            href: '/docs/products/network/cdn',
          },
          {
            label: 'Endpoints',
            href: '/docs/products/network/endpoints',
          },
        ],
      },
      {
        label: 'Features',
        items: [
          {
            label: 'Custom domains',
            href: '/docs/products/network/custom-domains',
          },
          {
            label: 'DNS',
            href: '/docs/products/network/dns',
          },
          {
            label: 'CAA records',
            href: '/docs/products/network/caa-records',
          },
          {
            label: 'DDoS mitigation',
            href: '/docs/products/network/ddos',
          },
          {
            label: 'TLS',
            href: '/docs/products/network/tls',
          },
          {
            label: 'Firewall',
            href: '/docs/products/firewall',
          },
          {
            label: 'Compression',
            href: '/docs/products/network/compression',
          },
          {
            label: 'Caching',
            href: '/docs/products/network/caching',
          },
        ],
      },
    ],
  },
  {
    prefix: 'products/firewall',
    parent: {
      href: '/docs',
      label: 'Firewall',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/firewall',
          },
          {
            label: 'Quick start',
            href: '/docs/products/firewall/quick-start',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Rules',
            href: '/docs/products/firewall/rules',
          },
          {
            label: 'Actions',
            href: '/docs/products/firewall/actions',
          },
          {
            label: 'Conditions',
            href: '/docs/products/firewall/conditions',
          },
          {
            label: 'Resource scopes',
            href: '/docs/products/firewall/scopes',
          },
          {
            label: 'Priority',
            href: '/docs/products/firewall/priority',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'Create a rule',
            href: '/docs/products/firewall/create',
          },
          {
            label: 'Update a rule',
            href: '/docs/products/firewall/update',
          },
          {
            label: 'Monitor traffic',
            href: '/docs/products/firewall/monitor',
          },
          {
            label: 'Delete a rule',
            href: '/docs/products/firewall/delete',
          },
        ],
      },
    ],
  },
  {
    prefix: 'products/agent',
    parent: {
      href: '/docs',
      label: 'Agent',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/agent',
          },
          {
            label: 'Quick start',
            href: '/docs/products/agent/quick-start',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Conversations',
            href: '/docs/products/agent/conversations',
          },
          {
            label: 'Actions',
            href: '/docs/products/agent/actions',
          },
          {
            label: 'MCP connections',
            href: '/docs/products/agent/mcp',
          },
          {
            label: 'Models',
            href: '/docs/products/agent/models',
          },
          {
            label: 'Memory',
            href: '/docs/products/agent/memory',
          },
          {
            label: 'Automations',
            href: '/docs/products/agent/automations',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'Chat with the Agent',
            href: '/docs/products/agent/chat',
          },
          {
            label: 'Connect Appwrite MCP',
            href: '/docs/products/agent/connect-mcp',
          },
          {
            label: 'Add a custom model',
            href: '/docs/products/agent/add-model',
          },
          {
            label: 'Add memory',
            href: '/docs/products/agent/add-memory',
          },
          {
            label: 'Create an automation',
            href: '/docs/products/agent/create-automation',
          },
        ],
      },
    ],
  },
  {
    prefix: 'tooling/ai',
    parent: {
      href: '/docs',
      label: 'AI',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/tooling/ai',
          },
          {
            label: 'Quick start prompts',
            href: '/docs/tooling/ai/quickstart-prompts',
          },
        ],
      },
      {
        label: 'Tooling',
        items: [
          {
            label: 'Appwrite Agent',
            href: '/docs/products/agent',
          },
          {
            label: 'MCP servers',
            href: '/docs/tooling/ai/mcp-servers',
          },
          {
            label: 'Agent skills',
            href: '/docs/tooling/ai/skills',
          },
          {
            label: 'AGENTS.md',
            href: '/docs/tooling/ai/agents-md',
          },
          {
            label: 'Appwrite Arena',
            href: '/docs/tooling/ai/arena',
          },
        ],
      },
      {
        label: 'IDEs',
        items: [
          {
            label: 'Claude Code',
            href: '/docs/tooling/ai/agents/claude-code',
          },
          {
            label: 'Codex',
            href: '/docs/tooling/ai/agents/codex',
          },
          {
            label: 'Cursor',
            href: '/docs/tooling/ai/agents/cursor',
          },
          {
            label: 'VS Code',
            href: '/docs/tooling/ai/agents/vscode',
          },
          {
            label: 'Zed',
            href: '/docs/tooling/ai/agents/zed',
          },
          {
            label: 'OpenCode',
            href: '/docs/tooling/ai/agents/opencode',
          },
          {
            label: 'Google Antigravity',
            href: '/docs/tooling/ai/agents/antigravity',
          },
        ],
      },
      {
        label: 'Vibe coding',
        items: [
          {
            label: 'Claude Desktop',
            href: '/docs/tooling/ai/vibe-coding/claude-desktop',
          },
          {
            label: 'Lovable',
            href: '/docs/tooling/ai/vibe-coding/lovable',
          },
          {
            label: 'Emergent',
            href: '/docs/tooling/ai/vibe-coding/emergent',
          },
          {
            label: 'Bolt',
            href: '/docs/tooling/ai/vibe-coding/bolt',
          },
          {
            label: 'Zenflow',
            href: '/docs/tooling/ai/vibe-coding/zenflow',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'AI in Functions',
            href: '/docs/tooling/ai/ai-in-functions',
          },
          {
            label: 'Vector DB and embeddings',
            href: '/docs/tooling/ai/vector-db-and-embeddings',
          },
          {
            label: 'Persistent agents with Realtime',
            href: '/docs/tooling/ai/persistent-agents-with-realtime',
          },
        ],
      },
    ],
  },
]
