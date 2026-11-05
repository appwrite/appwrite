import { useEffect, useMemo, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  CLARIFY_PROTOCOL_ID,
  collectClarifyEnvelopes,
  draftsFromClarifyAnswers,
  isClarifyPromptRequired,
  parseClarifyAnswers,
  serializeClarifyAnswers,
  type ClarifyAnswer,
  type ClarifyAnswers,
  type ClarifyPrompt,
  type ClarifyToolEnvelope,
} from '@/lib/assistant/clarify-protocol'
import {
  buildTurnView,
  type AssistantMessageLike,
} from '@/lib/assistant/turn-view'

type ChoiceDraft = { kind: 'choice'; values: string[] }
type ConfirmDraft = { kind: 'confirm'; confirmed: boolean | null }
type TextDraft = { kind: 'text'; value: string }
type PromptDraft = ChoiceDraft | ConfirmDraft | TextDraft

type DraftMap = Record<string, PromptDraft>

function buildInitialDrafts(
  prompts: ClarifyPrompt[],
  priorAnswers?: ClarifyAnswers | null,
): DraftMap {
  if (priorAnswers) {
    return draftsFromClarifyAnswers(prompts, priorAnswers) as DraftMap
  }
  return draftsFromClarifyAnswers(prompts, null) as DraftMap
}

function isPromptAnswered(
  prompt: ClarifyPrompt,
  draft: PromptDraft | undefined,
): boolean {
  if (!draft || draft.kind !== prompt.kind) return false
  if (prompt.kind === 'choice' && draft.kind === 'choice') {
    return draft.values.length > 0
  }
  if (prompt.kind === 'confirm' && draft.kind === 'confirm') {
    return draft.confirmed !== null
  }
  if (prompt.kind === 'text' && draft.kind === 'text') {
    return draft.value.trim().length > 0
  }
  return false
}

function canSubmitClarify(prompts: ClarifyPrompt[], drafts: DraftMap): boolean {
  for (const prompt of prompts) {
    if (!isClarifyPromptRequired(prompt)) continue
    if (!isPromptAnswered(prompt, drafts[prompt.id])) return false
  }
  return true
}

function buildClarifyAnswers(
  prompts: ClarifyPrompt[],
  drafts: DraftMap,
): ClarifyAnswers | null {
  if (!canSubmitClarify(prompts, drafts)) return null

  const answers: ClarifyAnswer[] = []
  for (const prompt of prompts) {
    const draft = drafts[prompt.id]
    if (!draft || draft.kind !== prompt.kind) continue

    if (prompt.kind === 'choice' && draft.kind === 'choice') {
      if (draft.values.length === 0) {
        if (isClarifyPromptRequired(prompt)) return null
        continue
      }
      answers.push({
        id: prompt.id,
        kind: 'choice',
        values: draft.values,
      })
      continue
    }

    if (prompt.kind === 'confirm' && draft.kind === 'confirm') {
      if (draft.confirmed === null) {
        if (isClarifyPromptRequired(prompt)) return null
        continue
      }
      answers.push({
        id: prompt.id,
        kind: 'confirm',
        confirmed: draft.confirmed,
      })
      continue
    }

    if (prompt.kind === 'text' && draft.kind === 'text') {
      const value = draft.value.trim()
      if (!value) {
        if (isClarifyPromptRequired(prompt)) return null
        continue
      }
      answers.push({
        id: prompt.id,
        kind: 'text',
        value,
      })
    }
  }

  return {
    protocol: CLARIFY_PROTOCOL_ID,
    answers,
  }
}

function toggleChoiceValue(
  values: string[],
  optionId: string,
  allowMultiple: boolean,
): string[] {
  if (!allowMultiple) return [optionId]
  if (values.includes(optionId)) {
    return values.filter((value) => value !== optionId)
  }
  return [...values, optionId]
}

