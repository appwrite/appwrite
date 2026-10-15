'use client'

import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { Check, ChevronRight, Copy } from 'lucide-react'
import { toast } from 'sonner'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type JsonValue =
  | null
  | boolean
  | number
  | string
  | JsonValue[]
  | { [key: string]: JsonValue }

function tryParseJson(payload: string): JsonValue | null {
  try {
    return JSON.parse(payload) as JsonValue
  } catch {
    return null
  }
}

function collapsedPreview(value: JsonValue[] | Record<string, JsonValue>): string {
  if (Array.isArray(value)) {
    return `[${value.length} item${value.length === 1 ? '' : 's'}]`
  }
  const keys = Object.keys(value)
  return `{${keys.length} key${keys.length === 1 ? '' : 's'}}`
}

function primitiveClassName(value: JsonValue): string {
  if (value === null) return 'text-muted-foreground'
  if (typeof value === 'boolean') return 'text-violet-600 dark:text-violet-400'
  if (typeof value === 'number') return 'text-amber-700 dark:text-amber-400'
  return 'text-emerald-700 dark:text-emerald-400'
}

function renderPrimitive(value: JsonValue): string {
  if (value === null) return 'null'
  if (typeof value === 'string') return JSON.stringify(value)
  return String(value)
}

function JsonCopyButton({ content }: { content: string }) {
  const t = useT()
  const [copied, setCopied] = useState(false)

  const handleCopy = useCallback(() => {
    void navigator.clipboard.writeText(content)
    setCopied(true)
    toast.success(t('Copied to clipboard'))
    window.setTimeout(() => setCopied(false), 2000)
  }, [content, t])

  return (
    <button
      type="button"
      onClick={handleCopy}
      className="flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
      aria-label={t('Copy JSON')}
    >
      {copied ? (
        <Check className="h-3.5 w-3.5 text-emerald-500" />
      ) : (
        <Copy className="h-3.5 w-3.5" />
      )}
    </button>
  )
}

function CollapseToggle({
  collapsed,
  onToggle,
  label,
}: {
  collapsed: boolean
  onToggle: () => void
  label: string
}) {
  const t = useT()
  return (
    <button
      type="button"
      onClick={(event) => {
        event.stopPropagation()
        onToggle()
      }}
      className="me-1 inline-flex h-4 w-4 shrink-0 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground"
      aria-label={collapsed ? `${t('Expand')} ${label}` : `${t('Collapse')} ${label}`}
      aria-expanded={!collapsed}
    >
      <ChevronRight
        className={cn(
          'h-3 w-3 transition-transform duration-150',
          !collapsed && 'rotate-90',
        )}
      />
    </button>
  )
}

