/**
 * Name Card Component
 *
 * Allows users to update the site's display name and description.
 */

import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Sites, type Models } from '@appwrite.io/console'
import { DescriptionField } from '@/components/global/shared/DescriptionField'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildSiteUpdateParams } from '@/lib/react-query/hooks'
import {
  normalizeDescription,
  readDescription,
  withDescription,
} from '@/lib/appwrite/resource-descriptions'
import { useT } from '@/lib/i18n/translate'

interface NameCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function NameCard({ projectId, siteId, site }: NameCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [name, setName] = useSyncStateFromServer(site?.name || '')
  const [description, setDescription] = useSyncStateFromServer(
    readDescription(site),
  )

  // Update site mutation
  const updateSiteMutation = useMutation({
    // The description comes from the variables, not from render-time state, so
    // the payload is whatever the caller passed and nothing else.
    mutationFn: async ({
      description,
      ...updates
    }: Partial<Models.Site> & { description: string }) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      // Sent through `withDescription`: the pinned SDK drops `description`.
      return await withDescription(
        projectId,
        normalizeDescription(description),
        Sites,
      ).update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: (updated) => {
      toast.success(t('Site updated successfully'))
      queryClient.setQueryData(['site', 'project', projectId, siteId], updated)
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update site')))
    },
  })

  const handleSave = () => {
    if (!name.trim()) {
      toast.error(t('Site name is required'))
      return
    }
    updateSiteMutation.mutate({ name, description })
  }

  // Both sides normalized: the API's `Text(1024, 0)` does not trim, so a
  // description another client stored with surrounding whitespace would
  // otherwise leave this card dirty on an untouched form.
  const hasChanges =
    name !== site?.name ||
    normalizeDescription(description) !==
      normalizeDescription(readDescription(site))

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Name and description')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Name and description used to identify this site')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div>
          <Label htmlFor="site-name" className="text-[13px]">
            {t('Name')}
          </Label>
          <Input
            id="site-name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t('Enter site name')}
            className="mt-2 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
          />
        </div>
        <DescriptionField
          id="site-description"
          value={description}
          onChange={setDescription}
          placeholder={t('What is this site for?')}
          className="max-w-sm"
        />
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || !name.trim() || updateSiteMutation.isPending}
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
