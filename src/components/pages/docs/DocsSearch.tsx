'use client'

import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { FileText } from 'lucide-react'
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { getDocsSearchHref, searchDocs } from '@/lib/docs/search'

type DocsSearchProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function DocsSearch({ open, onOpenChange }: DocsSearchProps) {
  const navigate = useNavigate()
  const [query, setQuery] = useState('')

  useEffect(() => {
    if (!open) {
      setQuery('')
    }
  }, [open])

  const results = useMemo(() => searchDocs(query), [query])

  const handleSelect = (slug: string) => {
    onOpenChange(false)
    const href = getDocsSearchHref(slug)
    if (href === '/docs') {
      navigate({ to: '/docs/' })
      return
    }
    navigate({ to: '/docs/$', params: { _splat: slug } })
  }

  return (
    <CommandDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Search documentation"
      description="Search Appwrite documentation pages"
      className="sm:max-w-xl"
      shouldFilter={false}
    >
      <CommandInput
        placeholder="Search documentation..."
        value={query}
        onValueChange={setQuery}
      />
      <CommandList className="max-h-[min(60dvh,420px)]">
        <CommandEmpty>No documentation pages found.</CommandEmpty>
        {results.length > 0 ? (
          <CommandGroup heading="Pages">
            {results.map((result) => (
              <CommandItem
                key={result.slug || 'docs-home'}
                value={`${result.title} ${result.slug} ${result.description}`}
                onSelect={() => handleSelect(result.slug)}
                className="items-start gap-3 py-3"
              >
                <FileText className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13px] font-medium text-foreground">
                    {result.title}
                  </p>
                  <p className="mt-1 line-clamp-2 text-[12px] text-muted-foreground">
                    {result.description}
                  </p>
                </div>
              </CommandItem>
            ))}
          </CommandGroup>
        ) : null}
      </CommandList>
    </CommandDialog>
  )
}
