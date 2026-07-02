/**
 * Function Domain Card
 *
 * Lets users choose between Edge (.appwrite.network) and Region
 * (.<region>.appwrite.run) endpoints per Appwrite Network docs.
 * Both support custom domains post-deployment.
 */

import { useEffect } from 'react'
import { DomainInput } from '@/components/global/shared/DomainInput'
import { useFunctionWizard } from '../WizardContext'
import { Network, Building2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

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
  const { endpointType, setEndpointType, baseDomain, region } =
    useFunctionWizard()

  useEffect(() => {
    const sub = extractSubdomain(domain)
    if (sub) setDomain(`${sub}.${baseDomain}`)
  }, [baseDomain, setDomain])

  const sub = extractSubdomain(domain)
  const edgeUrl = sub
    ? `https://${sub}.appwrite.network`
    : 'https://[name].appwrite.network'
  const regionUrl =
    sub && region
      ? `https://${sub}.${region}.appwrite.run`
      : `https://[name].${region || 'region'}.appwrite.run`

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden mb-6">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Domain')}
        </h3>
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
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <button
            type="button"
            onClick={() => {
              setEndpointType('region')
              if (sub && region) setDomain(`${sub}.${region}.appwrite.run`)
            }}
            className={cn(
              'text-start rounded-lg border p-4 transition-all cursor-pointer',
              endpointType === 'region'
                ? 'border-foreground bg-primary/5'
                : 'border-border hover:border-muted-foreground/50',
            )}
          >
            <Building2 className="h-5 w-5 text-muted-foreground mb-2" />
            <div className="text-[13px] font-semibold">
              {t('Region compute')}
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
              if (sub) setDomain(`${sub}.appwrite.network`)
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
        <DomainInput
          value={domain}
          onChange={setDomain}
          onValidChange={setDomainValid}
          baseDomain={baseDomain}
          placeholder="my-function"
        />
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
