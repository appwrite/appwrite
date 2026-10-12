import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type KeyboardEvent,
} from 'react'
import { ChevronDown, ChevronUp, Search, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useT } from '@/lib/i18n/translate'
import { formatCliTerminalSearchLabel } from '@/lib/cli-shell/cli-terminal-search-label'
import type { CliTerminalSearchResults } from '@/lib/cli-shell/cli-terminal-search-label'

type CliTerminalSearchProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  results: CliTerminalSearchResults | null
  onSearch: (query: string, options: { caseSensitive: boolean }) => void
  onFindNext: () => void
  onFindPrevious: () => void
}

export function CliTerminalSearch({
  open,
  onOpenChange,
  results,
  onSearch,
  onFindNext,
  onFindPrevious,
}: CliTerminalSearchProps) {
  const t = useT()
  const [query, setQuery] = useState('')
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (!open) {
      setQuery('')
      return
    }
    const raf = requestAnimationFrame(() => {
      inputRef.current?.focus()
      inputRef.current?.select()
    })
    return () => cancelAnimationFrame(raf)
  }, [open])

  const handleClose = useCallback(() => {
    onOpenChange(false)
    setQuery('')
  }, [onOpenChange])

  const handleSubmit = useCallback(
    (event: { preventDefault: () => void }) => {
      event.preventDefault()
      if (!query.trim()) return
      onSearch(query, { caseSensitive: false })
    },
    [onSearch, query],
  )

  const handleEscape = useCallback(
    (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      event.preventDefault()
      event.stopPropagation()
      handleClose()
    },
    [handleClose],
  )

  const handleInputKeyDown = useCallback(
    (event: KeyboardEvent<HTMLInputElement>) => {
      if (event.key === 'Escape') {
        handleEscape(event)
        return
      }
      if (event.key === 'Enter') {
        event.preventDefault()
        if (event.shiftKey) {
          onFindPrevious()
        } else {
          onFindNext()
        }
      }
    },
    [handleEscape, onFindNext, onFindPrevious],
  )

  const preventInputBlur = useCallback((event: { preventDefault: () => void }) => {
    event.preventDefault()
  }, [])

  const matchLabel = formatCliTerminalSearchLabel(query, results)
  const hasMatches = Boolean(query.trim() && results && results.resultCount > 0)

  if (!open) return null

  return (
    <div
      className="flex h-11 w-full shrink-0 items-center border-b border-border bg-muted/20 px-4 sm:px-6"
      onKeyDown={handleEscape}
    >
      <form
        onSubmit={handleSubmit}
        className="flex w-full min-w-0 items-center gap-2"
      >
        <div className="flex min-w-0 flex-1 items-center">
          <Search className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          <Input
            ref={inputRef}
            value={query}
            onChange={(event) => {
              const next = event.target.value
              setQuery(next)
              onSearch(next, { caseSensitive: false })
            }}
            onKeyDown={handleInputKeyDown}
            placeholder={t('Search output...')}
            className="h-8 min-w-0 flex-1 border-0 bg-transparent px-2 text-[13px] shadow-none focus-visible:border-transparent focus-visible:ring-0 dark:bg-transparent"
            aria-label={t('Search terminal output')}
          />
          {query.trim() ? (
            <div className="flex shrink-0 items-center gap-0.5 ps-1">
              <span
                className="min-w-[4.5rem] shrink-0 px-1 text-end text-[11px] tabular-nums text-muted-foreground"
                aria-live="polite"
              >
                {t(matchLabel)}
              </span>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-7 w-7 shrink-0 text-muted-foreground"
                onMouseDown={preventInputBlur}
                onClick={onFindPrevious}
                disabled={!hasMatches}
                title={t('Previous match')}
                aria-label={t('Previous match')}
              >
                <ChevronUp className="h-3.5 w-3.5" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-7 w-7 shrink-0 text-muted-foreground"
                onMouseDown={preventInputBlur}
                onClick={onFindNext}
                disabled={!hasMatches}
                title={t('Next match')}
                aria-label={t('Next match')}
              >
                <ChevronDown className="h-3.5 w-3.5" />
              </Button>
            </div>
          ) : null}
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-8 w-8 shrink-0 text-muted-foreground"
          onMouseDown={preventInputBlur}
          onClick={handleClose}
          title={t('Close search')}
          aria-label={t('Close search')}
        >
          <X className="h-3.5 w-3.5" />
        </Button>
      </form>
    </div>
  )
}