function ClarifyPromptView({
  prompt,
  draft,
  disabled,
  onChange,
}: {
  prompt: ClarifyPrompt
  draft: PromptDraft | undefined
  disabled: boolean
  onChange: (next: PromptDraft) => void
}) {
  const t = useT()

  if (prompt.kind === 'choice') {
    const values = draft?.kind === 'choice' ? draft.values : []
    const allowMultiple = prompt.allowMultiple === true

    return (
      <div className="space-y-2">
        <div>
          <p className="text-[13px] font-medium text-foreground">
            {prompt.question}
          </p>
          {prompt.hint ? (
            <p className="mt-1 text-[12px] text-muted-foreground">
              {prompt.hint}
            </p>
          ) : null}
        </div>
        <div className="space-y-1.5">
          {prompt.options.map((option) => {
            const selected = values.includes(option.id)
            return (
              <button
                key={option.id}
                type="button"
                disabled={disabled}
                onClick={() =>
                  onChange({
                    kind: 'choice',
                    values: toggleChoiceValue(
                      values,
                      option.id,
                      allowMultiple,
                    ),
                  })
                }
                className={cn(
                  'flex w-full items-start gap-2.5 rounded-md border px-3 py-2 text-start transition-colors',
                  'disabled:cursor-not-allowed disabled:opacity-60',
                  selected
                    ? 'border-foreground/25 bg-muted/50'
                    : 'border-border bg-background hover:bg-muted/30',
                )}
              >
                {allowMultiple ? (
                  <Checkbox
                    checked={selected}
                    disabled={disabled}
                    className="mt-0.5"
                    tabIndex={-1}
                    aria-hidden
                  />
                ) : (
                  <span
                    className={cn(
                      'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                      selected
                        ? 'border-foreground bg-foreground'
                        : 'border-muted-foreground/40',
                    )}
                    aria-hidden
                  >
                    {selected ? (
                      <span className="size-1.5 rounded-full bg-background" />
                    ) : null}
                  </span>
                )}
                <span className="min-w-0 flex-1">
                  <span className="block text-[13px] font-medium text-foreground">
                    {option.label}
                  </span>
                  {option.description ? (
                    <span className="mt-0.5 block text-[12px] text-muted-foreground">
                      {option.description}
                    </span>
                  ) : null}
                </span>
              </button>
            )
          })}
        </div>
      </div>
    )
  }

  if (prompt.kind === 'confirm') {
    const confirmed = draft?.kind === 'confirm' ? draft.confirmed : null
    const confirmLabel = prompt.confirmLabel?.trim() || t('Confirm')
    const cancelLabel = prompt.cancelLabel?.trim() || t('Cancel')

    return (
      <div className="space-y-2">
        <div>
          <p className="text-[13px] font-medium text-foreground">
            {prompt.question}
          </p>
          {prompt.hint ? (
            <p className="mt-1 text-[12px] text-muted-foreground">
              {prompt.hint}
            </p>
          ) : null}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            size="sm"
            variant={prompt.danger ? 'destructive' : 'default'}
            className="h-8 text-[12px]"
            disabled={disabled}
            aria-pressed={confirmed === true}
            onClick={() =>
              onChange({ kind: 'confirm', confirmed: true })
            }
          >
            {confirmLabel}
          </Button>
          <Button
            type="button"
            size="sm"
            variant="outline"
            className={cn(
              'h-8 text-[12px]',
              confirmed === false && 'border-foreground/25 bg-muted/50',
            )}
            disabled={disabled}
            aria-pressed={confirmed === false}
            onClick={() =>
              onChange({ kind: 'confirm', confirmed: false })
            }
          >
            {cancelLabel}
          </Button>
        </div>
      </div>
    )
  }

  const value = draft?.kind === 'text' ? draft.value : (prompt.defaultValue ?? '')

  return (
    <div className="space-y-2">
      <div>
        <p className="text-[13px] font-medium text-foreground">
          {prompt.question}
        </p>
        {prompt.hint ? (
          <p className="mt-1 text-[12px] text-muted-foreground">
            {prompt.hint}
          </p>
        ) : null}
      </div>
      {prompt.multiline ? (
        <Textarea
          value={value}
          disabled={disabled}
          placeholder={prompt.placeholder}
          onChange={(event) =>
            onChange({ kind: 'text', value: event.target.value })
          }
          className="min-h-[72px] text-[13px]"
        />
      ) : (
        <Input
          value={value}
          disabled={disabled}
          placeholder={prompt.placeholder}
          onChange={(event) =>
            onChange({ kind: 'text', value: event.target.value })
          }
          className="h-9 text-[13px]"
        />
      )}
    </div>
  )
}

