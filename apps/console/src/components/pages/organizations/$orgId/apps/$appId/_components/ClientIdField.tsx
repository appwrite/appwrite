import { useState } from 'react'
import { Check, Copy } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'

/** Read-only OAuth client ID (the app's $id) with a copy button. */
export function ClientIdField({ clientId }: { clientId: string }) {
  const t = useT()
  const [copied, setCopied] = useState(false)

  const handleCopy = async () => {
    await copyToClipboard('Client ID', clientId)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="space-y-2">
      <Label htmlFor="app-oauth-client-id">{t('Client ID')}</Label>
      <div className="flex items-center gap-2">
        <Input
          id="app-oauth-client-id"
          value={clientId}
          readOnly
          className="font-mono text-[13px]"
          onFocus={(e) => e.currentTarget.select()}
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 shrink-0 px-3"
          onClick={() => void handleCopy()}
          aria-label={t('Copy')}
        >
          {copied ? (
            <Check className="h-3.5 w-3.5 text-emerald-500" />
          ) : (
            <Copy className="h-3.5 w-3.5" />
          )}
        </Button>
      </div>
    </div>
  )
}
