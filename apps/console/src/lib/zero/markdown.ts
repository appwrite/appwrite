import { DEFAULT_LLMS_ORIGIN } from '@/lib/seo/llms'
import {
  ZERO_AGENT_PROMPT,
  ZERO_COMMAND,
  type ZeroService,
  zeroEntries,
  zeroFaqItems,
  zeroLifecycle,
  zeroMeta,
  zeroResponse,
  zeroServiceItems,
  zeroServices,
} from '@/lib/zero/marketing-content'

const SERVICE_NAMES: Record<ZeroService['id'], string> = {
  auth: 'Auth',
  databases: 'Databases',
  storage: 'Storage',
  functions: 'Functions',
  sites: 'Sites',
  realtime: 'Realtime',
  messaging: 'Messaging',
}

/** Markdown twin of /zero for coding agents. */
export function buildZeroMarkdown(
  origin: string = DEFAULT_LLMS_ORIGIN,
): string {
  const response = Object.fromEntries(
    zeroResponse.map((field) => [field.key, field.value]),
  )

  return [
    '# Appwrite Zero',
    '',
    `> ${zeroMeta.description}`,
    '',
    '```bash',
    ZERO_COMMAND,
    '```',
    '',
    '## For coding agents',
    '',
    `1. Run \`${ZERO_COMMAND} --json\` to get the manifest as JSON. Without Node.js, call \`POST /v1/zero\` instead.`,
    '2. Build against the `endpoint` and `projectId` it returns. The CLI keeps the API key in its own session, so `npx appwrite` commands work without `login`. The key has every project scope until `expiresAt`.',
    '3. Site and function domains stay private until the project is claimed. Send the `accessToken` in the `X-Appwrite-Zero-Token` header to reach them. `npx appwrite zero status` prints the time left and the owner link for each domain.',
    '4. When you finish, give the user the owner link to open what you built and the `claimUrl` to keep it. The claim link works until `claimBy`.',
    '',
    '## Ways in',
    '',
    ...zeroEntries.flatMap((entry) => [
      `### ${entry.label}`,
      '',
      entry.summary,
      '',
      '```bash',
      entry.call,
      '```',
      '',
    ]),
    '## Response',
    '',
    'Example `POST /v1/zero` response, with shortened values.',
    '',
    '```json',
    JSON.stringify(response, null, 2),
    '```',
    '',
    '| Field | Note |',
    '| --- | --- |',
    ...zeroResponse
      .filter((field) => field.note)
      .map((field) => `| \`${field.key}\` | ${field.note} |`),
    '',
    '## How it works',
    '',
    zeroLifecycle.description,
    '',
    ...zeroLifecycle.stages.map(
      (stage, index) =>
        `${index + 1}. **${stage.title}.** ${stage.description}`,
    ),
    '',
    zeroLifecycle.claim,
    '',
    '## What you get',
    '',
    zeroServices.description,
    '',
    `| Service | What works | ${zeroServices.caption} |`,
    '| --- | --- | --- |',
    ...zeroServiceItems.map(
      (service) =>
        `| ${SERVICE_NAMES[service.id]} | ${service.detail} | ${service.limit} |`,
    ),
    '',
    '## Prompt to hand your agent',
    '',
    '```text',
    ZERO_AGENT_PROMPT,
    '```',
    '',
    '## FAQ',
    '',
    ...zeroFaqItems.flatMap((item) => [
      `### ${item.question}`,
      '',
      item.answer,
      '',
    ]),
    '## Links',
    '',
    `- [HTML](${origin}/zero)`,
    `- [Sign up instead](${origin}/sign-up)`,
    `- [Appwrite for coding agents](${origin}/for-agents.md)`,
    `- [llms.txt](${origin}/llms.txt)`,
    '',
  ].join('\n')
}
