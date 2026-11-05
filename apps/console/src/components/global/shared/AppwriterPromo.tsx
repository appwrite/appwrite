import { ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  useInitThemeImageSrc,
  useInitThemeUsesDarkImage,
} from '@/lib/init/use-init-theme-image'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const APPWRITER_STORE_HREF =
  'https://appwrite.store/products/preorder-the-appwriter'

const APPWRITER_INIT_IMAGE_LIGHT = '/images/init/prize-day-2-swag-light.jpg'
const APPWRITER_INIT_IMAGE_DARK = '/images/init/prize-day-3-swag.jpg'
const APPWRITER_PROMO_IMAGE_ASPECT = '16 / 10'

export function AppwriterPromo({ className }: { className?: string }) {
  const t = useT()
  const usesDarkImage = useInitThemeUsesDarkImage()
  const imageSrc = useInitThemeImageSrc(
    APPWRITER_INIT_IMAGE_LIGHT,
    APPWRITER_INIT_IMAGE_DARK,
  )

  return (
    <aside
      className={cn(
        'flex w-[300px] shrink-0 flex-col justify-center gap-3 border-s border-border/60 px-5 py-3',
        className,
      )}
      aria-labelledby="appwriter-promo-heading"
    >
      <div
        className={cn(
          'relative w-full overflow-hidden rounded-lg border border-border',
          usesDarkImage ? 'bg-[#0a0a0a]' : 'bg-muted/30',
        )}
        style={{ aspectRatio: APPWRITER_PROMO_IMAGE_ASPECT }}
      >
        <img
          src={imageSrc}
          alt={t('The Appwriter mechanical keyboard') /* pragma: allowlist secret */}
          className="size-full object-cover object-center"
          loading="lazy"
          decoding="async"
        />
      </div>

      <div className="space-y-1.5">
        <h3
          id="appwriter-promo-heading"
          className="text-[14px] font-semibold leading-snug text-foreground"
        >
          The Appwriter
        </h3>
        <p className="text-[12px] leading-relaxed text-muted-foreground">
          {t(
            '75% hot-swap mechanical keyboard with Gateron G Pro Yellow switches, tri-mode USB-C/2.4GHz/BT, and 84 dye-sublimated keycaps optimized for Console shortcuts.',
          )}
        </p>
      </div>

      <Button variant="outline" size="sm" className="h-8 w-full text-[12px]" asChild>
        <a href={APPWRITER_STORE_HREF} target="_blank" rel="noopener noreferrer">
          {t('Order from the Appwrite Store')} {/* pragma: allowlist secret */}
          <ChevronRight className="size-3.5" aria-hidden />
        </a>
      </Button>
    </aside>
  )
}
