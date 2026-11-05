'use client'

import { useEffect, useState, type ReactNode } from 'react'
import {
  CodeBlock,
  getCodeLanguageLabel,
  normalizeCodeBlockContent,
  type CodeBlockLanguage,
} from '@/components/global/shared/CodeBlock'
import { CodeSnippetCopyButton } from '@/components/global/shared/CodeSnippetCopyButton'
import { HorizontalScrollFade } from '@/components/global/shared/HorizontalScrollFade'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useT } from '@/lib/i18n/translate'

const CODE_EXAMPLE_HEADER_LABEL_CLASS =
  'flex h-8 items-center text-[12px] font-medium'

export type ConnectCodeExampleTab = {
  id: string
  label: string
  /** Optional leading icon (e.g. MCP tool logos). */
  icon?: ReactNode
}

type ConnectCodePanelProps = {
  code: string
  language: CodeBlockLanguage
  fixedHeight?: string
  /** Omit outer border when the parent already provides the frame. */
  headless?: boolean
  className?: string
  wrapLines?: boolean
}

/** Syntax-highlighted block - framed or headless inside a parent wrapper. */
export function ConnectCodePanel({
  code,
  language,
  fixedHeight,
  headless = false,
  className,
  wrapLines = false,
}: ConnectCodePanelProps) {
  return (
    <div className={cn('flex min-h-0 min-w-0 w-full flex-1 flex-col', className)}>
      <CodeBlock
        code={code}
        language={language}
        variant={headless ? 'headless' : 'default'}
        showCopy={false}
        fixedHeight={fixedHeight}
        wrapLines={wrapLines}
        className={cn(
          'flex min-h-0 w-full flex-1 flex-col [&>div:last-child]:flex-1 [&>div:last-child]:min-h-0',
          headless && '[&>div:last-child]:border-0',
        )}
      />
    </div>
  )
}

type ConnectCodeExampleProps = {
  code: string
  language: CodeBlockLanguage
  tabs?: ConnectCodeExampleTab[]
  activeTabId?: string
  onTabChange?: (id: string) => void
  /** How to switch between multiple snippets. Docs multicode uses `dropdown`. */
  selectorVariant?: 'tabs' | 'dropdown'
  /** Accessible name for the file/language selector. */
  selectorAriaLabel?: string
  fixedHeight?: string
  className?: string
  /** Extra toolbar actions rendered before the copy button (e.g. Open in SQL editor). */
  actions?: ReactNode
  /** Omit outer border when nested inside another framed container. */
  headless?: boolean
}

/** Toolbar (optional language selector + copy) and code - connect modal SDK code panel. */
export function ConnectCodeExample({
  code,
  language,
  tabs,
  activeTabId,
  onTabChange,
  selectorVariant = 'tabs',
  selectorAriaLabel,
  fixedHeight,
  className,
  actions,
  headless = false,
}: ConnectCodeExampleProps) {
  const t = useT()
  const displayCode = normalizeCodeBlockContent(code)
  const showSelector = Boolean(tabs && tabs.length > 1)
  const selectedTabId = activeTabId ?? tabs?.[0]?.id
  const [copiedTabIds, setCopiedTabIds] = useState(() => new Set<string>())
  const tabsKey = tabs?.map((tab) => `${tab.id}:${tab.label}`).join('|') ?? ''

  useEffect(() => {
    setCopiedTabIds(new Set())
  }, [tabsKey])

  const markActiveTabCopied = () => {
    if (!selectedTabId) return
    setCopiedTabIds((prev) => {
      if (prev.has(selectedTabId)) return prev
      const next = new Set(prev)
      next.add(selectedTabId)
      return next
    })
  }

  return (
    <div
      dir="ltr"
      data-code-example
      className={cn(
        FORCE_LTR_CLASS,
        'flex w-full min-w-0 flex-col overflow-hidden',
        !headless && 'rounded-xl border border-border',
        fixedHeight && 'min-h-0',
        className,
      )}
      style={fixedHeight ? { height: fixedHeight } : undefined}
    >
      <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border px-3 py-2">
        <div className="min-w-0 flex-1">
          {showSelector && selectorVariant === 'dropdown' ? (
            <Select
              value={selectedTabId}
              onValueChange={(value) => onTabChange?.(value)}
            >
              <SelectTrigger
                size="sm"
                className={cn(
                  CODE_EXAMPLE_HEADER_LABEL_CLASS,
                  'min-w-[9rem] max-w-full bg-transparent hover:bg-transparent dark:bg-transparent dark:hover:bg-transparent',
                )}
                aria-label={selectorAriaLabel ?? t('Code language')}
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {tabs!.map((tab) => (
                  <SelectItem
                    key={tab.id}
                    value={tab.id}
                    className="text-[12px]"
                  >
                    {tab.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ) : showSelector ? (
            <HorizontalScrollFade
              className="min-w-0"
              viewportClassName="flex gap-1.5"
              fadeFromClassName="from-background"
            >
              <div
                role="tablist"
                aria-label={selectorAriaLabel ?? t('Select file')}
                className="flex w-max gap-1.5"
              >
                {tabs!.map((tab) => {
                  const isCopied = copiedTabIds.has(tab.id)
                  return (
                    <button
                      key={tab.id}
                      type="button"
                      role="tab"
                      aria-selected={selectedTabId === tab.id}
                      title={
                        isCopied
                          ? `${tab.label} (${t('Copied')})`
                          : tab.label
                      }
                      onClick={() => onTabChange?.(tab.id)}
                      className={cn(
                        'cursor-pointer inline-flex shrink-0 items-center gap-1.5 rounded-md px-2.5 py-1 text-[12px] font-medium transition-[color,background-color,opacity]',
                        selectedTabId === tab.id
                          ? 'bg-muted text-foreground'
                          : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground',
                        isCopied && 'opacity-40',
                      )}
                    >
                      {tab.icon}
                      {tab.label}
                    </button>
                  )
                })}
              </div>
            </HorizontalScrollFade>
          ) : (
            <span className={CODE_EXAMPLE_HEADER_LABEL_CLASS}>
              {getCodeLanguageLabel(language)}
            </span>
          )}
        </div>
        <div className="flex shrink-0 items-center gap-1">
          {actions}
          <CodeSnippetCopyButton
            content={displayCode}
            onCopied={markActiveTabCopied}
          />
        </div>
      </div>
      <ConnectCodePanel
        code={displayCode}
        language={language}
        headless
        fixedHeight={fixedHeight ? '100%' : undefined}
        className="min-h-0 flex-1"
      />
    </div>
  )
}
