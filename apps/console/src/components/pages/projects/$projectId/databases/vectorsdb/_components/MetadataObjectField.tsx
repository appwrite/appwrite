import { useEffect, useState } from 'react'
import { CodeEditor } from '@/components/global/shared/CodeEditor'
import { Label } from '@/components/ui/label'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type MetadataObjectFieldProps = {
  value: Record<string, unknown> | null
  onChange: (value: Record<string, unknown> | null) => void
  isRequired?: boolean
  disabled?: boolean
  id?: string
  fieldKey?: string
  autoFocus?: boolean
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

export function parseMetadataObjectInput(raw: string): {
  value: Record<string, unknown> | null
  error: string | null
} {
  const trimmed = raw.trim()
  if (!trimmed) {
    return { value: null, error: null }
  }

  try {
    const parsed = JSON.parse(trimmed) as unknown
    if (parsed === null) {
      return { value: null, error: null }
    }
    if (!isPlainObject(parsed)) {
      return {
        value: null,
        error: 'Metadata must be a JSON object',
      }
    }
    if (Object.keys(parsed).length === 0) {
      return { value: null, error: null }
    }
    return { value: parsed, error: null }
  } catch {
    return { value: null, error: 'Invalid metadata JSON' }
  }
}

export function formatMetadataObjectValue(
  value: Record<string, unknown> | null | undefined,
): string {
  if (!value || !isPlainObject(value)) return ''
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return ''
  }
}

export function normalizeMetadataObjectValue(
  value: unknown,
): Record<string, unknown> | null {
  if (value === null || value === undefined || value === '') return null
  if (isPlainObject(value)) {
    return Object.keys(value).length > 0 ? value : null
  }
  if (typeof value === 'string') {
    const { value: parsed, error } = parseMetadataObjectInput(value)
    if (error) return null
    return parsed
  }
  return null
}

export function MetadataObjectField({
  value,
  onChange,
  isRequired = false,
  disabled = false,
  id,
  fieldKey = 'metadata',
  autoFocus = false,
}: MetadataObjectFieldProps) {
  const t = useT()
  const [text, setText] = useState(() => formatMetadataObjectValue(value))
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const next = formatMetadataObjectValue(value)
    const currentParsed = parseMetadataObjectInput(text).value
    const same =
      JSON.stringify(currentParsed ?? null) === JSON.stringify(value ?? null)
    if (!same) {
      setText(next)
      setError(null)
    }
    // Sync only when the stored object identity changes
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value])

  const handleChange = (next: string) => {
    setText(next)
    const { value: parsed, error: parseError } = parseMetadataObjectInput(next)
    if (parseError) {
      setError(parseError)
      return
    }
    setError(null)
    onChange(parsed)
  }

  return (
    <div className="space-y-1.5">
      <Label
        htmlFor={id}
        className="flex items-center gap-1.5 text-[12px] font-medium text-foreground"
      >
        <span>{fieldKey}</span>
        <span className="inline-flex items-center rounded border border-border bg-muted/50 px-1.5 py-px text-[10px] font-medium text-muted-foreground">
          JSON
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
      <CodeEditor
        modelPath={`inmemory://vectorsdb-metadata/${id ?? fieldKey}`}
        value={text}
        onChange={handleChange}
        language="json"
        readOnly={disabled}
        height={160}
        lineNumbers="on"
        minimap={false}
        className={cn(error && 'border-destructive')}
        onEditorMount={
          autoFocus
            ? (editorInstance) => {
                editorInstance.focus()
              }
            : undefined
        }
      />
      <p className="text-[11px] text-muted-foreground">
        {t(
          'Optional JSON object stored with the vector. Use keys like title, source, or tags. Must be an object, not an array or string.',
        )}
      </p>
      {error ? (
        <p className="text-[12px] text-destructive">{t(error)}</p>
      ) : null}
    </div>
  )
}
