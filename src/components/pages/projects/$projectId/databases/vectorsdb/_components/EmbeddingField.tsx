import { useEffect, useMemo, useRef, useState } from 'react'
import { EmbeddingModel } from '@appwrite.io/console'
import { Check, Copy, Cpu } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  DEFAULT_EMBEDDING_DIMENSION_PRESET,
  EMBEDDING_DIMENSION_PRESETS,
  getEmbeddingDimensionPreset,
} from '@/lib/databases/embedding-dimension-presets'
import { createTextEmbeddings } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type EmbeddingFieldMode = 'generate' | 'external'

export type EmbeddingGenerateIntent = {
  text: string
  model: EmbeddingModel
}

type EmbeddingFieldProps = {
  value: number[] | null
  onChange: (value: number[] | null) => void
  /** When set, parent generates the embedding from this text on document save */
  onGenerateIntentChange?: (intent: EmbeddingGenerateIntent | null) => void
  expectedDimension?: number | null
  isRequired?: boolean
  disabled?: boolean
  id?: string
  /** Attribute key shown in the card header (e.g. embeddings) */
  fieldKey?: string
  autoFocus?: boolean
}

function isNumberArray(value: unknown): value is number[] {
  return (
    Array.isArray(value) &&
    value.every((item) => typeof item === 'number' && Number.isFinite(item))
  )
}

export function parseEmbeddingInput(raw: string): {
  embedding: number[] | null
  error: string | null
} {
  const trimmed = raw.trim()
  if (!trimmed) {
    return { embedding: null, error: null }
  }

  try {
    const parsed = JSON.parse(trimmed) as unknown
    if (!isNumberArray(parsed)) {
      return {
        embedding: null,
        error: 'Embedding must be a JSON array of numbers',
      }
    }
    return { embedding: parsed, error: null }
  } catch {
    return { embedding: null, error: 'Invalid embedding JSON' }
  }
}

function formatEmbeddingPreview(embedding: number[]): string {
  if (embedding.length === 0) return '[]'
  const head = embedding.slice(0, 6).map((n) => {
    const abs = Math.abs(n)
    if (abs >= 100 || abs === 0) return String(Number(n.toFixed(2)))
    return String(Number(n.toFixed(4)))
  })
  const suffix = embedding.length > 6 ? ', …' : ''
  return `[${head.join(', ')}${suffix}]`
}

function pickDefaultModel(expectedDimension?: number | null): EmbeddingModel {
  if (typeof expectedDimension === 'number' && expectedDimension > 0) {
    const match = EMBEDDING_DIMENSION_PRESETS.find(
      (preset) => preset.dimension === expectedDimension,
    )
    if (match) return match.id
  }
  return DEFAULT_EMBEDDING_DIMENSION_PRESET
}

export async function resolveEmbeddingFromText(params: {
  projectId: string
  text: string
  model: EmbeddingModel
  expectedDimension?: number | null
}): Promise<number[]> {
  const result = await createTextEmbeddings(params.projectId, [params.text], params.model)
  const first = result.embeddings?.[0]
  if (!first) {
    throw new Error('Failed to generate embedding')
  }
  if (first.error) {
    throw new Error(first.error || 'Failed to generate embedding')
  }
  if (!isNumberArray(first.embedding) || first.embedding.length === 0) {
    throw new Error('Failed to generate embedding')
  }
  if (
    typeof params.expectedDimension === 'number' &&
    params.expectedDimension > 0 &&
    first.embedding.length !== params.expectedDimension
  ) {
    throw new Error('Embedding dimension does not match this collection')
  }
  return first.embedding
}

