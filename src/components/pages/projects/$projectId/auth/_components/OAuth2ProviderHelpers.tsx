import { useEffect, useMemo, useState } from 'react'
import {
  BrainCircuit,
  Check,
  ChevronDown,
  Copy,
  Download,
  Image,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import {
  buildOAuth2SignInPrompt,
  fetchOAuth2ProviderIconSvg,
} from '@/lib/oauth2/sign-in-prompt'
import { getOAuth2ProviderIconPath } from '@/lib/oauth2/provider-display'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { downloadAsFile } from '@/lib/utils/database-schema-export'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type OAuth2ProviderHelpersProps = {
  projectId: string
  endpoint: string
  providerId: string
  providerName: string
}

export function OAuth2ProviderHelpers({
  projectId,
  endpoint,
  providerId,
  providerName,
}: OAuth2ProviderHelpersProps) {
  const t = useT()
  const [copiedIcon, setCopiedIcon] = useState(false)
  const [copiedPrompt, setCopiedPrompt] = useState(false)
  const [copyBusy, setCopyBusy] = useState(false)
  const [downloadBusy, setDownloadBusy] = useState(false)
  const [iconSvg, setIconSvg] = useState<string | undefined>()

  const iconPath = getOAuth2ProviderIconPath(providerId)
  const iconFilename =
    iconPath.split('/').pop()?.replace(/\.svg$/i, '') || providerId

  useEffect(() => {
    let cancelled = false
    setIconSvg(undefined)
    void fetchOAuth2ProviderIconSvg(providerId)
      .then((svg) => {
        if (!cancelled) setIconSvg(svg)
      })
      .catch(() => {
        if (!cancelled) setIconSvg(undefined)
      })
    return () => {
      cancelled = true
    }
  }, [providerId])

  const prompt = useMemo(
    () =>
      buildOAuth2SignInPrompt({
        projectId,
        endpoint,
        providerId,
        providerName,
        iconSvg,
      }),
    [projectId, endpoint, providerId, providerName, iconSvg],
  )

  const handleCopyIcon = async () => {
    if (copyBusy) return
    setCopyBusy(true)
    try {
      const svg = iconSvg ?? (await fetchOAuth2ProviderIconSvg(providerId))
      const ok = await copyToClipboard(t('Provider icon SVG'), svg, {
        showToast: true,
      })
      if (ok) {
        setCopiedIcon(true)
        setTimeout(() => setCopiedIcon(false), 2000)
      }
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to copy provider icon')))
    } finally {
      setCopyBusy(false)
    }
  }

  const handleDownloadIcon = async () => {
    if (downloadBusy) return
    setDownloadBusy(true)
    try {
      const svg = iconSvg ?? (await fetchOAuth2ProviderIconSvg(providerId))
      downloadAsFile(svg, `${iconFilename}.svg`, 'image/svg+xml')
      toast.success(t('Provider icon downloaded'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to download provider icon')))
    } finally {
      setDownloadBusy(false)
    }
  }

  const handleCopyPrompt = async () => {
    const ok = await copyToClipboard(t('AI prompt'), prompt, {
      showToast: false,
    })
    if (ok) {
      toast.success(t('Prompt copied to clipboard'))
      setCopiedPrompt(true)
      setTimeout(() => setCopiedPrompt(false), 2000)
    } else {
      toast.error(t('Failed to copy prompt'))
    }
  }

  return (
    <div className="space-y-4">
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Provider icon')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Copy or download this provider SVG to use on your OAuth sign-in button.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
              <img
                src={iconPath}
                alt=""
                className={cn('h-5 w-5', PUBLIC_ICON_MUTED_CLASSES)}
                onError={(e) => {
                  e.currentTarget.src = '/icons/empty.svg'
                }}
              />
            </div>
            <p className="text-[13px] font-medium text-foreground">
              {providerName}
            </p>
          </div>
          <p className="text-[12px] text-muted-foreground font-mono break-all">
            {iconPath}
          </p>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => void handleCopyIcon()}
              disabled={copyBusy || downloadBusy}
            >
              {copiedIcon ? (
                <>
                  <Check className="me-1.5 h-3.5 w-3.5 text-emerald-500" />
                  {t('Copied')}
                </>
              ) : (
                <>
                  <Image className="me-1.5 h-3.5 w-3.5" />
                  {t('Copy SVG')}
                </>
              )}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => void handleDownloadIcon()}
              disabled={copyBusy || downloadBusy}
            >
              <Download className="me-1.5 h-3.5 w-3.5" />
              {t('Download')}
            </Button>
          </div>
        </div>
      </div>

      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Sign-in with')} {providerName} {t('prompt')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Copy a ready-made prompt for your coding agent to add this provider sign-in button to your app.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-start gap-3 min-w-0">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
              <BrainCircuit className="h-5 w-5 text-muted-foreground" />
            </div>
            <p className="text-[12px] text-muted-foreground leading-relaxed">
              {t(
                'Includes project ID, endpoint, provider ID, and the icon SVG when available.',
              )}
            </p>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[13px] shrink-0"
            onClick={() => void handleCopyPrompt()}
            disabled={!prompt.trim()}
          >
            {copiedPrompt ? (
              <>
                <Check className="me-1.5 h-3.5 w-3.5 text-emerald-500" />
                {t('Copied')}
              </>
            ) : (
              <>
                <Copy className="me-1.5 h-3.5 w-3.5" />
                {t('Copy prompt')}
              </>
            )}
          </Button>
        </div>

        <Collapsible defaultOpen={false}>
          <CollapsibleTrigger className="group flex w-full cursor-pointer items-center justify-between gap-3 border-t border-border px-6 py-3 text-start transition-colors hover:bg-muted/30 data-[state=open]:bg-muted/20">
            <span className="text-[13px] font-medium text-foreground">
              {t('View prompt')}
            </span>
            <ChevronDown className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
          </CollapsibleTrigger>
          <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-accordion-up data-[state=open]:animate-accordion-down">
            <div className="border-t border-border px-6 py-4">
              <ConnectCodeExample
                code={prompt}
                language="markdown"
                fixedHeight="min(40dvh, 420px)"
              />
            </div>
          </CollapsibleContent>
        </Collapsible>
      </div>
    </div>
  )
}
