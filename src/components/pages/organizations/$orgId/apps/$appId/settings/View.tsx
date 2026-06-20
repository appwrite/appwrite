import { useParams } from '@tanstack/react-router'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useOrganizationApp } from '@/lib/react-query/hooks'
import { BrandingCard } from '../_components/BrandingCard'
import { useOrgAppUpdate } from '../_components/useOrgAppUpdate'

export function View() {
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  if (!app || !orgId) return null

  const { submit, isUpdating } = useOrgAppUpdate(orgId, app)

  const handlePublishToggle = async (enabled: boolean) => {
    try {
      await submit({ enabled }, {
        successMessage: enabled ? 'App published' : 'App unpublished',
      })
    } catch {
      // toast handled in submit
    }
  }

  return (
    <div className="space-y-4">
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Publish</h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Published apps appear in the marketplace catalog for other
            organizations.
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="flex items-center justify-between gap-4">
            <div>
              <Label htmlFor="app-publication-enabled">Published</Label>
              <p className="text-[12px] text-muted-foreground mt-1">
                {app.enabled
                  ? 'This app is visible in the marketplace.'
                  : 'This app is a draft and not listed publicly.'}
              </p>
            </div>
            <Switch
              id="app-publication-enabled"
              checked={app.enabled}
              onCheckedChange={handlePublishToggle}
              disabled={isUpdating}
            />
          </div>
        </div>
      </div>

      <BrandingCard orgId={orgId} app={app} />
    </div>
  )
}
