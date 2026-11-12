import { useState, type ReactNode } from 'react'
import { Check, Copy, Eye, EyeOff } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { useT } from '@/lib/i18n/translate'

type PostgresCopyableFieldProps = {
  label: string
  value: string
  mono?: boolean
  masked?: boolean
  isLoading?: boolean
  labelAction?: ReactNode
}

export function PostgresCopyableField({
  label,
  value,
  mono = true,
  masked = false,
  isLoading = false,
  labelAction,
}: PostgresCopyableFieldProps) {
  const t = useT()
  const [copied, setCopied] = useState(false)
  const [revealed, setRevealed] = useState(false)

  if (isLoading) {
    return (
      <div>
        <Skeleton className="mb-1.5 h-3 w-16" />
        <Skeleton className="h-9 w-full" />
      </div>
    )
  }

  if (!value) return null

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)
      toast.success(`${label} ${t('copied')}`)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error(t('Failed to copy'))
    }
  }

  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between gap-2">
        <Label className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
          {label}
        </Label>
        {labelAction}
      </div>
      <div className="relative">
        <Input
          value={value}
          readOnly
          type={masked && !revealed ? 'password' : 'text'}
          className={cn(
            'h-9 border-border bg-muted/30 text-[13px] shadow-none focus-visible:ring-inset',
            mono && 'font-mono',
            masked ? 'pe-16' : 'pe-10',
          )}
        />
        <div className="absolute end-1 top-1/2 flex -translate-y-1/2 items-center gap-0.5">
          {masked ? (
            <button
              type="button"
              onClick={() => setRevealed((current) => !current)}
              className="flex h-7 w-7 items-center justify-center rounded-md transition-colors hover:bg-accent"
              aria-label={revealed ? t('Hide password') : t('Show password')}
            >
              {revealed ? (
                <EyeOff className="h-4 w-4 text-muted-foreground" />
              ) : (
                <Eye className="h-4 w-4 text-muted-foreground" />
              )}
            </button>
          ) : null}
          <button
            type="button"
            onClick={() => void handleCopy()}
            className="flex h-7 w-7 items-center justify-center rounded-md transition-colors hover:bg-accent"
            aria-label={`Copy ${label}`}
          >
            {copied ? (
              <Check className="h-4 w-4 text-emerald-500" />
            ) : (
              <Copy className="h-4 w-4 text-muted-foreground" />
            )}
          </button>
        </div>
      </div>
    </div>
  )
}
