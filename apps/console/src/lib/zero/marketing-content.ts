import type { MarketingFaqItem } from '@/components/pages/marketing/MarketingFaqSection'
import type { MarketingStatItem } from '@/components/pages/marketing/MarketingSections'
import type { ProductFeatureContent } from '@/lib/products/features/types'
import type { ProductId } from '@/lib/products/types'

export const ZERO_COMMAND = 'npx appwrite zero'
export const ZERO_CURL = `curl -X POST \\
  https://cloud.appwrite.io/v1/zero \\
  -H 'X-Appwrite-Project: console'`
export const ZERO_MCP_ADD = 'claude mcp add appwrite -- uvx mcp-server-appwrite'
export const ZERO_BUILD_MINUTES = 60
export const ZERO_CLAIM_HOURS = 24

export const zeroMeta = {
  title: 'Zero signup. Full backend.',
  description:
    'Appwrite Zero gives you or your coding agent a real Appwrite project with no account and no card. Build for an hour, then claim it to keep everything.',
} as const

export const zeroHero = {
  title: 'Zero signup. Full backend',
  description:
    'A real Appwrite project for you or your coding agent, with no account and no card. Build for an hour, then claim it to keep everything.',
  stats: [
    { value: '0', label: 'Signup forms' },
    { value: '0', label: 'Keys to paste' },
    { value: '60 min', label: 'To build with every service' },
    { value: '24 h', label: 'To claim it after that' },
  ] satisfies MarketingStatItem[],
} as const

export const ZERO_AGENT_PROMPT = `Use Appwrite Zero for the backend. Run \`${ZERO_COMMAND}\` to get a real Appwrite project with no account. The project works for ${ZERO_BUILD_MINUTES} minutes, and the output has the endpoint, the project ID and a claim link. Build there, then send me the owner link for the site and the claim link so I can keep the project.`

/** Local coding agents that can run the command and accept a prefilled prompt. */
export const ZERO_AGENT_IDE_IDS = [
  'cursor',
  'codex',
  'windsurf',
  'vscode',
] as const

const sample = {
  projectId: '6721f0c4001e8b3a9d52',
  endpoint: 'https://fra.cloud.appwrite.io/v1',
  consoleUrl:
    'https://cloud.appwrite.io/console/project-fra-6721f0c4001e8b3a9d52',
  claimUrl: 'https://cloud.appwrite.io/transfer?transferId=6721f0c5…&secret=…',
  apiKey: 'standard_8f3c…',
}

/** What a claim keeps, as the claim screen lists it. */
export const zeroClaimRows = [
  { label: 'Project ID', value: sample.projectId },
  { label: 'API key', value: sample.apiKey },
  { label: 'Domains', value: 'guestbook-6721f.appwrite.network' },
] as const

export type ZeroAgentExample = {
  request: string
  site: string
  step: string
  detail: string
}

export const zeroAgents = {
  title: 'Hand it to your agent',
  description:
    'Type what you want built and send it to your agent. It creates the backend itself, builds on it, and hands you two links. One opens what it built, the other keeps it.',
  examples: [
    {
      request: 'Build a guestbook',
      site: 'guestbook',
      step: 'Created the messages table',
      detail: 'name, body',
    },
    {
      request: 'Start a launch waitlist',
      site: 'waitlist',
      step: 'Created the signups table',
      detail: 'email',
    },
    {
      request: 'Make a photo board',
      site: 'photo-board',
      step: 'Created the photos bucket',
      detail: 'jpg, png, webp',
    },
    {
      request: 'Add sign-in to my app',
      site: 'my-app',
      step: 'Turned on email sign-in',
      detail: 'email, password',
    },
  ] satisfies ZeroAgentExample[],
  /** Middle step when the visitor types a request of their own. */
  custom: {
    site: 'my-app',
    step: 'Created the tables it needs',
    detail: 'npx appwrite push tables',
  },
  created: { label: 'Created a Zero project', detail: ZERO_COMMAND },
  deployed: { label: 'Deployed the site', detail: 'npx appwrite push sites' },
  reply:
    'It is live. Open the owner link once to see it, then claim the project to keep it.',
  claimLink: 'cloud.appwrite.io/transfer?transferId=6721f0c5…',
} as const

