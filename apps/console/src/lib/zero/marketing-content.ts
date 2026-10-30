import type { MarketingFaqItem } from '@/components/pages/marketing/MarketingFaqSection'

export const ZERO_COMMAND = 'appwrite zero'
export const ZERO_CLI_INSTALL =
  'curl -sL https://appwrite.io/cli/install.sh | bash'
export const ZERO_CURL = `curl -X POST https://cloud.appwrite.io/v1/zero \\
  -H 'X-Appwrite-Project: console'`
export const ZERO_MCP_ADD = 'claude mcp add appwrite -- uvx mcp-server-appwrite'
export const ZERO_BUILD_MINUTES = 60
export const ZERO_CLAIM_HOURS = 24

export const zeroHero = {
  title: 'A backend in one command',
  description:
    'Appwrite Zero gives you or your coding agent a real Appwrite project with no account and no card. Build for an hour, then claim it to keep everything.',
} as const

export const ZERO_AGENT_PROMPT =
  'Get yourself an Appwrite backend with `appwrite zero`. It creates a real project with no account and works for 60 minutes. The output has the endpoint, the project ID and a claim link. Build the backend there, then send me the owner link for the site and the claim link so I can keep the project.'

export type ZeroEntry = {
  id: 'cli' | 'http' | 'mcp'
  label: string
  summary: string
  call: string
  response: string
}

const sample = {
  projectId: '6721f0c4001e8b3a9d52',
  endpoint: 'https://fra.cloud.appwrite.io/v1',
  consoleUrl:
    'https://cloud.appwrite.io/console/project-fra-6721f0c4001e8b3a9d52',
  claimUrl: 'https://cloud.appwrite.io/transfer?transferId=6721f0c5…&secret=…',
}

export const zeroEntries: ZeroEntry[] = [
  {
    id: 'cli',
    label: 'CLI',
    summary:
      'Writes appwrite.config.json and signs the CLI in with the project key, so appwrite push deploys straight into it.',
    call: ZERO_COMMAND,
    response: `✓ Your Appwrite Zero project is live → ./appwrite.config.json

  Project    ${sample.projectId}
  Endpoint   ${sample.endpoint}

  You have 60 min to build. Claim it within 25h to keep it:
    ${sample.claimUrl}`,
  },
  {
    id: 'http',
    label: 'HTTP',
    summary:
      'One public request, no key. The response carries an API key with every project scope until the build window ends.',
    call: ZERO_CURL,
    response: `{
  "projectId": "${sample.projectId}",
  "endpoint": "${sample.endpoint}",
  "apiKey": "standard_8f3c…",
  "consoleUrl": "${sample.consoleUrl}",
  "claimUrl": "${sample.claimUrl}",
  "expiresAt": "2026-10-30T17:05:00.000+00:00",
  "claimBy": "2026-10-31T17:05:00.000+00:00"
}`,
  },
  {
    id: 'mcp',
    label: 'MCP',
    summary:
      'Start the local MCP server without a project or key. It asks for a Zero project and points every other tool at it.',
    call: ZERO_MCP_ADD,
    response: `appwrite_create_zero_project()

Created a temporary Appwrite project. The other Appwrite tools
now operate on it. Give the user this link to keep the project:
${sample.claimUrl}`,
  },
]

export type ZeroStage = {
  id: 'build' | 'claim' | 'deleted'
  title: string
  description: string
}

export const zeroStages: ZeroStage[] = [
  {
    id: 'build',
    title: 'Building',
    description:
      'Every service answers and the API key has every project scope. Sites and functions open only through your owner link.',
  },
  {
    id: 'claim',
    title: 'Paused, data kept',
    description:
      'API calls stop with zero_expired. Nothing is deleted. Claim it and the project comes back as you left it.',
  },
  {
    id: 'deleted',
    title: 'Deleted',
    description:
      'Nobody claimed it, so the project and its data are gone. Run the command again for a new one.',
  },
]

export type ZeroAllowance = {
  name: string
  detail: string
}

export const zeroAllowances: ZeroAllowance[] = [
  {
    name: 'Auth',
    detail:
      'Sign up users with email, OAuth, magic links and anonymous sessions.',
  },
  {
    name: 'Databases',
    detail:
      'Tables, rows, queries, indexes and permissions, through the SDKs or the API key.',
  },
  {
    name: 'Storage',
    detail: 'A bucket for uploads, with image previews and file tokens.',
  },
  {
    name: 'Functions',
    detail:
      'Deploy code in any runtime. Up to 500 executions an hour while unclaimed.',
  },
  {
    name: 'Sites',
    detail:
      'Deploy a frontend to a real domain. Up to 20 deployments an hour, private until claimed.',
  },
  {
    name: 'Realtime',
    detail: 'Subscribe to changes over WebSockets from the same project.',
  },
]

export const zeroClaimSteps = [
  {
    title: 'Open the claim link',
    description:
      'Sign in, or create an account. The link works any time before the deadline.',
  },
  {
    title: 'Pick an organization',
    description: 'Choose one you own, or create a free one on the spot.',
  },
  {
    title: 'Keep building',
    description:
      'Same project, same ID, same API key, now without an expiry. Its domains open to everyone.',
  },
] as const

export const zeroFaqItems: MarketingFaqItem[] = [
  {
    question: 'Is it really free?',
    answer:
      'Yes. You never enter a card. An unclaimed project runs on its own Zero plan with hourly caps, so nobody pays for it and it stays free for everyone.',
  },
  {
    question: 'Do I need an Appwrite account?',
    answer:
      'No. The command gives you the project and its API key straight away. You only sign in when you want to keep it.',
  },
  {
    question: 'What happens after 60 minutes?',
    answer:
      'The project pauses and API calls return zero_expired, but nothing is deleted. You then have 24 hours to claim it from the link you were given. Unclaimed projects are deleted with their data.',
  },
  {
    question: 'Who can open my site or function domain?',
    answer:
      'Only you, until you claim the project. Open the owner link the CLI prints once in your browser, or send the X-Appwrite-Zero-Token header from an agent. After you claim it, the domains are public.',
  },
  {
    question: 'What is limited before I claim it?',
    answer:
      'One project, 500 function executions and 20 deployments an hour, and no messaging. You cannot create other projects or organizations from it. Everything else follows the Free plan.',
  },
  {
    question: 'What if it says to try again later?',
    answer:
      'Each region holds a fixed number of unclaimed projects, and each network can create five a day. If you hit either limit, try again shortly or sign up to keep building.',
  },
  {
    question: 'Can my coding agent use it?',
    answer:
      'Yes, that is what it is for. An agent can run appwrite zero, call POST /v1/zero, or start the Appwrite MCP server without credentials. It gets the endpoint, the key and the claim link, and hands the claim link back to you.',
  },
]
