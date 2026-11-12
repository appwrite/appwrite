import type { LucideIcon } from 'lucide-react'
import {
  ArrowRightLeft,
  Building2,
  Cloud,
  Database,
  Folder,
  FolderKanban,
  Gift,
  Globe,
  Handshake,
  Layers,
  Lightbulb,
  MapPin,
  MessageSquare,
  Rocket,
  Server,
  Shield,
  Users,
  Zap,
} from 'lucide-react'
import type { MarketingFaqItem } from '@/components/pages/marketing/MarketingFaqSection'
import type { AnalyticsActionId } from '@/lib/analytics-actions'
import type { ProductTone } from '@/lib/products/theme'

export type SolutionCtaKind = 'signup' | 'pricing' | 'sales'

export type SolutionCta = {
  label: string
  kind: SolutionCtaKind
  analytics: AnalyticsActionId
}

export type SolutionFeature = {
  title: string
  description: string
  icon?: LucideIcon
  iconSrc?: string
}

export type SolutionCapabilityGroup = {
  title: string
  icon: LucideIcon
  items: { title: string; description: string }[]
}

export type SolutionHeroMetric = {
  icon: LucideIcon
  title: string
  value: string
  /** Numbers, sample ids, and plan names stay as written. */
  literal?: boolean
  code?: boolean
  detail: string
  /** Sample domains and filenames. Not translated. */
  mono?: boolean
}

export type SolutionHeroPath = {
  steps: readonly { label: string; literal?: boolean }[]
  note: string
}

export type SolutionHeroProject = {
  name: string
  detail: string
  status: string
}

export type SolutionPageContent = {
  tone: ProductTone
  secondaryTone: ProductTone
  hero: {
    eyebrow: string
    title: string
    accent: string
    description: string
    orgLabel: string
    status: string
    board:
      | { kind: 'metrics'; metrics: readonly SolutionHeroMetric[]; path: SolutionHeroPath }
      | { kind: 'projects'; projects: readonly SolutionHeroProject[]; path: SolutionHeroPath }
    chips: readonly { label: string; icon?: LucideIcon; iconSrc?: string }[]
  }
  primaryCta: SolutionCta
  secondaryCta: SolutionCta
  stats: readonly { value: string; label: string }[]
  customers: { eyebrow: string; title: string; description: string }
  why: {
    eyebrow: string
    title: string
    description: string
    items: readonly SolutionFeature[]
  }
  platform: {
    eyebrow: string
    title: string
    description: string
    platformLabel: string
    outcomes: readonly string[]
  }
  capabilities: {
    eyebrow: string
    title: string
    description: string
    /**
     * `stages` is the product journey (backend, site, firewall).
     * `path` is a pilot that can move onto Enterprise.
     */
    layout?: 'columns' | 'stages' | 'path'
    groups: readonly SolutionCapabilityGroup[]
  }
  faq: {
    title: string
    description: string
    items: readonly MarketingFaqItem[]
  }
  closing: {
    title: string
    description: string
  }
}

