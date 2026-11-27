import { useState } from 'react'
import { Check, Copy } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { ZERO_AGENT_PROMPT, ZERO_COMMAND } from '@/lib/zero/marketing-content'

/** The install command with copying as the primary action, sized to sit beside hero buttons. */
export function ZeroCommandBar({ className }: { className?: string }) {
  const t = useT()
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(ZERO_COMMAND)
      setCopied(true)
      toast.success(t('Copied to clipboard'))
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error(t('Failed to copy'))
    }
  }

  return (
    <div
      dir="ltr"
      className={cn(
        'flex h-11 items-center gap-3 rounded-lg border border-border bg-card/70 ps-4 pe-1 backdrop-blur-sm',
        className,
      )}
    >
      <code className="whitespace-nowrap font-mono text-[14px] text-foreground">
        <span className="select-none text-muted-foreground">$ </span>
        {ZERO_COMMAND}
      </code>
      <Button
        type="button"
        variant="brandCta"
        className="h-9 gap-1.5 px-3.5 text-[14px]"
        onClick={() => void handleCopy()}
        {...analyticsAttrs('zero-copy-command')}
      >
        {copied ? (
          <Check className="size-3.5" aria-hidden />
        ) : (
          <Copy className="size-3.5" aria-hidden />
        )}
        {copied ? t('Copied') : t('Copy')}
      </Button>
    </div>
  )
}

/** Copies the prompt that tells an agent to use Zero. */
export function ZeroPromptButton({ className }: { className?: string }) {
  const t = useT()

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(ZERO_AGENT_PROMPT)
      toast.success(t('Prompt copied to clipboard'))
    } catch {
      toast.error(t('Failed to copy prompt'))
    }
  }

  return (
    <Button
      type="button"
      variant="outline"
      size="lg"
      className={cn('h-11 bg-background/60 text-[14px]', className)}
      onClick={() => void handleCopy()}
      {...analyticsAttrs('zero-copy-prompt')}
    >
      {t('Copy agent prompt')}
    </Button>
  )
}
