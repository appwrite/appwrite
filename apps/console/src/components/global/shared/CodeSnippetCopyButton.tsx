'use client'

import { useState } from 'react'
import { Check, Copy } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

type CodeSnippetCopyButtonProps = {
  content: string
  /** Called after content is successfully written to the clipboard. */
  onCopied?: () => void
}

export function CodeSnippetCopyButton({
  content,
  onCopied,
}: CodeSnippetCopyButtonProps) {
  const t = useT()
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(content)
      setCopied(true)
      onCopied?.()
      toast.success(t('Copied to clipboard'))
      setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error(t('Failed to copy'))
    }
  }

  return (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      className="h-7 shrink-0 gap-1 text-[12px] text-muted-foreground"
      onClick={handleCopy}
    >
      {copied ? (
        <Check className="h-3.5 w-3.5" />
      ) : (
        <Copy className="h-3.5 w-3.5" />
      )}
      {t('Copy')}
    </Button>
  )
}