export const startupsPage = {
  tone: 'mint',
  secondaryTone: 'orange',
  hero: {
    eyebrow: 'Startups',
    title: 'Build the product',
    accent: 'on one backend.',
    description:
      'Auth, databases, storage, functions, messaging, and hosting in one project. Start on the Free plan. Move to Pro when people are using the product. The APIs stay the same.',
    orgLabel: 'northstar · Pro',
    status: 'Project live',
    board: {
      kind: 'metrics',
      metrics: [
        { icon: Users, title: 'Auth', value: '1,284', literal: true, detail: 'users' },
        { icon: Database, title: 'Databases', value: '12.4k', literal: true, detail: 'rows' },
        { icon: Folder, title: 'Storage', value: '86', literal: true, detail: 'files' },
        { icon: Zap, title: 'Functions', value: 'node-22', literal: true, code: true, detail: 'Deployed' },
        { icon: MessageSquare, title: 'Messaging', value: '3', literal: true, detail: 'Email, SMS, and push' },
        { icon: Globe, title: 'Sites', value: 'Live', detail: 'app.example.com', mono: true },
      ],
      path: {
        steps: [
          { label: 'Free', literal: true },
          { label: 'Pro', literal: true },
        ],
        note: 'Same APIs on Free and Pro',
      },
    },
    chips: [
      { label: 'Free plan to start', icon: Gift },
      { label: 'Open source', iconSrc: '/icons/github-circle.svg' },
    ],
  },
  primaryCta: {
    label: 'Get started',
    kind: 'signup',
    analytics: 'startups-get-started',
  },
  secondaryCta: {
    label: 'View pricing',
    kind: 'pricing',
    analytics: 'startups-view-pricing',
  },
  stats: [
    { value: 'Free', label: 'Plan to start building' },
    { value: 'Pro', label: 'When the product has users' },
    { value: '13+', label: 'Function runtimes' },
    { value: '1', label: 'Console for the whole stack' },
    { value: 'OSS', label: 'Self-host the same platform' },
  ],
  customers: {
    eyebrow: 'Customers',
    title: 'Teams already shipping on Appwrite',
    description:
      'Early products and larger companies run on the same platform. You do not switch backends when the product grows.',
  },
  why: {
    eyebrow: 'Why Appwrite',
    title: 'A backend you do not have to staff',
    description:
      'A first version usually means wiring auth, a database, file storage, and hosting before the product exists. Appwrite is those services, already wired, with one set of APIs.',
    items: [
      {
        title: 'One stack',
        description:
          'Auth, databases, storage, functions, messaging, and sites. One vendor for the first version, and the one after it.',
        icon: Layers,
      },
      {
        title: 'A working API the same day',
        description:
          'Create a project, add a platform, and call the API. There is no cluster to provision first.',
        icon: Zap,
      },
      {
        title: 'Room to grow',
        description:
          'The Free plan covers the prototype. Pro covers production traffic. Limits go up without a rewrite.',
        icon: Rocket,
      },
      {
        title: 'Open source',
        description:
          'Run Appwrite on Cloud today. The same APIs run on your own servers whenever you want them there.',
        iconSrc: '/icons/github-circle.svg',
      },
    ],
  },
  platform: {
    eyebrow: 'One platform',
    title: 'The list a first version always needs',
    description:
      'Identity, a database, files, somewhere to run code, and a place to host the site. Appwrite replaces that list with one project.',
    platformLabel: 'Appwrite',
    outcomes: ['One signup', 'One Console', 'One invoice', 'Same APIs later'],
  },
  capabilities: {
    eyebrow: 'What you get',
    title: 'The backend, the site, then the firewall',
    description:
      'That is the order. The same project moves to Pro when you need higher limits, and the APIs stay the same.',
    layout: 'stages',
    groups: [
      {
        title: 'Build',
        icon: Layers,
        items: [
          {
            title: 'Auth',
            description: 'Email, OAuth, and teams, without a separate identity vendor.',
          },
          {
            title: 'Databases',
            description: 'Tables, documents, or vectors in the same project.',
          },
          {
            title: 'Storage',
            description: 'Files and image previews, with permissions.',
          },
          {
            title: 'Functions',
            description: 'Custom logic in the runtimes you already use.',
          },
          {
            title: 'Messaging',
            description: 'Email, SMS, and push from the same project.',
          },
          {
            title: 'Realtime',
            description: 'Live updates when data changes.',
          },
        ],
      },
      {
        title: 'Deploy',
        icon: Rocket,
        items: [
          {
            title: 'Sites',
            description: 'Host the web app next to the API.',
          },
          {
            title: 'Domains',
            description: 'Search, buy, and connect a domain from the Console.',
          },
        ],
      },
      {
        title: 'Protect',
        icon: Shield,
        items: [
          {
            title: 'Firewall',
            description: 'Deny, rate limit, or redirect traffic on the project.',
          },
        ],
      },
    ],
  },
  faq: {
    title: 'Startups FAQ',
    description: 'Plans, cost, and what happens when the product gets bigger.',
    items: [
      {
        question: 'Who is this for?',
        answer:
          'Founders and small product teams building a software product on Appwrite Cloud.',
      },
      {
        question: 'Do we need to talk to sales first?',
        answer:
          'No. Create an account and start on the Free plan. Move to Pro in the Console when you need higher limits.',
        links: [{ label: 'View pricing', href: '/pricing' }],
      },
      {
        question: 'What if we later need SSO, an SLA, or a self-hosted edition?',
        answer:
          'That is the Enterprise plan. The APIs stay the same. Contact sales when the company needs that contract.',
        links: [{ label: 'Enterprise', href: '/enterprise' }],
      },
      {
        question: 'Can we move from another backend?',
        answer:
          'Yes. Appwrite includes a migration tool for users, databases, and files. The docs cover the steps, and the team can help if the move is large.',
      },
      {
        question: 'What does it cost?',
        answer:
          'The Free plan is free. Pro is listed on the pricing page. You pay for the plan, plus any usage above the included limits.',
        links: [{ label: 'View pricing', href: '/pricing' }],
      },
    ],
  },
  closing: {
    title: 'Create a project and ship',
    description:
      'Start on the Free plan. Move to Pro in the Console when the business needs higher limits.',
  },
} as const satisfies SolutionPageContent

