import { Fragment, useEffect, useMemo, useState } from 'react'
import {
  Check,
  ChevronDown,
  Copy,
  Download,
  Image,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ConnectCodePanel } from '@/components/global/shared/ConnectCodeExample'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import {
  buildOAuth2SignInPrompt,
  fetchOAuth2ProviderIconSvg,
  OAUTH2_SIGN_IN_PROMPT_SDKS,
  svgMarkupToPngBlob,
  type OAuth2SignInPromptSdkId,
} from '@/lib/oauth2/sign-in-prompt'
import { getOAuth2ProviderIconPath } from '@/lib/oauth2/provider-display'
import { copyToClipboard } from '@/lib/utils/context-menu'
import {
  downloadAsFile,
  downloadBlob,
} from '@/lib/utils/database-schema-export'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type OAuth2ProviderHelpersProps = {
  projectId: string
  endpoint: string
  providerId: string
  providerName: string
}

type DownloadFormat = 'svg' | 'png'

export function OAuth2ProviderHelpers({
  projectId,
  endpoint,
  providerId,
  providerName,
}: OAuth2ProviderHelpersProps) {
  const t = useT()
  const [copiedIcon, setCopiedIcon] = useState(false)
  const [copiedPromptSdk, setCopiedPromptSdk] =
    useState<OAuth2SignInPromptSdkId | null>(null)
  const [copyBusy, setCopyBusy] = useState(false)
  const [downloadBusy, setDownloadBusy] = useState<DownloadFormat | null>(null)
  const [iconSvg, setIconSvg] = useState<string | undefined>()
  const [viewingSdk, setViewingSdk] = useState<OAuth2SignInPromptSdkId | null>(
    null,
  )

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

  const promptsBySdk = useMemo(() => {
    const entries = OAUTH2_SIGN_IN_PROMPT_SDKS.map((sdk) => [
      sdk.id,
      buildOAuth2SignInPrompt({
        projectId,
        endpoint,
        providerId,
        providerName,
        iconSvg,
        sdk: sdk.id,
      }),
    ] as const)
    return Object.fromEntries(entries) as Record<
      OAuth2SignInPromptSdkId,
      string
    >
  }, [projectId, endpoint, providerId, providerName, iconSvg])

  const handleCopyIcon = async () => {
    if (copyBusy || downloadBusy) return
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

  const handleDownloadIcon = async (format: DownloadFormat) => {
    if (copyBusy || downloadBusy) return
    setDownloadBusy(format)
    try {
      const svg = iconSvg ?? (await fetchOAuth2ProviderIconSvg(providerId))
      if (format === 'svg') {
        downloadAsFile(svg, `${iconFilename}.svg`, 'image/svg+xml')
      } else {
        const pngBlob = await svgMarkupToPngBlob(svg)
        downloadBlob(pngBlob, `${iconFilename}.png`)
      }
      toast.success(t('Provider icon downloaded'))
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to download provider icon')))
    } finally {
      setDownloadBusy(null)
    }
  }

  const handleCopyPrompt = async (sdkId: OAuth2SignInPromptSdkId) => {
    const prompt = promptsBySdk[sdkId]
    const ok = await copyToClipboard(t('AI prompt'), prompt, {
      showToast: false,
    })
    if (ok) {
      toast.success(t('Prompt copied to clipboard'))
      setCopiedPromptSdk(sdkId)
      setTimeout(() => setCopiedPromptSdk(null), 2000)
    } else {
      toast.error(t('Failed to copy prompt'))
    }
  }

  const actionsDisabled = copyBusy || downloadBusy !== null

  return (
    <div className="space-y-4">
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Provider icon')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Copy or download this provider icon to use on your OAuth sign-in button.',
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
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:flex-wrap">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => void handleCopyIcon()}
              disabled={actionsDisabled}
            >
              <span className="relative inline-flex items-center justify-center">
                <span
                  className={cn(
                    'inline-flex items-center',
                    copiedIcon && 'invisible',
                  )}
                >
                  <Image className="me-1.5 h-3.5 w-3.5" />
                  {t('Copy SVG')}
                </span>
                <span
                  className={cn(
                    'absolute inset-0 inline-flex items-center justify-center',
                    !copiedIcon && 'invisible',
                  )}
                  aria-hidden={!copiedIcon}
                >
                  <Check className="me-1.5 h-3.5 w-3.5 text-emerald-500" />
                  {t('Copied')}
                </span>
              </span>
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => void handleDownloadIcon('svg')}
              disabled={actionsDisabled}
            >
              <Download className="me-1.5 h-3.5 w-3.5" />
              {t('Download SVG')}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => void handleDownloadIcon('png')}
              disabled={actionsDisabled}
            >
              <Download className="me-1.5 h-3.5 w-3.5" />
              {t('Download PNG')}
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
        <div className="border-t border-border overflow-hidden">
          <Table className="table-fixed w-full">
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  {t('Client SDK')}
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[160px]" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {OAUTH2_SIGN_IN_PROMPT_SDKS.map((sdk) => {
                const isCopied = copiedPromptSdk === sdk.id
                const isViewing = viewingSdk === sdk.id
                return (
                  <Fragment key={sdk.id}>
                    <TableRow>
                      <TableCell className="px-4 py-3">
                        <span className="text-[13px] font-medium text-foreground">
                          {t(sdk.label)}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-right">
                        <div className="inline-flex items-center justify-end gap-2">
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 text-[12px]"
                            onClick={() => void handleCopyPrompt(sdk.id)}
                            disabled={!promptsBySdk[sdk.id]?.trim()}
                          >
                            <span className="relative inline-flex items-center justify-center">
                              <span
                                className={cn(
                                  'inline-flex items-center',
                                  isCopied && 'invisible',
                                )}
                              >
                                <Copy className="me-1.5 h-3.5 w-3.5" />
                                {t('Copy')}
                              </span>
                              <span
                                className={cn(
                                  'absolute inset-0 inline-flex items-center justify-center',
                                  !isCopied && 'invisible',
                                )}
                                aria-hidden={!isCopied}
                              >
                                <Check className="me-1.5 h-3.5 w-3.5 text-emerald-500" />
                                {t('Copied')}
                              </span>
                            </span>
                          </Button>
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 w-8 p-0"
                            aria-label={isViewing ? t('Hide') : t('View')}
                            aria-expanded={isViewing}
                            onClick={() =>
                              setViewingSdk((current) =>
                                current === sdk.id ? null : sdk.id,
                              )
                            }
                          >
                            <ChevronDown
                              className={cn(
                                'h-3.5 w-3.5 text-muted-foreground transition-transform',
                                isViewing && 'rotate-180',
                              )}
                            />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                    {isViewing ? (
                      <TableRow className="hover:bg-transparent">
                        <TableCell
                          colSpan={2}
                          className="max-w-0 border-b border-border px-4 pb-4 pt-3"
                        >
                          <div className="min-w-0 max-h-[min(40dvh,420px)] overflow-auto">
                            <ConnectCodePanel
                              code={promptsBySdk[sdk.id]}
                              language="markdown"
                              headless
                              wrapLines
                            />
                          </div>
                        </TableCell>
                      </TableRow>
                    ) : null}
                  </Fragment>
                )
              })}
            </TableBody>
          </Table>
        </div>
      </div>
    </div>
  )
}
