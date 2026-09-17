import type { DocsNavTree } from '../types'
import type { DocsSectionNavConfig } from './sections'

const DOCS_TOOLING_SECTION_NAV: DocsNavTree = [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/tooling',
          },
          {
            label: 'Quick start prompts',
            href: '/docs/tooling/ai/quickstart-prompts',
          },
        ],
      },
      {
        label: 'Tools',
        items: [
          {
            label: 'MCP server',
            href: '/docs/tooling/ai/mcp-servers',
          },
          {
            label: 'CLI',
            href: '/docs/tooling/command-line/installation',
          },
          {
            label: 'Agent skills',
            href: '/docs/tooling/ai/skills',
          },
          {
            label: 'Terraform',
            href: '/docs/tooling/terraform',
          },
          {
            label: 'AGENTS.md',
            href: '/docs/tooling/ai/agents-md',
          },
          {
            label: 'Command Center',
            href: '/docs/tooling/command-center',
          },
          {
            label: 'Appwrite Arena',
            href: '/docs/tooling/ai/arena',
          },
          {
            label: 'Appwrite Agent',
            href: '/docs/products/agent',
          },
          {
            label: 'The Appwriter',
            href: '/docs/tooling/appwriter',
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
            label: 'ChatGPT',
            href: '/docs/tooling/ai/agents/chatgpt',
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
          {
            label: 'Grok Build',
            href: '/docs/tooling/ai/agents/grok-build',
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
            label: 'Backend for coding agents',
            href: '/docs/tooling/ai/backend-for-agents',
          },
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
]

/**
 * Additional docs section navigation maintained in vibes.
 * Merged with DOCS_SECTION_NAVS from ./sections.ts.
 */
export const DOCS_LOCAL_SECTION_NAVS: DocsSectionNavConfig[] = [
  {
    prefix: 'partners/guides',
    parent: {
      href: '/docs/partners',
      label: 'Guides',
    },
    navigation: [
      {
        items: [
          {
            label: "Manage a customer's project",
            href: '/docs/partners/guides/oauth-connect',
          },
        ],
      },
    ],
  },
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
    // Overrides the imported products/auth nav to add the vibes-native
    // Sign in with Appwrite guide. Keep in sync with generated sections.ts.
    prefix: 'products/auth',
    parent: {
      href: '/docs',
      label: 'Auth',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/auth',
          },
          {
            label: 'Quick start',
            href: '/docs/products/auth/quick-start',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Accounts',
            href: '/docs/products/auth/accounts',
          },
          {
            label: 'Users',
            href: '/docs/products/auth/users',
          },
          {
            label: 'Teams',
            href: '/docs/products/auth/teams',
          },
          {
            label: 'Impersonation',
            href: '/docs/products/auth/impersonation',
          },
          {
            label: 'Preferences',
            href: '/docs/products/auth/preferences',
          },
          {
            label: 'Labels',
            href: '/docs/products/auth/labels',
          },
          {
            label: 'Security',
            href: '/docs/products/auth/security',
          },
          {
            label: 'Email policies',
            href: '/docs/products/auth/email-policies',
          },
          {
            label: 'Message templates',
            href: '/docs/products/auth/message-templates',
          },
          {
            label: 'Tokens',
            href: '/docs/products/auth/tokens',
          },
          {
            label: 'Identities',
            href: '/docs/products/auth/identities',
          },
          {
            label: 'Presences',
            href: '/docs/products/auth/presences',
          },
          {
            label: 'OAuth2 server',
            href: '/docs/products/auth/oauth-server',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'Email and password login',
            href: '/docs/products/auth/email-password',
          },
          {
            label: 'Phone (SMS) login',
            href: '/docs/products/auth/phone-sms',
          },
          {
            label: 'Magic URL login',
            href: '/docs/products/auth/magic-url',
          },
          {
            label: 'Email OTP login',
            href: '/docs/products/auth/email-otp',
          },
          {
            label: 'OAuth2 login',
            href: '/docs/products/auth/oauth2',
          },
          {
            label: 'Native sign-in',
            href: '/docs/products/auth/native-sign-in',
          },
          {
            label: 'Sign in with Appwrite',
            href: '/docs/products/auth/sign-in-with-appwrite',
          },
          {
            label: 'Anonymous login',
            href: '/docs/products/auth/anonymous',
          },
          {
            label: 'JWT login',
            href: '/docs/products/auth/jwt',
          },
          {
            label: 'SSR login',
            href: '/docs/products/auth/server-side-rendering',
          },
          {
            label: 'React library',
            href: '/docs/products/auth/react',
          },
          {
            label: 'Custom token login',
            href: '/docs/products/auth/custom-token',
          },
          {
            label: 'Multi-factor authentication',
            href: '/docs/products/auth/mfa',
          },
          {
            label: 'Auth status check',
            href: '/docs/products/auth/checking-auth-status',
          },
          {
            label: 'User verification',
            href: '/docs/products/auth/verify-user',
          },
          {
            label: 'Team invites',
            href: '/docs/products/auth/team-invites',
          },
          {
            label: 'Multi-tenancy',
            href: '/docs/products/auth/multi-tenancy',
          },
        ],
      },
      {
        label: 'References',
        items: [
          {
            label: 'Account API',
            href: '/docs/references/cloud/client-web/account',
          },
          {
            label: 'Users API',
            href: '/docs/references/cloud/server-nodejs/users',
          },
          {
            label: 'Teams API',
            href: '/docs/references/cloud/client-web/teams',
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
          {
            label: 'Traffic overview',
            href: '/docs/products/firewall/monitor',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'Block traffic by country',
            href: '/docs/products/firewall/block-countries',
          },
          {
            label: 'Allowlist trusted IPs',
            href: '/docs/products/firewall/allowlist-ips',
          },
          {
            label: 'Rate limit auth traffic',
            href: '/docs/products/firewall/rate-limit-auth',
          },
          {
            label: 'Challenge automated traffic',
            href: '/docs/products/firewall/challenge-bots',
          },
          {
            label: 'Redirect for maintenance',
            href: '/docs/products/firewall/site-maintenance',
          },
          {
            label: 'Attack mode',
            href: '/docs/products/firewall/attack-mode',
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
    prefix: 'tooling',
    parent: {
      href: '/docs',
      label: 'Tooling',
    },
    navigation: DOCS_TOOLING_SECTION_NAV,
  },
  {
    prefix: 'tooling/ai',
    parent: {
      href: '/docs/tooling',
      label: 'Tooling',
    },
    navigation: DOCS_TOOLING_SECTION_NAV,
  },
]