export const agenciesPage = {
  tone: 'orange',
  secondaryTone: 'pink',
  hero: {
    eyebrow: 'Agencies',
    title: 'Ship client work',
    accent: 'on the same stack.',
    description:
      'Each client gets a project with auth, data, files, functions, and hosting. Your team keeps one Console and one way of working. When the engagement ends, the client can run it.',
    orgLabel: 'studio · Clients',
    status: '3 projects active',
    board: {
      kind: 'projects',
      projects: [
        { name: 'acme', detail: 'acme.example.com', status: 'Live' },
        { name: 'northwind', detail: 'northwind.example.com', status: 'In progress' },
        { name: 'luma', detail: 'client@luma.com', status: 'Handoff' },
      ],
      path: {
        steps: [{ label: 'Your team' }, { label: 'The client' }],
        note: 'The client can run the project',
      },
    },
    chips: [
      { label: 'A project per client', icon: FolderKanban },
      { label: 'Handoff included', icon: Handshake },
    ],
  },
  primaryCta: {
    label: 'Get started',
    kind: 'signup',
    analytics: 'agencies-get-started',
  },
  secondaryCta: {
    label: 'Contact sales',
    kind: 'sales',
    analytics: 'agencies-contact-sales',
  },
  stats: [
    { value: '1', label: 'Stack for every client' },
    { value: 'Pro', label: 'Pricing on the organization' },
    { value: 'Teams', label: 'Invite the client at handoff' },
    { value: 'Sites', label: 'Host the product you ship' },
    { value: 'OSS', label: 'The client can leave with it' },
  ],
  customers: {
    eyebrow: 'Customers',
    title: 'Client products that stay on Appwrite',
    description:
      'Agencies ship on the same platform the client keeps after launch. The handoff is the project.',
  },
  why: {
    eyebrow: 'Why Appwrite',
    title: 'Stop inventing a stack for every brief',
    description:
      'The brief changes. Auth, a database, files, and hosting usually do not. Appwrite is that stack on every project, so the second client is faster than the first.',
    items: [
      {
        title: 'A repeatable project',
        description:
          'Same services, same SDKs, same Console. A new engagement starts from a pattern the team already knows.',
        icon: Layers,
      },
      {
        title: 'One project per client',
        description:
          'Each client has its own project, with its own users, keys, and data.',
        icon: FolderKanban,
      },
      {
        title: 'A handoff the client can run',
        description:
          'Invite them into the organization, or give them the project. They are not stuck on a tool only your team can operate.',
        icon: Handshake,
      },
      {
        title: 'Hosting in the same project',
        description: 'Ship the site on Appwrite Sites, next to the API the product calls.',
        icon: Globe,
      },
    ],
  },
  platform: {
    eyebrow: 'One platform',
    title: 'The stack you stop reassembling',
    description:
      'Every engagement starts with the same backend list. Appwrite covers it, so the hours go into the product.',
    platformLabel: 'Appwrite',
    outcomes: ['One stack', 'One Console', 'A project per client', 'A real handoff'],
  },
  capabilities: {
    eyebrow: 'How agencies use it',
    title: 'From the first commit to the handoff',
    description: 'Build in your organization. Leave the client with a project they can operate.',
    groups: [
      {
        title: 'Client work',
        icon: FolderKanban,
        items: [
          {
            title: 'Project per client',
            description: 'Users, data, and files stay inside that project.',
          },
          {
            title: 'API keys',
            description: 'Server keys scoped to the project you are shipping.',
          },
          {
            title: 'Team permissions',
            description: 'Your developers and the client team, with different access.',
          },
        ],
      },
      {
        title: 'The product',
        icon: Layers,
        items: [
          {
            title: 'Auth through functions',
            description: 'Sign-in, data, files, and custom logic in one project.',
          },
          {
            title: 'Sites',
            description: 'Host the web app you were hired to ship.',
          },
          {
            title: 'Messaging',
            description: 'Email, SMS, and push when the product sends them.',
          },
        ],
      },
      {
        title: 'After launch',
        icon: Handshake,
        items: [
          {
            title: 'They can stay on Cloud',
            description: 'The client keeps the project on the Free or Pro plan.',
          },
          {
            title: 'Open source',
            description: 'They can self-host if their policy requires it.',
          },
          {
            title: 'Enterprise',
            description: 'SSO, SLAs, and a self-hosted edition when the client needs that contract.',
          },
        ],
      },
    ],
  },
  faq: {
    title: 'Agencies FAQ',
    description: 'Clients, billing, and what happens when you hand the project over.',
    items: [
      {
        question: 'Can we run many clients on one account?',
        answer:
          'Yes. Create a project per client. Keep the projects in your organization while you build, and move or share a project when you hand it over.',
      },
      {
        question: 'Does the client need their own Appwrite account?',
        answer:
          'They can. Invite them as members, or have them create an organization and take the project. They use the same Console you do.',
      },
      {
        question: 'What do we pay Appwrite?',
        answer:
          'You pay for the plan on the organization that owns the projects. What you charge the client is between you and them.',
        links: [{ label: 'View pricing', href: '/pricing' }],
      },
      {
        question: 'What if a client needs Enterprise?',
        answer:
          'The project does not need a rewrite. Contact sales with them, or send them to the Enterprise page, when they need SSO, an SLA, or a self-hosted edition.',
        links: [{ label: 'Enterprise', href: '/enterprise' }],
      },
    ],
  },
  closing: {
    title: 'Start the next client project',
    description:
      'Create an account, open a project, and use the same stack on the next engagement.',
  },
} as const satisfies SolutionPageContent