export const zeroLifecycle = {
  title: 'An hour to build. A day to claim it',
  description:
    'The project is real from the first request. When the hour is up it pauses with every row, file and deployment intact, and waits a day for you to claim it.',
  stages: [
    {
      id: 'build',
      title: '60 minutes',
      description:
        'First, build. Every service answers and the API key has every project scope.',
    },
    {
      id: 'pause',
      title: '24 hours',
      description:
        'Then it pauses. API calls return zero_expired and nothing is deleted.',
    },
    {
      id: 'delete',
      title: 'Deleted',
      description:
        'After 25 hours, a project nobody claimed is deleted with its data.',
    },
  ],
  claim:
    'Claim it any time in those 25 hours, even during the build hour. Same project, same ID, same API key, now without an expiry, and its domains open to everyone.',
} as const

export type ZeroService = {
  id: Extract<
    ProductId,
    | 'auth'
    | 'databases'
    | 'storage'
    | 'functions'
    | 'sites'
    | 'realtime'
    | 'messaging'
  >
  detail: string
  limit: string
}

export const zeroServices = {
  title: 'Every service, from minute zero',
  description:
    'A Zero project is a normal Appwrite project on a capped plan. What your agent builds is what you keep, with nothing to migrate.',
  caption: 'Before you claim it',
} as const

export const zeroServiceItems: ZeroService[] = [
  {
    id: 'auth',
    detail: 'Email, OAuth, magic links and anonymous sessions',
    limit: 'Included',
  },
  {
    id: 'databases',
    detail: 'Tables, rows, queries, indexes and permissions',
    limit: 'Included',
  },
  {
    id: 'storage',
    detail: 'Uploads with image previews and file tokens',
    limit: '1 bucket',
  },
  {
    id: 'functions',
    detail: 'Code in any runtime',
    limit: '500 runs an hour',
  },
  {
    id: 'sites',
    detail: 'A frontend on a real domain, private until claimed',
    limit: '20 deploys an hour',
  },
  {
    id: 'realtime',
    detail: 'Subscriptions over WebSockets',
    limit: 'Included',
  },
  {
    id: 'messaging',
    detail: 'Email, SMS and push',
    limit: 'After you claim it',
  },
]

export type ZeroEntry = {
  id: 'cli' | 'http' | 'mcp'
  label: string
  summary: string
  call: string
}

export const zeroEntries: ZeroEntry[] = [
  {
    id: 'cli',
    label: 'CLI',
    summary:
      'Nothing to install beyond Node.js. Writes appwrite.config.json and signs the CLI in with the project key, so npx appwrite push deploys straight into it.',
    call: ZERO_COMMAND,
  },
  {
    id: 'http',
    label: 'HTTP',
    summary:
      'One public request, no key. The response carries an API key with every project scope until the build window ends.',
    call: ZERO_CURL,
  },
  {
    id: 'mcp',
    label: 'MCP',
    summary:
      'Start the local MCP server without a project or key. It asks for a Zero project and points every other tool at it.',
    call: ZERO_MCP_ADD,
  },
]

/** The page's feature sections, in the shape every product page uses. */
export const zeroFeatures: ProductFeatureContent[] = [
  {
    id: 'agents',
    title: zeroAgents.title,
    description: zeroAgents.description,
    docsHref: '/docs/tooling/ai/mcp-servers',
    docsLabel: 'MCP docs',
  },
  {
    id: 'lifecycle',
    title: zeroLifecycle.title,
    description: zeroLifecycle.description,
    docsHref: '/docs/tooling/command-line/commands',
    docsLabel: 'CLI docs',
  },
  {
    id: 'claim',
    title: 'Sign in once, to keep it',
    description:
      'When there is something worth keeping, open the claim link and move the project into an organization you own. Same ID, same key, same data.',
    docsHref: '/docs/advanced/billing/free',
    docsLabel: 'Free plan docs',
  },
  {
    id: 'ways-in',
    title: 'Three ways in',
    description:
      'Each one returns the same project: an endpoint, a project ID, an API key and a claim link.',
    docsHref: '/docs/tooling/command-line/installation',
    docsLabel: 'CLI docs',
    extraDocsLinks: [{ href: '/docs/apis/rest', label: 'REST API docs' }],
  },
  {
    id: 'services',
    title: zeroServices.title,
    description: zeroServices.description,
    docsHref: '/docs/advanced/billing/free',
    docsLabel: 'Free plan docs',
    layout: 'stacked',
    hideVisual: true,
    hideDocsLink: true,
  },
]

