/**
 * Appwrite clarify protocol (`appwrite.clarify/v1`).
 *
 * Shareable contract between the agent engine and the Console.
 * The agent posts structured follow-ups via the built-in `clarify` tool;
 * the Console renders prompts and sends the user's answers on the next turn.
 */

/** Wire protocol id — bump only on breaking changes. */
export type ClarifyProtocolId = 'appwrite.clarify/v1'

export const CLARIFY_PROTOCOL_ID: ClarifyProtocolId = 'appwrite.clarify/v1'
export const CLARIFY_TOOL_NAME = 'clarify'

export type ClarifyEnvelope = {
  protocol: ClarifyProtocolId
  title?: string
  prompts: ClarifyPrompt[]
}

export type ClarifyOption = {
  id: string
  label: string
  description?: string
}

export type ClarifyPrompt =
  | {
      id: string
      kind: 'choice'
      question: string
      hint?: string
      required?: boolean
      options: ClarifyOption[]
      allowMultiple?: boolean
    }
  | {
      id: string
      kind: 'confirm'
      question: string
      hint?: string
      required?: boolean
      confirmLabel?: string
      cancelLabel?: string
      /** Destructive styling (deletes, irreversible ops) */
      danger?: boolean
    }
  | {
      id: string
      kind: 'text'
      question: string
      hint?: string
      required?: boolean
      placeholder?: string
      defaultValue?: string
      multiline?: boolean
    }

/** Suggested shape for the next user turn / structured reply. */
export type ClarifyAnswers = {
  protocol: ClarifyProtocolId
  answers: Array<
    | { id: string; kind: 'choice'; values: string[] }
    | { id: string; kind: 'confirm'; confirmed: boolean }
    | { id: string; kind: 'text'; value: string }
  >
}

export type ClarifyAnswer =
  ClarifyAnswers['answers'][number]

export type ClarifyToolEnvelope = {
  key: string
  messageId?: string
  toolCallId?: string
  envelope: ClarifyEnvelope
}

export function isClarifyToolName(name: string | null | undefined): boolean {
  return (name?.trim().toLowerCase() ?? '') === CLARIFY_TOOL_NAME
}

function toolOutputToText(output: unknown): string | null {
  if (output === undefined || output === null) return null
  if (typeof output === 'string') return output
  try {
    return JSON.stringify(output)
  } catch {
    return String(output)
  }
}

function asNonEmptyString(value: unknown): string | null {
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  return trimmed ? trimmed : null
}

function parseClarifyOption(value: unknown): ClarifyOption | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null
  const record = value as Record<string, unknown>
  const id = asNonEmptyString(record.id)
  const label = asNonEmptyString(record.label)
  if (!id || !label) return null
  const description = asNonEmptyString(record.description) ?? undefined
  return description ? { id, label, description } : { id, label }
}

function parseClarifyPrompt(value: unknown): ClarifyPrompt | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null
  const record = value as Record<string, unknown>
  const id = asNonEmptyString(record.id)
  const kind = asNonEmptyString(record.kind)
  const question = asNonEmptyString(record.question)
  if (!id || !kind || !question) return null

  const hint = asNonEmptyString(record.hint) ?? undefined
  const required =
    typeof record.required === 'boolean' ? record.required : undefined

  if (kind === 'choice') {
    if (!Array.isArray(record.options)) return null
    const options = record.options
      .map(parseClarifyOption)
      .filter((option): option is ClarifyOption => option !== null)
    if (options.length < 2) return null
    return {
      id,
      kind: 'choice',
      question,
      hint,
      required,
      options,
      allowMultiple:
        typeof record.allowMultiple === 'boolean'
          ? record.allowMultiple
          : undefined,
    }
  }

  if (kind === 'confirm') {
    return {
      id,
      kind: 'confirm',
      question,
      hint,
      required,
      confirmLabel: asNonEmptyString(record.confirmLabel) ?? undefined,
      cancelLabel: asNonEmptyString(record.cancelLabel) ?? undefined,
      danger: typeof record.danger === 'boolean' ? record.danger : undefined,
    }
  }

  if (kind === 'text') {
    return {
      id,
      kind: 'text',
      question,
      hint,
      required,
      placeholder: asNonEmptyString(record.placeholder) ?? undefined,
      defaultValue:
        typeof record.defaultValue === 'string'
          ? record.defaultValue
          : undefined,
      multiline:
        typeof record.multiline === 'boolean' ? record.multiline : undefined,
    }
  }

  // Unknown kinds are skipped (forward-compat); do not fail the batch.
  return null
}

/**
 * Parse a clarify tool result into a protocol envelope.
 * Returns null for validation errors, unknown protocol, or malformed JSON.
 */
export function parseClarifyEnvelope(output: unknown): ClarifyEnvelope | null {
  const text = toolOutputToText(output)?.trim()
  if (!text) return null
  if (text.startsWith('Error:')) return null
  try {
    const parsed = JSON.parse(text) as unknown
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return null
    }
    const record = parsed as Record<string, unknown>
    if (record.protocol !== CLARIFY_PROTOCOL_ID) return null
    if (!Array.isArray(record.prompts)) return null

    const prompts = record.prompts
      .map(parseClarifyPrompt)
      .filter((prompt): prompt is ClarifyPrompt => prompt !== null)
    if (prompts.length === 0) return null

    const title = asNonEmptyString(record.title) ?? undefined
    return {
      protocol: CLARIFY_PROTOCOL_ID,
      title,
      prompts,
    }
  } catch {
    return null
  }
}

