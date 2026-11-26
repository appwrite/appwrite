import { useEffect, useRef, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  listsEqual,
  nonEmptyList,
  trimOrEmpty,
  useOrgAppUpdate,
} from './useOrgAppUpdate'
import { AppLogoFilePicker } from '../../_components/AppLogoFilePicker'
import { AppImagesPicker } from '../../_components/AppImagesPicker'
import { useT } from '@/lib/i18n/translate'

type BrandingCardProps = {
  orgId: string
  app: Models.App
}

export function BrandingCard({ orgId, app }: BrandingCardProps) {
  const t = useT()
  const { submit, isUpdating } = useOrgAppUpdate(orgId, app)
  const [clientUri, setClientUri] = useState(app.clientUri ?? '')
  const [logoUri, setLogoUri] = useState(app.logoUri ?? '')
  const [images, setImages] = useState(app.images ?? [])

  const syncedAppRef = useRef(app)

  useEffect(() => {
    if (syncedAppRef.current.$id === app.$id) return
    syncedAppRef.current = app
    setClientUri(app.clientUri ?? '')
    setLogoUri(app.logoUri ?? '')
    setImages(app.images ?? [])
  }, [app])

  const brandingDirty =
    trimOrEmpty(clientUri) !== (app.clientUri ?? '') ||
    trimOrEmpty(logoUri) !== (app.logoUri ?? '') ||
    !listsEqual(nonEmptyList(images), app.images ?? [])

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Branding')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('URLs shown on the OAuth2 consent screen.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="space-y-2">
          <Label htmlFor="app-branding-homepage">{t('Homepage URL')}</Label>
          <Input
            id="app-branding-homepage"
            value={clientUri}
            onChange={(e) => setClientUri(e.target.value)}
            placeholder="https://example.com"
          />
        </div>
        <div className="space-y-2">
          <Label>{t('Logo')}</Label>
          <AppLogoFilePicker
            teamId={orgId}
            value={logoUri}
            onChange={setLogoUri}
            disabled={isUpdating}
          />
        </div>
        <div className="space-y-2">
          <Label>{t('Images')}</Label>
          <AppImagesPicker
            teamId={orgId}
            value={images}
            onChange={setImages}
            disabled={isUpdating}
          />
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={isUpdating || !brandingDirty}
          onClick={() =>
            submit({
              clientUri: trimOrEmpty(clientUri),
              logoUri: trimOrEmpty(logoUri),
              images: nonEmptyList(images),
            }).catch(() => {
              // toast handled in submit
            })
          }
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
