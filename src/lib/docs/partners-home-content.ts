import type { LucideIcon } from 'lucide-react'
import {
  ArrowLeftRight,
  BarChart2,
  Boxes,
  Building2,
  FileText,
  Globe,
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
    'Use OAuth connect, organization API keys, and Console SDK APIs to provision organizations, projects, and domains for your users.',
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
    title: 'Org API keys',
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
    description: 'Create organizations, manage members, and read billing and plan information.',
    href: '/docs/partners/organizations',
    icon: Building2,
  },
  {
    title: 'Project',
    description: 'Create projects and manage databases, storage, functions, and other resources.',
    href: '/docs/partners/projects',
    icon: Boxes,
  },
  {
    title: 'Domains',
    description: 'Register, transfer, and manage organization domains and DNS records.',
    href: '/docs/partners/domains',
    icon: Globe,
  },
  {
    title: 'Proxy',
    description: 'Wrap Console and project APIs so customers use your product while Appwrite stays the backend.',
    href: '/docs/partners/proxy',
    icon: ArrowLeftRight,
  },
  {
    title: 'Usage',
    description: 'Read organization usage, plan limits, and billing aggregation for customer dashboards.',
    href: '/docs/partners/usage',
    icon: BarChart2,
  },
  {
    title: 'Apps',
    description: 'Register OAuth apps, manage client credentials, and start authorization flows.',
    href: '/docs/partners/apps',
    icon: LayoutGrid,
  },
]

export const DOCS_PARTNERS_HOME_GUIDES: DocsPartnersHomeCard[] = [
  {
    title: 'Provisioning',
    description: 'Choose an integration model and provision projects for your customers.',
    href: '/docs/partners/guides/provisioning',
    icon: FileText,
  },
  {
    title: 'Marketplaces',
    description: 'Publish OAuth apps, run install flows, and manage integrations with the Apps and OAuth APIs.',
    href: '/docs/partners/guides/marketplaces',
    icon: FileText,
  },
  {
    title: 'Multi-tenancy',
    description: 'Isolate customer data and resources across organizations and projects.',
    href: '/docs/partners/guides/multi-tenancy',
    icon: FileText,
  },
]
