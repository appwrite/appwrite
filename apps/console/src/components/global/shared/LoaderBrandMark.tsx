import { AppwriteWordmark } from '@/components/global/shared/AppwriteWordmark'
import { LegacyAppwriteLogo } from '@/components/global/shared/LegacyAppwriteBrand'
import { getReleaseLine } from '@/lib/appwrite-version'
import { isLegacyThemeFromStorage } from '@/lib/legacy-theme-assets'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { cn } from '@/lib/utils'

export function LoaderBrandMark({ brand }: { brand: 'console' | 'partners' }) {
  if (brand === 'partners') {
    return (
      <div
        className={cn('inline-flex items-center gap-2.5', FORCE_LTR_CLASS)}
        dir="ltr"
      >
        <AppwriteWordmark className="h-8" />
        <span className="rounded-full border border-border px-2 py-0.5 text-[10px] font-light tracking-wide text-muted-foreground">
          Partners
        </span>
      </div>
    )
  }

  const isLegacy = isLegacyThemeFromStorage()
  const release = getReleaseLine(getRuntimeConfig().appwriteVersion)

  return (
    <div
      className={cn('inline-flex items-end gap-1.5', FORCE_LTR_CLASS)}
      dir="ltr"
    >
      {isLegacy ? (
        <LegacyAppwriteLogo className="h-8" aria-label="Appwrite" />
      ) : (
        <AppwriteWordmark className="h-8" />
      )}
      {release ? (
        <span className="pb-0.5 text-xs font-extralight tracking-tight text-muted-foreground">
          / {release}
        </span>
      ) : null}
    </div>
  )
}