function JsonProperty({
  name,
  value,
  path,
  depth,
  isLast,
}: {
  name?: string | number
  value: JsonValue
  path: string
  depth: number
  isLast: boolean
}) {
  const [collapsed, setCollapsed] = useState(false)
  const comma = isLast ? '' : ','

  const nameLabel =
    name === undefined ? null : typeof name === 'number' ? (
      <span className="text-muted-foreground">{name}</span>
    ) : (
      <span className="text-foreground/90">{JSON.stringify(name)}</span>
    )

  if (Array.isArray(value)) {
    if (value.length === 0) {
      return (
        <div className="whitespace-pre">
          {nameLabel ? (
            <>
              {nameLabel}
              <span className="text-muted-foreground">: </span>
            </>
          ) : null}
          <span className="text-muted-foreground">[]{comma}</span>
        </div>
      )
    }

    return (
      <div>
        <div className="flex items-start whitespace-pre">
          <CollapseToggle
            collapsed={collapsed}
            onToggle={() => setCollapsed((current) => !current)}
            label={String(name ?? 'array')}
          />
          {nameLabel ? (
            <>
              {nameLabel}
              <span className="text-muted-foreground">: </span>
            </>
          ) : null}
          {collapsed ? (
            <span className="text-muted-foreground">
              {collapsedPreview(value)}
              {comma}
            </span>
          ) : (
            <span className="text-muted-foreground">[</span>
          )}
        </div>
        {!collapsed ? (
          <>
            {value.map((item, index) => (
              <div
                key={`${path}[${index}]`}
                style={{ paddingInlineStart: (depth + 1) * 14 }}
              >
                <JsonProperty
                  value={item}
                  path={`${path}[${index}]`}
                  depth={depth + 1}
                  isLast={index === value.length - 1}
                />
              </div>
            ))}
            <div style={{ paddingInlineStart: depth * 14 }}>
              <span className="text-muted-foreground">]{comma}</span>
            </div>
          </>
        ) : null}
      </div>
    )
  }

  if (value !== null && typeof value === 'object') {
    const entries = Object.entries(value)

    if (entries.length === 0) {
      return (
        <div className="whitespace-pre">
          {nameLabel ? (
            <>
              {nameLabel}
              <span className="text-muted-foreground">: </span>
            </>
          ) : null}
          <span className="text-muted-foreground">{`{}${comma}`}</span>
        </div>
      )
    }

    return (
      <div>
        <div className="flex items-start whitespace-pre">
          <CollapseToggle
            collapsed={collapsed}
            onToggle={() => setCollapsed((current) => !current)}
            label={String(name ?? 'object')}
          />
          {nameLabel ? (
            <>
              {nameLabel}
              <span className="text-muted-foreground">: </span>
            </>
          ) : null}
          {collapsed ? (
            <span className="text-muted-foreground">
              {collapsedPreview(value)}
              {comma}
            </span>
          ) : (
            <span className="text-muted-foreground">{'{'}</span>
          )}
        </div>
        {!collapsed ? (
          <>
            {entries.map(([key, child], index) => (
              <div
                key={`${path}.${key}`}
                style={{ paddingInlineStart: (depth + 1) * 14 }}
              >
                <JsonProperty
                  name={key}
                  value={child}
                  path={`${path}.${key}`}
                  depth={depth + 1}
                  isLast={index === entries.length - 1}
                />
              </div>
            ))}
            <div style={{ paddingInlineStart: depth * 14 }}>
              <span className="text-muted-foreground">{`}${comma}`}</span>
            </div>
          </>
        ) : null}
      </div>
    )
  }

  return (
    <div className="whitespace-pre-wrap break-all">
      {nameLabel ? (
        <>
          {nameLabel}
          <span className="text-muted-foreground">: </span>
        </>
      ) : null}
      <span className={primitiveClassName(value)}>{renderPrimitive(value)}</span>
      <span className="text-muted-foreground">{comma}</span>
    </div>
  )
}

function JsonRoot({ value }: { value: JsonValue }) {
  if (Array.isArray(value)) {
    return (
      <JsonProperty value={value} path="$" depth={0} isLast />
    )
  }

  if (value !== null && typeof value === 'object') {
    const entries = Object.entries(value)
    return (
      <>
        <span className="text-muted-foreground">{'{'}</span>
        {entries.map(([key, child], index) => (
          <div key={key} style={{ paddingInlineStart: 14 }}>
            <JsonProperty
              name={key}
              value={child}
              path={`$.${key}`}
              depth={1}
              isLast={index === entries.length - 1}
            />
          </div>
        ))}
        <span className="text-muted-foreground">{'}'}</span>
      </>
    )
  }

  return (
    <span className={primitiveClassName(value)}>{renderPrimitive(value)}</span>
  )
}

type CollapsibleJsonViewProps = {
  payload: string
  footer?: ReactNode
  className?: string
}

export function CollapsibleJsonView({
  payload,
  footer,
  className,
}: CollapsibleJsonViewProps) {
  const parsed = useMemo(() => tryParseJson(payload), [payload])

  if (parsed === null) {
    return (
      <div
        dir="ltr"
        data-code-example
        className={cn(FORCE_LTR_CLASS, 'overflow-hidden rounded-md border border-border', className)}
      >
        <CodeBlock
          code={payload}
          language="json"
          variant="headless"
          copyInside
          wrapLines
        />
        {footer}
      </div>
    )
  }

  return (
    <div
      dir="ltr"
      data-code-example
      className={cn(FORCE_LTR_CLASS, 'overflow-hidden rounded-md border border-border', className)}
    >
      <div className="relative bg-muted/10">
        <div className="absolute end-2 top-2 z-10">
          <JsonCopyButton content={payload} />
        </div>
        <div
          className="overflow-x-auto px-3 py-3 pe-10 font-mono text-[12px] leading-relaxed"
          onClick={(event) => event.stopPropagation()}
        >
          <JsonRoot value={parsed} />
        </div>
      </div>
      {footer}
    </div>
  )
}
