import type { LucideIcon } from 'lucide-react'
import {
  ArrowLeftRight,
  Boxes,
  Building2,
  Key,
  LayoutGrid,
} from 'lucide-react'

export type DocsPartnersHomeCard = {
  title: string
  description: string
  href: string
  icon?: LucideIcon
  customIcon?: 'oauth'
  new?: boolean
}

export type DocsPartnersHomeAudience = {
  title: string
  description: string
}

export const DOCS_PARTNERS_HOME_HERO = {
  title: 'Integrate Appwrite into your platform',
  description:
    'Use OAuth connect and Partners keys to provision and configure Appwrite projects for your users from your own backend.',
} as const

export const DOCS_PARTNERS_HOME_AUDIENCES: DocsPartnersHomeAudience[] = [
  {
    title: 'Vibe coding and agentic platforms',
    description:
      'Users describe an app in natural language while your product provisions Appwrite projects, auth, and databases on their behalf.',
  },
  {
    title: 'AI agents and MCP tools',
    description:
      'Agents, skills, and IDE integrations that connect to a user Appwrite account and manage backends during autonomous workflows.',
  },
  {
    title: 'Multi-tenant SaaS control planes',
    description:
      'Products that isolate each customer in a dedicated Appwrite project or organization from one operator account.',
  },
  {
    title: 'Embedded and white-label backends',
    description:
      'Developer platforms that expose your own product UX while Appwrite powers provisioning, domains, and project lifecycle behind the scenes.',
  },
]

export const DOCS_PARTNERS_HOME_INTEGRATIONS: DocsPartnersHomeCard[] = [
  {
    title: 'OAuth connect',
    description:
      'Let users authorize your platform to access their Appwrite organizations and projects.',
    href: '/docs/partners/oauth-connect',
    customIcon: 'oauth',
    new: true,
  },
  {
    title: 'Partners keys',
    description:
      'Use organization-scoped API keys to proxy Appwrite and manage resources on behalf of your users.',
    href: '/docs/partners/org-api-keys',
    icon: Key,
    new: true,
  },
]

export const DOCS_PARTNERS_HOME_APIS: DocsPartnersHomeCard[] = [
  {
    title: 'Organization',
    description:
      'Provision projects for your customers, manage members, and read the organization.',
    href: '/docs/partners/organizations',
    icon: Building2,
  },
  {
    title: 'Project',
    description:
      'Configure auth methods, platforms, API keys, SMTP, and policies for each project.',
    href: '/docs/partners/project',
    icon: Boxes,
  },
  {
    title: 'Proxy',
    description:
      'Point custom domains at a project API, function, or site, and verify them.',
    href: '/docs/partners/proxy',
    icon: ArrowLeftRight,
  },
  {
    title: 'Apps',
    description:
      'Register OAuth apps, manage client credentials, and start authorization flows.',
    href: '/docs/partners/apps',
    icon: LayoutGrid,
  },
]