export const innovationTeamsPage = {
  tone: 'purple',
  secondaryTone: 'mint',
  hero: {
    eyebrow: 'Innovation teams',
    title: 'Prove the idea',
    accent: 'on a real backend.',
    description:
      'Labs and incubation teams can stand up auth, data, files, and hosting on Appwrite Cloud without waiting on a platform build. If the pilot sticks, the same APIs run on an Enterprise plan, including a self-hosted edition.',
    orgLabel: 'lab · Pilot',
    status: 'Pilot in progress',
    board: {
      kind: 'metrics',
      metrics: [
        { icon: Users, title: 'Auth', value: '18', literal: true, detail: 'users' },
        { icon: Database, title: 'Databases', value: '240', literal: true, detail: 'rows' },
        { icon: Folder, title: 'Storage', value: '12', literal: true, detail: 'files' },
        { icon: Zap, title: 'Functions', value: 'node-22', literal: true, code: true, detail: 'Deployed' },
        { icon: MessageSquare, title: 'Messaging', value: 'Email', detail: 'From the pilot' },
        { icon: Globe, title: 'Sites', value: 'Live', detail: 'pilot.example.com', mono: true },
      ],
      path: {
        steps: [
          { label: 'Cloud', literal: true },
          { label: 'Enterprise', literal: true },
        ],
        note: 'Including a self-hosted edition',
      },
    },
    chips: [
      { label: 'Start on Cloud', icon: Cloud },
      { label: 'Self-host later', icon: Server },
    ],
  },
  primaryCta: {
    label: 'Get started',
    kind: 'signup',
    analytics: 'innovation-get-started',
  },
  secondaryCta: {
    label: 'Contact sales',
    kind: 'sales',
    analytics: 'innovation-contact-sales',
  },
  stats: [
    { value: 'Cloud', label: 'No infra ticket to start' },
    { value: '1', label: 'Stack for the pilot' },
    { value: 'API', label: 'APIs if it reaches production' },
    { value: 'Enterprise', label: 'When the pilot is a product' },
    { value: 'OSS', label: 'Self-host if policy requires it' },
  ],
  customers: {
    eyebrow: 'Customers',
    title: 'A pilot on the platform production uses',
    description:
      'The demo is an Appwrite project, with users, permissions, and a path onto Enterprise.',
  },
  why: {
    eyebrow: 'Why Appwrite',
    title: 'A pilot should not wait on a platform project',
    description:
      'Innovation teams get stuck between a slide and a six-month build. Appwrite lets the team ship a working product on Cloud, then hand over a platform that already exists.',
    items: [
      {
        title: 'A working pilot',
        description:
          'Auth, a database, storage, and hosting are already there. The team builds the product.',
        icon: Lightbulb,
      },
      {
        title: 'Something IT can take',
        description:
          'The pilot has users, permissions, and logs. Enterprise adds SSO, SLAs, and a self-hosted edition.',
        icon: Building2,
      },
      {
        title: 'A region you can name',
        description:
          'Pick a Cloud region for the pilot. If policy requires your own environment, the self-hosted edition is the same platform.',
        icon: MapPin,
      },
      {
        title: 'Handoff to the product org',
        description:
          'The team that owns the product can take the project. They do not rebuild it on another stack.',
        icon: ArrowRightLeft,
      },
    ],
  },
  platform: {
    eyebrow: 'One platform',
    title: 'What a pilot usually bolts together',
    description:
      'Identity, a database, files, a place to run code, and somewhere to host the UI. Appwrite is that list, in one project.',
    platformLabel: 'Appwrite',
    outcomes: ['One project', 'One Console', 'Same APIs later', 'A path to Enterprise'],
  },
  capabilities: {
    eyebrow: 'From pilot to production',
    title: 'Start small. Keep the APIs.',
    description:
      'Build on Cloud. Move to Enterprise terms when the company is ready to own the product.',
    layout: 'path',
    groups: [
      {
        title: 'Pilot',
        icon: Lightbulb,
        items: [
          {
            title: 'Auth',
            description: 'Sign-in for the first users, without a separate identity project.',
          },
          {
            title: 'Databases',
            description: 'A database in the project, without an infra ticket.',
          },
          {
            title: 'Storage',
            description: 'Files for the demo, with permissions.',
          },
          {
            title: 'Functions',
            description: 'The logic that is not plain CRUD.',
          },
        ],
      },
      {
        title: 'Show it',
        icon: Globe,
        items: [
          {
            title: 'Sites',
            description: 'Host the demo next to the API.',
          },
          {
            title: 'Realtime',
            description: 'Live updates when the demo needs them.',
          },
          {
            title: 'Messaging',
            description: 'The emails and notifications the pilot sends.',
          },
        ],
      },
      {
        title: 'If it sticks',
        icon: Building2,
        items: [
          {
            title: 'Enterprise plan',
            description: 'SSO, SLAs, and custom limits when procurement starts.',
          },
          {
            title: 'Self-hosted edition',
            description: 'The same platform, in your environment.',
          },
          {
            title: 'Same SDKs',
            description: 'The pilot code keeps calling the same APIs.',
          },
        ],
      },
    ],
  },
  faq: {
    title: 'Innovation teams FAQ',
    description: 'Pilots, procurement, and self-hosting.',
    items: [
      {
        question: 'Who is this for?',
        answer:
          'Internal innovation, R&D, and incubation teams that need a working product and do not want to wait for a central platform build.',
      },
      {
        question: 'Can we start before procurement?',
        answer:
          'Yes. A team can create an Appwrite Cloud account and build on the Free or Pro plan. Procurement matters when you need Enterprise terms.',
        links: [{ label: 'View pricing', href: '/pricing' }],
      },
      {
        question: 'What if the company requires self-hosting?',
        answer:
          'The Enterprise self-hosted edition is the same platform, in your environment. Talk to sales when that requirement is real. You do not throw the pilot away.',
        links: [{ label: 'Enterprise', href: '/enterprise' }],
      },
      {
        question: 'What do we bring to a security review?',
        answer:
          'Cloud Enterprise can include SOC 2 and HIPAA support, plus SSO and activity logs. Start the pilot on Cloud, and bring the Enterprise page into the review when the pilot is worth a contract.',
        links: [{ label: 'Enterprise', href: '/enterprise' }],
      },
    ],
  },
  closing: {
    title: 'Start the pilot on Cloud',
    description:
      'Build on the Free or Pro plan. Talk to sales when the pilot needs Enterprise terms or a self-hosted edition.',
  },
} as const satisfies SolutionPageContent
