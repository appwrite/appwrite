import { useCallback, useRef, useState } from 'react'
import ReactMarkdown from 'react-markdown'
import {
  Bold,
  Code,
  Italic,
  Link as LinkIcon,
  List,
  ListOrdered,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type MarkdownEditorProps = {
  id?: string
  value: string
  onChange: (value: string) => void
  placeholder?: string
  disabled?: boolean
  rows?: number
  className?: string
}

type WrapOptions = {
  before: string
  after: string
  placeholder?: string
  /** When true, apply before/after on each selected line */
  perLine?: boolean
}

function applyWrap(
  value: string,
  selectionStart: number,
  selectionEnd: number,
  options: WrapOptions,
): { next: string; selectionStart: number; selectionEnd: number } {
  const selected = value.slice(selectionStart, selectionEnd)
  const { before, after, placeholder = 'text', perLine } = options

  if (perLine) {
    const block =
      selected.length > 0
        ? selected
            .split('\n')
            .map((line) => (line.trim() ? `${before}${line}` : line))
            .join('\n')
        : `${before}${placeholder}`
    const next =
      value.slice(0, selectionStart) + block + value.slice(selectionEnd)
    return {
      next,
      selectionStart,
      selectionEnd: selectionStart + block.length,
    }
  }

  const content = selected.length > 0 ? selected : placeholder
  const insertion = `${before}${content}${after}`
  const next =
    value.slice(0, selectionStart) + insertion + value.slice(selectionEnd)
  const contentStart = selectionStart + before.length
  return {
    next,
    selectionStart: contentStart,
    selectionEnd: contentStart + content.length,
  }
}

export function MarkdownEditor({
  id,
  value,
  onChange,
  placeholder,
  disabled = false,
  rows = 5,
  className,
}: MarkdownEditorProps) {
  const t = useT()
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const [mode, setMode] = useState<'write' | 'preview'>('write')

  const runWrap = useCallback(
    (options: WrapOptions) => {
      const el = textareaRef.current
      if (!el || disabled) return
      const start = el.selectionStart
      const end = el.selectionEnd
      const result = applyWrap(value, start, end, options)
      onChange(result.next)
      requestAnimationFrame(() => {
        el.focus()
        el.setSelectionRange(result.selectionStart, result.selectionEnd)
      })
    },
    [disabled, onChange, value],
  )

  const tools = [
    {
      label: t('Bold'),
      icon: Bold,
      onClick: () =>
        runWrap({ before: '**', after: '**', placeholder: t('bold text') }),
    },
    {
      label: t('Italic'),
      icon: Italic,
      onClick: () =>
        runWrap({ before: '_', after: '_', placeholder: t('italic text') }),
    },
    {
      label: t('Code'),
      icon: Code,
      onClick: () =>
        runWrap({ before: '`', after: '`', placeholder: t('code') }),
    },
    {
      label: t('Link'),
      icon: LinkIcon,
      onClick: () =>
        runWrap({
          before: '[',
          after: '](https://)',
          placeholder: t('link text'),
        }),
    },
    {
      label: t('Bullet list'),
      icon: List,
      onClick: () =>
        runWrap({
          before: '- ',
          after: '',
          placeholder: t('list item'),
          perLine: true,
        }),
    },
    {
      label: t('Numbered list'),
      icon: ListOrdered,
      onClick: () =>
        runWrap({
          before: '1. ',
          after: '',
          placeholder: t('list item'),
          perLine: true,
        }),
    },
  ] as const

  return (
    <div
      className={cn(
        'overflow-hidden rounded-md border border-border bg-background',
        disabled && 'opacity-60',
        className,
      )}
    >
      <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/30 px-2 py-1">
        <TooltipProvider delayDuration={300}>
          <div className="flex min-w-0 flex-wrap items-center gap-0.5">
            {tools.map((tool) => (
              <Tooltip key={tool.label}>
                <TooltipTrigger asChild>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 w-7 p-0"
                    disabled={disabled || mode === 'preview'}
                    onClick={tool.onClick}
                    aria-label={tool.label}
                  >
                    <tool.icon className="h-3.5 w-3.5" />
                  </Button>
                </TooltipTrigger>
                <TooltipContent className="text-[12px]">
                  {tool.label}
                </TooltipContent>
              </Tooltip>
            ))}
          </div>
        </TooltipProvider>

        <div className="flex shrink-0 items-center rounded-md border border-border bg-background p-0.5">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className={cn(
              'h-6 px-2 text-[11px]',
              mode === 'write' && 'bg-muted text-foreground',
            )}
            disabled={disabled}
            onClick={() => setMode('write')}
          >
            {t('Write')}
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className={cn(
              'h-6 px-2 text-[11px]',
              mode === 'preview' && 'bg-muted text-foreground',
            )}
            disabled={disabled}
            onClick={() => setMode('preview')}
          >
            {t('Preview')}
          </Button>
        </div>
      </div>

      {mode === 'write' ? (
        <Textarea
          ref={textareaRef}
          id={id}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder={placeholder}
          disabled={disabled}
          rows={rows}
          className="min-h-[7.5rem] resize-y rounded-none border-0 bg-transparent px-3 py-2.5 text-[13px] shadow-none focus-visible:ring-0"
        />
      ) : (
        <div className="min-h-[7.5rem] px-3 py-2.5">
          {value.trim() ? (
            <div className="prose prose-sm dark:prose-invert max-w-none text-[13px] text-foreground prose-p:my-2 prose-ul:my-2 prose-ol:my-2 prose-li:my-0.5 prose-headings:mb-2 prose-headings:mt-3 prose-a:text-foreground prose-code:rounded prose-code:bg-muted prose-code:px-1 prose-code:py-0.5 prose-code:before:content-none prose-code:after:content-none">
              <ReactMarkdown>{value}</ReactMarkdown>
            </div>
          ) : (
            <p className="text-[13px] text-muted-foreground">
              {t('Nothing to preview')}
            </p>
          )}
        </div>
      )}
    </div>
  )
}
