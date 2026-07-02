import { useState, useEffect } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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
import {
  MARKETPLACE_CATEGORY_LABELS,
  type MarketplaceAppCategory,
} from '@/lib/marketplace/types'
import { useT } from '@/lib/i18n/translate'

export type CreateMarketplaceAppInput = {
  name: string
  slug: string
  shortDescription: string
  description: string
  category: MarketplaceAppCategory
}

interface CreateMarketplaceAppProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (input: CreateMarketplaceAppInput) => void | Promise<void>
  isSubmitting?: boolean
}

function slugify(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

export function CreateMarketplaceApp({
  open,
  onOpenChange,
  onCreate,
  isSubmitting = false,
}: CreateMarketplaceAppProps) {
  const t = useT()
  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')
  const [slugTouched, setSlugTouched] = useState(false)
  const [shortDescription, setShortDescription] = useState('')
  const [description, setDescription] = useState('')
  const [category, setCategory] = useState<MarketplaceAppCategory>('devtools')

  useEffect(() => {
    if (!open) {
      setName('')
      setSlug('')
      setSlugTouched(false)
      setShortDescription('')
      setDescription('')
      setCategory('devtools')
    }
  }, [open])

  useEffect(() => {
    if (!slugTouched && name) {
      setSlug(slugify(name))
    }
  }, [name, slugTouched])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!name.trim() || !shortDescription.trim() || isSubmitting) return
    await onCreate({
      name: name.trim(),
      slug: slug.trim() || slugify(name),
      shortDescription: shortDescription.trim(),
      description: description.trim() || shortDescription.trim(),
      category,
    })
  }

  const canSubmit = name.trim().length > 0 && shortDescription.trim().length > 0

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Add app')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Create an OAuth2 app listing for the marketplace. It is saved as a draft until you publish it.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="marketplace-app-name">{t('Name')}</Label>
              <Input
                id="marketplace-app-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('My integration')}
                autoFocus
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="marketplace-app-slug">{t('Slug')}</Label>
              <Input
                id="marketplace-app-slug"
                value={slug}
                onChange={(e) => {
                  setSlugTouched(true)
                  setSlug(e.target.value)
                }}
                placeholder="my-integration"
              />
              <p className="text-[12px] text-muted-foreground">
                {t('Used in the public listing URL')}
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="marketplace-app-category">{t('Category')}</Label>
              <Select
                value={category}
                onValueChange={(v) =>
                  setCategory(v as MarketplaceAppCategory)
                }
              >
                <SelectTrigger id="marketplace-app-category">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {(
                    Object.keys(
                      MARKETPLACE_CATEGORY_LABELS,
                    ) as MarketplaceAppCategory[]
                  ).map((key) => (
                    <SelectItem key={key} value={key}>
                      {t(MARKETPLACE_CATEGORY_LABELS[key])}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="marketplace-app-short">{t('Short description')}</Label>
              <Input
                id="marketplace-app-short"
                value={shortDescription}
                onChange={(e) => setShortDescription(e.target.value)}
                placeholder={t('One line summary')}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="marketplace-app-description">
                {t('Description')}
              </Label>
              <Textarea
                id="marketplace-app-description"
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder={t('Full description for the listing page')}
                rows={4}
                className="resize-none"
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={!canSubmit || isSubmitting}>
              {t('Add app')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
