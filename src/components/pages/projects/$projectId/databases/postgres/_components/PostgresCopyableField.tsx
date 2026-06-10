import { useState } from 'react'
import { Check, Copy } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

type PostgresCopyableFieldProps = {
  label: string
  value: string
  mono?: boolean
  masked?: boolean
}

export function PostgresCopyableField({
  label,
  value,
  mono = true,
  masked = false,
}: PostgresCopyableFieldProps) {
  const [copied, setCopied] = useState(false)

  if (!value) return null

  const handleCopy = () => {
    void navigator.clipboard.writeText(value)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div>
      <Label className="mb-1.5 block text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </Label>
      <div className="relative">
        <Input
          value={value}
          readOnly
          type={masked ? 'password' : 'text'}
          className={cn(
            'h-9 border-border bg-muted/30 pr-10 text-[13px] shadow-none',
            mono && 'font-mono',
          )}
        />
        <button
          type="button"
          onClick={handleCopy}
          className="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md transition-colors hover:bg-accent"
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
  )
}
