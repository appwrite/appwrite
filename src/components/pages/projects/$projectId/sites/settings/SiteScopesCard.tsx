import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildSiteUpdateParams } from '@/lib/react-query/hooks'
import { ScopeEditor } from '@/components/global/shared/ScopeEditor'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

interface SiteScopesCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function SiteScopesCard({
  projectId,
  siteId,
  site,
}: SiteScopesCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  // Null until the site loads, so an empty editor is never mistaken for
  // "no scopes selected" and saved over the real list.
  const [scopes, setScopes] = useState<string[] | null>(null)

  useEffect(() => {
    if (site) {
      setScopes(site.scopes ?? [])
    }
  }, [site])

  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: (updated) => {
      toast.success(t('Scopes updated successfully'))
      queryClient.setQueryData(['site', 'project', projectId, siteId], updated)
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update scopes')))
    },
  })

  // Order is not meaningful, so compare as sets.
  const hasChanges = useMemo(() => {
    if (scopes === null) return false
    const original = new Set(site?.scopes ?? [])
    if (original.size !== scopes.length) return true
    return scopes.some((scope) => !original.has(scope))
  }, [scopes, site?.scopes])

  const handleSave = () => {
    if (scopes === null) return
    updateSiteMutation.mutate({ scopes })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Scopes')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Select scopes to grant the dynamic key generated temporarily for every site build and SSR execution. It is best practice to allow only necessary permissions.',
          )}{' '}
          <DocsRouteLink
            className="link-neutral"
            href="/docs/advanced/platform/api-keys#scopes"
          >
            {t('Learn more')}
          </DocsRouteLink>
          .
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        {scopes !== null ? (
          <ScopeEditor
            value={scopes}
            onChange={setScopes}
            disabled={updateSiteMutation.isPending}
          />
        ) : (
          <p className="text-[13px] text-muted-foreground">
            {t('Loading scopes...')}
          </p>
        )}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || updateSiteMutation.isPending}
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