export function EmbeddingField({
  value,
  onChange,
  onGenerateIntentChange,
  expectedDimension,
  isRequired = false,
  disabled = false,
  id,
  fieldKey = 'embeddings',
  autoFocus = false,
}: EmbeddingFieldProps) {
  const t = useT()
  const hasEmbedding = isNumberArray(value) && value.length > 0
  const [mode, setMode] = useState<EmbeddingFieldMode>(
    hasEmbedding ? 'external' : 'generate',
  )
  const [sourceText, setSourceText] = useState('')
  const [model, setModel] = useState<EmbeddingModel>(() =>
    pickDefaultModel(expectedDimension),
  )
  const [externalText, setExternalText] = useState(() =>
    hasEmbedding ? JSON.stringify(value) : '',
  )
  const [externalError, setExternalError] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  const onGenerateIntentChangeRef = useRef(onGenerateIntentChange)
  onGenerateIntentChangeRef.current = onGenerateIntentChange

  const handleCopyEmbedding = async () => {
    if (!hasEmbedding || !value) return
    try {
      await navigator.clipboard.writeText(JSON.stringify(value))
      setCopied(true)
      toast.success(t('Copied to clipboard'))
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error(t('Failed to copy'))
    }
  }

  const modelOptions = useMemo(() => {
    if (typeof expectedDimension === 'number' && expectedDimension > 0) {
      const matching = EMBEDDING_DIMENSION_PRESETS.filter(
        (preset) => preset.dimension === expectedDimension,
      )
      if (matching.length > 0) return matching
    }
    return EMBEDDING_DIMENSION_PRESETS
  }, [expectedDimension])

  useEffect(() => {
    if (!modelOptions.some((option) => option.id === model)) {
      setModel(modelOptions[0]?.id ?? DEFAULT_EMBEDDING_DIMENSION_PRESET)
    }
  }, [model, modelOptions])

  useEffect(() => {
    if (mode === 'generate') {
      onGenerateIntentChangeRef.current?.({ text: sourceText, model })
    } else {
      onGenerateIntentChangeRef.current?.(null)
    }
  }, [mode, sourceText, model])

  useEffect(() => {
    return () => {
      onGenerateIntentChangeRef.current?.(null)
    }
  }, [])

  useEffect(() => {
    if (!hasEmbedding) {
      if (mode === 'external' && externalText.trim() === '') {
        setExternalText('')
      }
      return
    }
    if (mode === 'external') {
      try {
        const currentParsed = parseEmbeddingInput(externalText).embedding
        if (
          !currentParsed ||
          currentParsed.length !== value!.length ||
          currentParsed.some((n, i) => n !== value![i])
        ) {
          setExternalText(JSON.stringify(value))
          setExternalError(null)
        }
      } catch {
        setExternalText(JSON.stringify(value))
        setExternalError(null)
      }
    }
    // Only sync when the stored embedding identity changes
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hasEmbedding, value])

  const selectedPreset = getEmbeddingDimensionPreset(model)
  const dimensionMismatch =
    typeof expectedDimension === 'number' &&
    expectedDimension > 0 &&
    hasEmbedding &&
    value!.length !== expectedDimension

  const modelDimensionMismatch =
    typeof expectedDimension === 'number' &&
    expectedDimension > 0 &&
    typeof selectedPreset?.dimension === 'number' &&
    selectedPreset.dimension !== expectedDimension

  const handleExternalChange = (next: string) => {
    setExternalText(next)
    const { embedding, error } = parseEmbeddingInput(next)
    if (error) {
      setExternalError(error)
      return
    }
    if (embedding === null) {
      setExternalError(null)
      onChange(null)
      return
    }
    if (
      typeof expectedDimension === 'number' &&
      expectedDimension > 0 &&
      embedding.length !== expectedDimension
    ) {
      setExternalError('Embedding dimension does not match this collection')
      onChange(embedding)
      return
    }
    setExternalError(null)
    onChange(embedding)
  }

  const handleModeChange = (next: EmbeddingFieldMode) => {
    setMode(next)
    if (next === 'generate') {
      // Keep any existing vector until save; new text replaces it on save.
      onGenerateIntentChange?.({ text: sourceText, model })
    } else {
      onGenerateIntentChange?.(null)
      if (hasEmbedding) {
        setExternalText(JSON.stringify(value))
        setExternalError(null)
      }
    }
  }

  const dimensionHint =
    typeof expectedDimension === 'number' && expectedDimension > 0
      ? expectedDimension
      : null

  const pendingGenerateText = mode === 'generate' && sourceText.trim().length > 0

  return (
    <div className="space-y-1.5">
      <div className="space-y-1">
        <Label
          htmlFor={id}
          className="flex items-center gap-1.5 text-[12px] font-medium text-foreground"
        >
          <span>{fieldKey}</span>
          <span className="inline-flex items-center gap-1 rounded border border-border bg-muted/50 px-1.5 py-px text-[10px] font-medium text-muted-foreground">
            <Cpu className="h-3 w-3 shrink-0 opacity-70" />
            Vector
            {dimensionHint != null ? (
              <span className="tabular-nums">· {dimensionHint}</span>
            ) : null}
          </span>
          {isRequired ? (
            <span
              className="text-[12px] font-semibold text-destructive"
              aria-label={t('Required field')}
            >
              *
            </span>
          ) : null}
        </Label>
        <p className="text-[12px] text-muted-foreground">
          {t(
            'Enter text to embed when you save, or edit the vector as a JSON array.',
          )}
        </p>
      </div>

      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="space-y-4 px-4 py-4">
          <ToggleGroup
            type="single"
            variant="outline"
            value={mode}
            onValueChange={(next) => {
              if (next === 'generate' || next === 'external') {
                handleModeChange(next)
              }
            }}
            className="w-full gap-0 rounded-lg border border-border bg-muted p-1"
            aria-label={t('Embedding input mode')}
            disabled={disabled}
          >
            <ToggleGroupItem
              value="generate"
              className="h-8 flex-1 rounded-md border-0 text-[12px] font-medium text-muted-foreground shadow-none first:rounded-md last:rounded-md hover:bg-transparent hover:text-foreground data-[state=on]:bg-background data-[state=on]:text-foreground data-[state=on]:shadow-sm data-[variant=outline]:border-0 data-[variant=outline]:first:border-0"
            >
              {t('From text')}
            </ToggleGroupItem>
            <ToggleGroupItem
              value="external"
              className="h-8 flex-1 rounded-md border-0 text-[12px] font-medium text-muted-foreground shadow-none first:rounded-md last:rounded-md hover:bg-transparent hover:text-foreground data-[state=on]:bg-background data-[state=on]:text-foreground data-[state=on]:shadow-sm data-[variant=outline]:border-0 data-[variant=outline]:first:border-0"
            >
              {t('Vector')}
            </ToggleGroupItem>
          </ToggleGroup>

          {mode === 'generate' ? (
            <div className="space-y-3">
              <div className="space-y-2">
                <Label
                  htmlFor={id ? `${id}-model` : undefined}
                  className="text-[12px] font-medium text-foreground"
                >
                  {t('Embedding model')}
                </Label>
                <Select
                  value={model}
                  onValueChange={(next) => setModel(next as EmbeddingModel)}
                  disabled={disabled}
                >
                  <SelectTrigger
                    id={id ? `${id}-model` : undefined}
                    className="h-9 bg-background text-[13px]"
                  >
                    <SelectValue placeholder={t('Select embedding model')} />
                  </SelectTrigger>
                  <SelectContent>
                    {modelOptions.map((option) => (
                      <SelectItem key={option.id} value={option.id}>
                        <span className="flex items-center gap-1.5">
                          <span>{option.label}</span>
                          <span className="text-muted-foreground">
                            ({option.dimension})
                          </span>
                        </span>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {modelDimensionMismatch ? (
                  <p className="text-[11px] text-muted-foreground">
                    {t(
                      'Selected model dimension does not match this collection',
                    )}
                  </p>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label
                  htmlFor={id}
                  className="text-[12px] font-medium text-foreground"
                >
                  {t('Text to embed')}
                </Label>
                <Textarea
                  id={id}
                  value={sourceText}
                  onChange={(e) => setSourceText(e.target.value)}
                  autoFocus={autoFocus}
                  disabled={disabled}
                  placeholder={t(
                    'Enter the text you want to turn into a vector embedding',
                  )}
                  className="min-h-[96px] resize-y bg-background text-[13px]"
                />
              </div>
            </div>
          ) : (
            <div className="space-y-2">
              <Label
                htmlFor={id}
                className="text-[12px] font-medium text-foreground"
              >
                {t('Vector values')}
              </Label>
              <Textarea
                id={id}
                value={externalText}
                onChange={(e) => handleExternalChange(e.target.value)}
                autoFocus={autoFocus}
                disabled={disabled}
                placeholder={
                  dimensionHint != null
                    ? `[0.12, -0.04, …] (${dimensionHint} ${t('dimensions')})`
                    : '[0.12, -0.04, 0.88, …]'
                }
                className={cn(
                  'min-h-[96px] max-h-[140px] overflow-y-auto resize-y bg-background font-mono text-[12px]',
                  externalError && 'border-destructive',
                )}
              />
              <p className="text-[11px] text-muted-foreground">
                {t(
                  'JSON array of numbers. Edit the current vector or paste a new one.',
                )}
              </p>
              {externalError ? (
                <p className="text-[12px] text-destructive">{t(externalError)}</p>
              ) : null}
            </div>
          )}
        </div>

        <div className="border-t border-border" />

        <div
          className={cn(
            'bg-muted/30 px-4 py-3',
            dimensionMismatch && !pendingGenerateText && 'bg-destructive/5',
          )}
        >
          {pendingGenerateText ? (
            <p className="text-[12px] text-muted-foreground">
              {t('Embedding will be generated when you save this document.')}
            </p>
          ) : hasEmbedding ? (
            <div className="space-y-1.5">
              <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-1.5">
                  <Cpu className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                  <span className="text-[12px] font-medium text-foreground">
                    {value!.length} {t('dimensions')}
                  </span>
                </div>
                <div className="flex shrink-0 items-center gap-0.5">
                  <button
                    type="button"
                    onClick={() => void handleCopyEmbedding()}
                    disabled={disabled}
                    className="flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
                    aria-label={t('Copy embedding')}
                  >
                    {copied ? (
                      <Check className="h-3.5 w-3.5 text-emerald-500" />
                    ) : (
                      <Copy className="h-3.5 w-3.5" />
                    )}
                  </button>
                  {!isRequired && mode === 'external' ? (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-7 px-2 text-[12px] text-muted-foreground hover:text-foreground"
                      disabled={disabled}
                      onClick={() => {
                        onChange(null)
                        setExternalText('')
                        setExternalError(null)
                      }}
                    >
                      {t('Clear')}
                    </Button>
                  ) : null}
                </div>
              </div>
              <p className="max-h-[72px] overflow-y-auto break-all font-mono text-[11px] text-muted-foreground">
                {formatEmbeddingPreview(value!)}
              </p>
              {mode === 'generate' ? (
                <p className="text-[11px] text-muted-foreground">
                  {t('Enter new text above to replace this embedding on save.')}
                </p>
              ) : null}
              {dimensionMismatch ? (
                <p className="text-[12px] text-destructive">
                  {t('Embedding dimension does not match this collection')}
                </p>
              ) : null}
            </div>
          ) : (
            <p className="text-[12px] text-muted-foreground">
              {mode === 'generate'
                ? t('Enter text above to generate an embedding on save.')
                : t('No embedding yet')}
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
