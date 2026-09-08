import { assetUrl } from '@/lib/asset-url'
import { useState } from 'react'
import { Check, Copy, ExternalLink } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'

/** Public marketplace listing URL for an app, with copy and open actions. */
export function MarketplaceUrlCard({ appId }: { appId: string }) {
  const t = useT()
  const [copied, setCopied] = useState(false)

  // Org-agnostic share link: /marketplace/$appId resolves the visitor's own
  // organization, so the URL works for people outside this org too.
  const marketplaceUrl = `${
    typeof window === 'undefined'
      ? 'https://cloud.appwrite.io'
      : window.location.origin
  }/marketplace/${appId}`

  const handleCopy = async () => {
    await copyToClipboard('Marketplace URL', marketplaceUrl)
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Marketplace page')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Public listing page for this app in the marketplace.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center gap-2">
          <Input
            value={marketplaceUrl}
            readOnly
            className="font-mono text-[13px]"
            onFocus={(e) => e.currentTarget.select()}
            aria-label={t('Marketplace page')}
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
          <Button
            variant="outline"
            size="sm"
            className="h-9 shrink-0 px-3"
            asChild
          >
            <a
              href={assetUrl(marketplaceUrl)}
              target="_blank"
              rel="noopener noreferrer"
              aria-label={t('Open')}
            >
              <ExternalLink className="h-3.5 w-3.5" />
            </a>
          </Button>
        </div>
      </div>
    </div>
  )
}
