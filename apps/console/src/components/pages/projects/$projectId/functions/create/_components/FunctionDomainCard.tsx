/**
 * Function Domain Card
 *
 * Cloud: choose between Edge (`_APP_DOMAIN_SITES`, e.g. .appwrite.network)
 * and Region (`_APP_DOMAIN_FUNCTIONS`, e.g. .<region>.appwrite.run) endpoints
 * per Appwrite Network docs.
 * Self-hosted: no network picker; suffix is `_APP_DOMAIN_FUNCTIONS`.
 */

import { useEffect } from 'react'
import { DomainInput } from '@/components/global/shared/DomainInput'
import { useFunctionWizard } from '../WizardContext'
import { Network, Building2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'
import { useConsoleProfile } from '@/hooks/use-console-profile'

const FUNCTION_DOMAINS_URL =
  '/docs/products/functions/domains'
const NETWORK_EDGES_URL = '/docs/products/network/edges'
const NETWORK_REGIONS_URL = '/docs/products/network/regions'

export interface FunctionDomainCardProps {
  domain: string
  setDomain: (v: string) => void
  domainValid: boolean
  setDomainValid: (v: boolean) => void
}

function extractSubdomain(fullDomain: string): string {
  const first = fullDomain.split('.')[0]
  return first || ''
}

export function FunctionDomainCard({
  domain,
  setDomain,
  domainValid: _domainValid,
  setDomainValid,
}: FunctionDomainCardProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const showNetworkPicker = features.edgeNetwork
  const {
    endpointType,
    setEndpointType,
    baseDomain,
    edgeBaseDomain,
    regionBaseDomain,
    region,
  } = useFunctionWizard()

  useEffect(() => {
    const sub = extractSubdomain(domain)
    if (sub && baseDomain) setDomain(`${sub}.${baseDomain}`)
  }, [baseDomain, setDomain])

  const sub = extractSubdomain(domain)
  const edgeUrl = `https://${sub || '[name]'}.${edgeBaseDomain}`
  const regionUrl = `https://${sub || '[name]'}.${
    regionBaseDomain || `${region || 'region'}.appwrite.run`
  }`

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Domain')}
        </h3>
        {showNetworkPicker ? (
          <p className="text-[12px] text-muted-foreground mt-1">
            {t(
              'Pick where your function runs. Both support custom domains after deployment.',
            )}{' '}
            <DocsRouteLink className="link-neutral font-medium" href={NETWORK_REGIONS_URL}>
              {t('Region')}
            </DocsRouteLink>
            {' · '}
            <DocsRouteLink className="link-neutral font-medium" href={NETWORK_EDGES_URL}>
              {t('Edge')}
            </DocsRouteLink>
          </p>
        ) : (
          <p className="text-[12px] text-muted-foreground mt-1">
            {t('Your function will be accessible at this URL')}
          </p>
        )}
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        {showNetworkPicker ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <button
              type="button"
              onClick={() => {
                setEndpointType('region')
                if (sub && regionBaseDomain)
                  setDomain(`${sub}.${regionBaseDomain}`)
              }}
              className={cn(
                'text-start rounded-lg border p-4 transition-all cursor-pointer',
                endpointType === 'region'
                  ? 'border-foreground bg-primary/5'
                  : 'border-border hover:border-muted-foreground/50',
              )}
            >
              <Building2 className="h-5 w-5 text-muted-foreground mb-2" />
              <div className="flex items-center gap-2">
                <div className="text-[13px] font-semibold">
                  {t('Region compute')}
                </div>
                <Badge variant="info" className="text-[10px] shrink-0">
                  {t('Default')}
                </Badge>
              </div>
              <p className="text-[11px] text-muted-foreground mt-0.5">
                {t('Data sovereignty, compliance')}
              </p>
              <code className="mt-2 block text-[11px] text-muted-foreground font-mono truncate">
                {regionUrl}
              </code>
            </button>
            <button
              type="button"
              onClick={() => {
                setEndpointType('edge')
                if (sub) setDomain(`${sub}.${edgeBaseDomain}`)
              }}
              className={cn(
                'text-start rounded-lg border p-4 transition-all cursor-pointer',
                endpointType === 'edge'
                  ? 'border-foreground bg-primary/5'
                  : 'border-border hover:border-muted-foreground/50',
              )}
            >
              <Network className="h-5 w-5 text-muted-foreground mb-2" />
              <div className="text-[13px] font-semibold">
                {t('Edge network')}
              </div>
              <p className="text-[11px] text-muted-foreground mt-0.5">
                {t('Geo-routed, lowest latency')}
              </p>
              <code className="mt-2 block text-[11px] text-muted-foreground font-mono truncate">
                {edgeUrl}
              </code>
            </button>
          </div>
        ) : null}
        {baseDomain ? (
          <DomainInput
            value={domain}
            onChange={setDomain}
            onValidChange={setDomainValid}
            baseDomain={baseDomain}
            placeholder="my-function"
          />
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/20">
        <p className="text-[11px] text-muted-foreground">
          {t('Custom domain can be added in function settings.')}{' '}
          <DocsRouteLink className="link-neutral font-medium" href={FUNCTION_DOMAINS_URL}>
            {t('Learn more')}
          </DocsRouteLink>
        </p>
      </div>
    </div>
  )
}
