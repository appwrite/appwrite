import { useEffect, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { mapAppToMarketplaceApp } from '@/lib/marketplace/map-app'
import {
  buildMarketplaceAppTags,
  MARKETPLACE_CATEGORY_LABELS,
  MARKETPLACE_CATEGORY_ORDER,
  type MarketplaceAppCategory,
} from '@/lib/marketplace/types'
import { useOrganizationApp } from '@/lib/react-query/hooks'
import {
  trimOrEmpty,
  useOrgAppUpdate,
} from './_components/useOrgAppUpdate'
import { DeleteAppCard } from './_components/DeleteAppCard'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  if (!app || !orgId) return null

  const mapped = mapAppToMarketplaceApp(app, { organizationId: orgId })
  const { submit, isUpdating } = useOrgAppUpdate(orgId, app)

  const [name, setName] = useState(app.name)
  const [tagline, setTagline] = useState(app.tagline ?? '')
  const [description, setDescription] = useState(app.description ?? '')
  const [category, setCategory] = useState<MarketplaceAppCategory>(
    mapped.category,
  )

  useEffect(() => {
    const nextMapped = mapAppToMarketplaceApp(app, { organizationId: orgId })
    setName(app.name)
    setTagline(app.tagline ?? '')
    setDescription(app.description ?? '')
    setCategory(nextMapped.category)
  }, [app, orgId])

  const handleUpdate = async () => {
    if (!trimOrEmpty(name)) return
    await submit({
      name: trimOrEmpty(name),
      tagline: trimOrEmpty(tagline),
      description: trimOrEmpty(description),
      tags: buildMarketplaceAppTags(category, app.tags ?? []),
    })
  }

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Details')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Name and descriptions shown on the marketplace listing and OAuth2 consent screen.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <div className="space-y-2">
            <Label htmlFor="app-general-name">{t('Name')}</Label>
            <Input
              id="app-general-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="app-general-tagline">{t('Tagline')}</Label>
            <Input
              id="app-general-tagline"
              value={tagline}
              onChange={(e) => setTagline(e.target.value)}
              placeholder={t('Short summary for listings and consent')}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="app-general-description">{t('Description')}</Label>
            <Textarea
              id="app-general-description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={4}
              className="resize-none"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="app-general-category">{t('Category')}</Label>
            <Select
              value={category}
              onValueChange={(value) =>
                setCategory(value as MarketplaceAppCategory)
              }
            >
              <SelectTrigger id="app-general-category">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {MARKETPLACE_CATEGORY_ORDER.map((key) => (
                  <SelectItem key={key} value={key}>
                    {t(MARKETPLACE_CATEGORY_LABELS[key])}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label>{t('App ID')}</Label>
            <CopyableId id={app.$id} />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={isUpdating || !trimOrEmpty(name)}
            onClick={handleUpdate}
          >
            {t('Update')}
          </Button>
        </div>
      </div>

      <DeleteAppCard orgId={orgId} app={app} />
    </div>
  )
}
