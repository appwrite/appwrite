import { useCallback, useEffect, useRef, useState } from 'react'
import { Search, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatCliTerminalSearchLabel } from '@/lib/cli-shell/cli-terminal-search-label'
import type { CliTerminalSearchResults } from '@/lib/cli-shell/cli-terminal-search-label'

type CliTerminalSearchProps = {
  open: boolean
  fullscreen: boolean
  onOpenChange: (open: boolean) => void
  results: CliTerminalSearchResults | null
  onSearch: (query: string, options: { caseSensitive: boolean }) => void
  onFindNext: () => void
  onFindPrevious: () => void
}

export function CliTerminalSearch({
  open,
  fullscreen,
  onOpenChange,
  results,
  onSearch,
  onFindNext,
  onFindPrevious,
}: CliTerminalSearchProps) {
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

  const matchLabel = formatCliTerminalSearchLabel(query, results)

  if (!open) return null

  return (
    <div
      className="absolute right-0 top-2 z-20 flex items-center gap-2 rounded-lg border border-border bg-background/95 p-2 shadow-md backdrop-blur-sm"
      onKeyDown={(event) => {
        if (event.key === 'Escape' && fullscreen) {
          event.preventDefault()
          handleClose()
        }
      }}
    >
      <form onSubmit={handleSubmit} className="flex items-center gap-2">
        <div className="relative">
          <Search className="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            ref={inputRef}
            value={query}
            onChange={(event) => {
              const next = event.target.value
              setQuery(next)
              onSearch(next, { caseSensitive: false })
            }}
            placeholder="Search output"
            className="h-8 w-44 pl-8 text-[13px] sm:w-52"
            aria-label="Search terminal output"
          />
        </div>
        <span
          className="w-[5.75rem] shrink-0 text-right text-[12px] tabular-nums text-muted-foreground"
          aria-live="polite"
        >
          {matchLabel}
        </span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 text-[12px]"
          onClick={onFindPrevious}
        >
          Prev
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 text-[12px]"
          onClick={onFindNext}
        >
          Next
        </Button>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-8 w-8 text-muted-foreground"
          onClick={handleClose}
          title="Close search"
          aria-label="Close search"
        >
          <X className="h-3.5 w-3.5" />
        </Button>
      </form>
    </div>
  )
}
