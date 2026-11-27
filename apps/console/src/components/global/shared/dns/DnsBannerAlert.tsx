import {
  AlertCircle,
  AlertTriangle,
  CheckCircle2,
  ExternalLink,
  Info,
} from 'lucide-react'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

export const DNS_PROVIDERS_LINK = '/docs/advanced/platform/custom-domains'

export type DnsBannerTone = 'info' | 'error' | 'warning' | 'success'

const DNS_BANNER_TONES: Record<
  DnsBannerTone,
  { className: string; Icon: typeof Info }
> = {
  info: {
    className:
      'border-blue-500/30 bg-blue-500/5 [&>svg]:text-blue-600 dark:[&>svg]:text-blue-400',
    Icon: Info,
  },
  error: {
    className:
      'border-red-500/30 bg-red-500/10 [&>svg]:text-red-600 dark:[&>svg]:text-red-400',
    Icon: AlertTriangle,
  },
  warning: {
    className:
      'border-amber-500/30 bg-amber-500/10 [&>svg]:text-amber-600 dark:[&>svg]:text-amber-400',
    Icon: AlertCircle,
  },
  success: {
    className:
      'border-emerald-500/30 bg-emerald-500/5 [&>svg]:text-emerald-600 dark:[&>svg]:text-emerald-400',
    Icon: CheckCircle2,
  },
}

export type DnsBanner = {
  tone: DnsBannerTone
  /** Bold lead-in, e.g. "CNAME record" */
  prefix?: string
  text: string
  /** The DNS checks passed; what is left is the certificate. */
  dnsSetUp?: boolean
}

/** One problem, or the next step, above the records. */
export function DnsBannerAlert({ banner }: { banner: DnsBanner }) {
  const t = useT()
  const { className, Icon } = DNS_BANNER_TONES[banner.tone]
  return (
    <Alert variant="default" className={className}>
      <Icon className="h-4 w-4" />
      <AlertDescription className="flex w-full items-start justify-between gap-4 text-[13px]">
        <p className="min-w-0 break-words text-muted-foreground">
          {banner.prefix && (
            <span className="font-medium text-foreground">
              {banner.prefix}:{' '}
            </span>
          )}
          {banner.text}
        </p>
        {banner.tone !== 'success' && (
          <DocsRouteLink
            href={DNS_PROVIDERS_LINK}
            className="inline-flex shrink-0 items-center gap-1 font-medium text-foreground hover:underline"
          >
            <ExternalLink className="h-3.5 w-3.5" />
            {t('Docs')}
          </DocsRouteLink>
        )}
      </AlertDescription>
    </Alert>
  )
}