function ClarifyEnvelopeForm({
  item,
  interactive,
  priorAnswers,
  onSubmitAnswers,
}: {
  item: ClarifyToolEnvelope
  interactive: boolean
  priorAnswers?: ClarifyAnswers | null
  onSubmitAnswers: (answersJson: string) => void
}) {
  const t = useT()
  const { envelope } = item
  const [drafts, setDrafts] = useState<DraftMap>(() =>
    buildInitialDrafts(envelope.prompts, priorAnswers),
  )
  const [submitted, setSubmitted] = useState(!!priorAnswers)
  // Sync guard: React may double-invoke updaters; never submit twice.
  const submittedRef = useRef(!!priorAnswers)

  useEffect(() => {
    setDrafts(buildInitialDrafts(envelope.prompts, priorAnswers))
    const alreadyAnswered = !!priorAnswers
    setSubmitted(alreadyAnswered)
    submittedRef.current = alreadyAnswered
    // Reset only when the tool call identity or hydrated reply changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps -- prompts tied to item.key
  }, [item.key, priorAnswers])

  const disabled = !interactive || submitted
  const canSubmit = canSubmitClarify(envelope.prompts, drafts)
  const confirmOnly =
    envelope.prompts.length === 1 && envelope.prompts[0]?.kind === 'confirm'

  const submitFromDrafts = (nextDrafts: DraftMap) => {
    if (submittedRef.current || !interactive) return
    const answers = buildClarifyAnswers(envelope.prompts, nextDrafts)
    if (!answers) return
    submittedRef.current = true
    setSubmitted(true)
    onSubmitAnswers(serializeClarifyAnswers(answers))
  }

  const handlePromptChange = (prompt: ClarifyPrompt, next: PromptDraft) => {
    if (submittedRef.current) return
    const updated = { ...drafts, [prompt.id]: next }
    setDrafts(updated)
    // Single confirm gates submit immediately on either choice.
    if (confirmOnly && next.kind === 'confirm' && next.confirmed !== null) {
      submitFromDrafts(updated)
    }
  }

  return (
    <div className="rounded-md border border-border bg-muted/20 p-3">
      {envelope.title ? (
        <p className="mb-3 text-[13px] font-semibold text-foreground">
          {envelope.title}
        </p>
      ) : null}
      <div className="space-y-4">
        {envelope.prompts.map((prompt) => (
          <ClarifyPromptView
            key={prompt.id}
            prompt={prompt}
            draft={drafts[prompt.id]}
            disabled={disabled}
            onChange={(next) => handlePromptChange(prompt, next)}
          />
        ))}
      </div>
      {!confirmOnly ? (
        <div className="mt-3 flex justify-end">
          <Button
            type="button"
            size="sm"
            className="h-8 text-[12px]"
            disabled={disabled || !canSubmit}
            onClick={() => submitFromDrafts(drafts)}
          >
            {t('Continue')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}

export function AgentClarifySurfaces({
  message,
  interactive = false,
  followingUserText,
  onSubmitAnswers,
}: {
  message: AssistantMessageLike
  /** When false, forms are shown read-only (historical turns). */
  interactive?: boolean
  /** Next user message text, used to hydrate answered historical forms. */
  followingUserText?: string | null
  onSubmitAnswers?: (answersJson: string) => void
}) {
  const priorAnswers = useMemo(
    () =>
      followingUserText ? parseClarifyAnswers(followingUserText) : null,
    [followingUserText],
  )

  const envelopes = useMemo(() => {
    const turn = buildTurnView(message)
    const tools = turn.toolOrder
      .map((key) => turn.tools[key])
      .filter(Boolean)
      .map((tool) => ({
        ...tool,
        messageId: turn.messageId,
      }))
    return collectClarifyEnvelopes(tools)
  }, [message])

  if (envelopes.length === 0) return null

  return (
    <div className="space-y-3">
      {envelopes.map((item) => (
        <ClarifyEnvelopeForm
          key={item.key}
          item={item}
          interactive={interactive && !!onSubmitAnswers && !priorAnswers}
          priorAnswers={priorAnswers}
          onSubmitAnswers={onSubmitAnswers ?? (() => {})}
        />
      ))}
    </div>
  )
}