function clarifyToolKey(tool: {
  id?: string
  toolCallId?: string
  name?: string | null
  messageId?: string
}): string {
  return (
    tool.toolCallId ||
    tool.id ||
    `${tool.messageId ?? 'message'}:${tool.name ?? CLARIFY_TOOL_NAME}`
  )
}

/**
 * Collect clarify envelopes from successful clarify tool results.
 */
export function collectClarifyEnvelopes(
  tools: Array<{
    id?: string
    toolCallId?: string
    name?: string | null
    status?: string | null
    output?: unknown
    errorMessage?: string | null
    messageId?: string
  }>,
): ClarifyToolEnvelope[] {
  const results: ClarifyToolEnvelope[] = []
  for (const tool of tools) {
    if (!isClarifyToolName(tool.name)) continue
    if (tool.errorMessage) continue
    const status = tool.status?.toLowerCase() ?? ''
    if (status === 'error' || status === 'failed') continue
    const envelope = parseClarifyEnvelope(tool.output)
    if (!envelope) continue
    results.push({
      key: clarifyToolKey(tool),
      messageId: tool.messageId,
      toolCallId: tool.toolCallId,
      envelope,
    })
  }
  return results
}

export function isClarifyPromptRequired(prompt: ClarifyPrompt): boolean {
  return prompt.required !== false
}

export function serializeClarifyAnswers(answers: ClarifyAnswers): string {
  return JSON.stringify(answers)
}

/** Compact, human-readable summary for chat bubbles (wire format stays JSON). */
export function formatClarifyAnswersSummary(answers: ClarifyAnswers): string {
  return answers.answers
    .map((answer) => {
      if (answer.kind === 'choice') {
        return `${answer.id}: ${answer.values.join(', ')}`
      }
      if (answer.kind === 'confirm') {
        return `${answer.id}: ${answer.confirmed ? 'confirmed' : 'cancelled'}`
      }
      return `${answer.id}: ${answer.value}`
    })
    .join('\n')
}

/**
 * Parse a user message that replies to a clarify form.
 * Returns null when the text is not a clarify answers envelope.
 */
export function parseClarifyAnswers(text: string): ClarifyAnswers | null {
  const trimmed = text.trim()
  if (!trimmed || trimmed.startsWith('Error:')) return null
  try {
    const parsed = JSON.parse(trimmed) as unknown
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return null
    }
    const record = parsed as Record<string, unknown>
    if (record.protocol !== CLARIFY_PROTOCOL_ID) return null
    if (!Array.isArray(record.answers)) return null

    const answers: ClarifyAnswer[] = []
    for (const item of record.answers) {
      if (!item || typeof item !== 'object' || Array.isArray(item)) continue
      const entry = item as Record<string, unknown>
      const id = asNonEmptyString(entry.id)
      const kind = asNonEmptyString(entry.kind)
      if (!id || !kind) continue

      if (kind === 'choice' && Array.isArray(entry.values)) {
        const values = entry.values.filter(
          (value): value is string =>
            typeof value === 'string' && value.trim().length > 0,
        )
        answers.push({ id, kind: 'choice', values })
        continue
      }
      if (kind === 'confirm' && typeof entry.confirmed === 'boolean') {
        answers.push({ id, kind: 'confirm', confirmed: entry.confirmed })
        continue
      }
      if (kind === 'text' && typeof entry.value === 'string') {
        answers.push({ id, kind: 'text', value: entry.value })
      }
    }

    if (answers.length === 0) return null
    return { protocol: CLARIFY_PROTOCOL_ID, answers }
  } catch {
    return null
  }
}

/** Seed form drafts from a prior ClarifyAnswers reply (historical turns). */
export function draftsFromClarifyAnswers(
  prompts: ClarifyPrompt[],
  answers: ClarifyAnswers | null | undefined,
): Record<
  string,
  | { kind: 'choice'; values: string[] }
  | { kind: 'confirm'; confirmed: boolean | null }
  | { kind: 'text'; value: string }
> {
  const drafts: Record<
    string,
    | { kind: 'choice'; values: string[] }
    | { kind: 'confirm'; confirmed: boolean | null }
    | { kind: 'text'; value: string }
  > = {}

  const byId = new Map(
    (answers?.answers ?? []).map((answer) => [answer.id, answer]),
  )

  for (const prompt of prompts) {
    const answer = byId.get(prompt.id)
    if (prompt.kind === 'choice') {
      drafts[prompt.id] = {
        kind: 'choice',
        values:
          answer?.kind === 'choice' ? answer.values : [],
      }
      continue
    }
    if (prompt.kind === 'confirm') {
      drafts[prompt.id] = {
        kind: 'confirm',
        confirmed:
          answer?.kind === 'confirm' ? answer.confirmed : null,
      }
      continue
    }
    drafts[prompt.id] = {
      kind: 'text',
      value:
        answer?.kind === 'text'
          ? answer.value
          : (prompt.defaultValue ?? ''),
    }
  }

  return drafts
}