/** What `npx appwrite zero` prints for a project created a moment ago. */
export const ZERO_CLI_OUTPUT = [
  '✓ Your Appwrite Zero project is live → ./appwrite.config.json',
  '',
  `  Project    ${sample.projectId}`,
  `  Endpoint   ${sample.endpoint}`,
  `  Console    ${sample.consoleUrl}`,
  '',
  '  You have 60 min to build. Claim it within 25h to keep it:',
  `    ${sample.claimUrl}`,
  '',
  '  Next steps',
  "    appwrite push                Deploy this directory's resources",
  "    appwrite zero status         Check the time left and get each domain's owner link",
].join('\n')

/** What the MCP server's `appwrite_create_zero_project` tool returns, shortened. */
export const ZERO_MCP_OUTPUT = [
  'Created a temporary Appwrite project. The other Appwrite tools now operate on it. Give the user this link to keep the project:',
  sample.claimUrl,
].join('\n')

/** `POST /v1/zero` response, one field per line, with the note shown beside it. */
export const zeroResponse: ReadonlyArray<{
  key: string
  value: string
  note?: string
}> = [
  { key: 'projectId', value: sample.projectId },
  { key: 'endpoint', value: sample.endpoint },
  { key: 'apiKey', value: sample.apiKey, note: 'Every project scope' },
  { key: 'consoleUrl', value: sample.consoleUrl },
  { key: 'claimUrl', value: sample.claimUrl, note: 'Give this to a human' },
  {
    key: 'accessToken',
    value: '9b2e47d1…',
    note: 'Opens your domains until claimed',
  },
  {
    key: 'expiresAt',
    value: '2026-10-30T17:05:00.000+00:00',
    note: 'End of the build hour',
  },
  {
    key: 'claimBy',
    value: '2026-10-31T17:05:00.000+00:00',
    note: 'Claim deadline',
  },
]

export const zeroFaqItems: MarketingFaqItem[] = [
  {
    question: 'Do I need an Appwrite account?',
    answer:
      'No. The command gives you the project and its API key straight away. You only sign in when you want to keep it.',
  },
  {
    question: 'Is it really free?',
    answer:
      'Yes. You never enter a card. An unclaimed project runs on its own Zero plan with hourly caps, so nobody pays for it and it stays free for everyone.',
  },
  {
    question: 'What happens after 60 minutes?',
    answer:
      'The project pauses and API calls return zero_expired, but nothing is deleted. You then have 24 hours to claim it from the link you were given. Unclaimed projects are deleted with their data.',
  },
  {
    question: 'What do I keep when I claim it?',
    answer:
      'All of it. The project keeps its ID, API key, data, files and deployments, and moves into an organization you own. Its site and function domains open to everyone.',
  },
  {
    question: 'Who can open my site or function domain?',
    answer:
      "Only you, until you claim the project. Open a domain's owner link once in your browser, or send the accessToken in the X-Appwrite-Zero-Token header from an agent. npx appwrite zero status lists the owner link for each domain. After you claim it, the domains are public.",
  },
  {
    question: 'What is limited before I claim it?',
    answer:
      'One project, 500 function executions and 20 deployments an hour, and no messaging. You cannot create other projects or organizations from it. Everything else follows the Free plan.',
  },
  {
    question: 'Can my coding agent use it?',
    answer:
      'Yes, that is what it is for. An agent can run npx appwrite zero, call POST /v1/zero, or start the Appwrite MCP server without credentials. It gets the endpoint, the key and the claim link, and hands the claim link back to you.',
  },
  {
    question: 'What if it says to try again later?',
    answer:
      'Each region holds a fixed number of unclaimed projects, and each network can create five a day. If you hit either limit, try again shortly or sign up to keep building.',
  },
]

export const zeroCta = {
  title: 'Start from zero',
  description:
    'One command, a real backend and an hour on the clock. Keep it if you like what you built.',
} as const
