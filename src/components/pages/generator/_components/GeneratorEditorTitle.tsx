import { useEffect, useRef, useState } from 'react'
import { ChevronRight, Pencil } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

type GeneratorEditorTitleProps = {
  name: string
  maxLength: number
  isSaving?: boolean
  onChange: (name: string) => void | Promise<void>
}

export function GeneratorEditorTitle({
  name,
  maxLength,
  isSaving = false,
  onChange,
}: GeneratorEditorTitleProps) {
  const [isEditing, setIsEditing] = useState(false)
  const [draft, setDraft] = useState(name)
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (!isEditing) {
      setDraft(name)
    }
  }, [isEditing, name])

  useEffect(() => {
    if (isEditing) {
      inputRef.current?.focus()
      inputRef.current?.select()
    }
  }, [isEditing])

  const commit = async () => {
    const trimmed = draft.trim()
    if (!trimmed || trimmed === name.trim()) {
      setDraft(name)
      setIsEditing(false)
      return
    }

    try {
      await onChange(trimmed.slice(0, maxLength))
      setIsEditing(false)
    } catch {
      setDraft(name)
      setIsEditing(false)
    }
  }

  const cancel = () => {
    setDraft(name)
    setIsEditing(false)
  }

  return (
    <div className="group flex min-w-0 items-center gap-1.5">
      <span className="shrink-0 font-normal text-muted-foreground">Generator</span>
      <ChevronRight className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
      {isEditing ? (
        <Input
          ref={inputRef}
          value={draft}
          maxLength={maxLength}
          disabled={isSaving}
          onChange={(event) => setDraft(event.target.value)}
          onBlur={() => {
            void commit()
          }}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault()
              void commit()
            }
            if (event.key === 'Escape') {
              event.preventDefault()
              cancel()
            }
          }}
          className="h-7 min-w-[8rem] max-w-[min(100%,20rem)] border-0 bg-transparent px-1 text-[17px] font-semibold leading-tight shadow-none focus-visible:ring-1"
          aria-label="Document name"
        />
      ) : (
        <button
          type="button"
          disabled={isSaving}
          onClick={() => setIsEditing(true)}
          onDoubleClick={() => setIsEditing(true)}
          className={cn(
            'flex min-w-0 max-w-full items-center gap-1.5 rounded-sm text-start',
            'hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            isSaving && 'opacity-60',
          )}
          aria-label="Update name"
        >
          <span className="truncate">{name}</span>
          <Pencil className="size-3.5 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100" />
        </button>
      )}
    </div>
  )
}
