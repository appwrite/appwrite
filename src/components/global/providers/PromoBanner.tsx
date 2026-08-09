import {
  createContext,
  useContext,
  useState,
  useCallback,
  useMemo,
  type ReactNode,
} from 'react'
import { X, ImageIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'

export interface PromoBannerItem {
  id: string
  title: string
  description: string
  image?: string
  ctaText?: string
  ctaUrl?: string
}

interface PromoBannerProps {
  banners: PromoBannerItem[]
  onDismiss: (id: string) => void
  onDismissAll: () => void
}

function PromoBannerComponent({
  banners,
  onDismiss,
  onDismissAll,
}: PromoBannerProps) {
  const t = useT()
  const [expandedIndex, setExpandedIndex] = useState(0)
  const { features } = useConsoleProfile()

  // Filter out AI assistant banner when feature is disabled
  const filteredBanners = useMemo(
    () =>
      banners.filter(
        (b) => b.ctaUrl !== '/ai-assistant' || features.agent,
      ),
    [banners, features.agent],
  )

  if (filteredBanners.length === 0) return null

  const visibleBanners = filteredBanners.slice(0, 3)

  return (
    <div className="fixed bottom-4 start-4 z-50">
      {/* Stacked banners container */}
      <div className="relative">
        {visibleBanners.map((banner, index) => {
          const isExpanded = index === expandedIndex
          const stackOffset = (visibleBanners.length - 1 - index) * 12
          const stackScale = 1 - (visibleBanners.length - 1 - index) * 0.04

          return (
            <div
              key={banner.id}
              className={cn(
                'absolute bottom-0 start-0 w-[320px] transition-all duration-300 ease-out',
                isExpanded ? 'z-30' : index === 1 ? 'z-20' : 'z-10',
                !isExpanded && 'cursor-pointer hover:translate-y-[-2px]',
              )}
              style={{
                transform: `translateY(-${stackOffset}px) scale(${stackScale})`,
                transformOrigin: 'bottom center',
              }}
              onClick={() => !isExpanded && setExpandedIndex(index)}
            >
              {/* Card using native UI styles */}
              <div
                className={cn(
                  'overflow-hidden rounded-lg border border-border bg-card',
                  !isExpanded && 'opacity-95',
                )}
              >
                {/* Image placeholder area */}
                <div className="relative h-[140px] overflow-hidden bg-muted">
                  {banner.image ? (
                    <img
                      src={banner.image}
                      alt=""
                      className="h-full w-full object-cover"
                    />
                  ) : (
                    <div className="flex h-full w-full items-center justify-center">
                      <ImageIcon className="h-10 w-10 text-muted-foreground/40" />
                    </div>
                  )}

                  {/* Close button */}
                  {isExpanded && (
                    <button
                      onClick={(e) => {
                        e.stopPropagation()
                        onDismiss(banner.id)
                        if (expandedIndex >= filteredBanners.length - 1) {
                          setExpandedIndex(
                            Math.max(0, filteredBanners.length - 2),
                          )
                        }
                      }}
                      className="absolute end-2 top-2 flex h-7 w-7 items-center justify-center rounded-md bg-background/80 text-muted-foreground backdrop-blur-sm transition-colors hover:bg-background hover:text-foreground"
                    >
                      <X className="h-4 w-4" />
                    </button>
                  )}
                </div>

                {/* Content area */}
                <div className="p-4">
                  <h4 className="text-[15px] font-semibold text-foreground">
                    {t(banner.title)}
                  </h4>
                  <p className="mt-1 text-[13px] leading-relaxed text-muted-foreground">
                    {t(banner.description)}
                  </p>

                  {/* CTA Button */}
                  {isExpanded && banner.ctaText && (
                    <button
                      className="mt-3 w-full rounded-md bg-primary py-2.5 text-[13px] font-medium text-primary-foreground transition-colors hover:bg-primary/90 active:scale-[0.98]"
                      onClick={(e) => {
                        e.stopPropagation()
                        if (banner.ctaUrl) {
                          window.location.href = banner.ctaUrl
                        }
                      }}
                    >
                      {t(banner.ctaText)}
                    </button>
                  )}
                </div>
              </div>
            </div>
          )
        })}

        {/* Spacer to maintain layout height */}
        <div
          className="pointer-events-none w-[320px]"
          style={{ height: `${240 + (visibleBanners.length - 1) * 12}px` }}
        />
      </div>

      {/* Stack indicator & dismiss all */}
      {filteredBanners.length > 1 && (
        <div className="mt-3 flex items-center justify-between px-1">
          <div className="flex items-center gap-1.5">
            {filteredBanners.slice(0, 3).map((_, index) => (
              <button
                key={index}
                onClick={() => setExpandedIndex(index)}
                className={cn(
                  'h-1.5 rounded-full transition-all',
                  index === expandedIndex
                    ? 'w-5 bg-foreground'
                    : 'w-1.5 bg-muted-foreground/40 hover:bg-muted-foreground/60',
                )}
              />
            ))}
            {filteredBanners.length > 3 && (
              <span className="ms-1.5 text-[11px] text-muted-foreground">
                +{filteredBanners.length - 3} {t('more')}
              </span>
            )}
          </div>
          <button
            onClick={onDismissAll}
            className="text-[12px] text-muted-foreground transition-colors hover:text-foreground"
          >
            {t('Dismiss all')}
          </button>
        </div>
      )}
    </div>
  )
}

// Mock banners for testing
export const mockPromoBanners: PromoBannerItem[] = [
  {
    id: 'promo-1',
    title: 'Introducing Imagine',
    description: 'The most complete AI builder to date',
    ctaText: 'Try it now',
    ctaUrl: '/ai-assistant',
  },
  {
    id: 'promo-2',
    title: 'Edge Functions',
    description:
      'Deploy serverless functions at the edge for ultra-low latency',
    ctaText: 'Learn more',
    ctaUrl: '/functions',
  },
  {
    id: 'promo-3',
    title: 'New Database Regions',
    description: "We've expanded to 12 new regions worldwide",
    ctaText: 'See regions',
    ctaUrl: '/regions',
  },
]

interface PromoBannerContextValue {
  banners: PromoBannerItem[]
  addBanner: (banner: PromoBannerItem) => void
  removeBanner: (id: string) => void
  clearAllBanners: () => void
  addMockBanner: () => void
}

const PromoBannerContext = createContext<PromoBannerContextValue | null>(null)

export function usePromoBanner() {
  const context = useContext(PromoBannerContext)
  if (!context) {
    throw new Error('usePromoBanner must be used within a PromoBannerProvider')
  }
  return context
}

interface PromoBannerProviderProps {
  children: ReactNode
}

export function PromoBannerProvider({ children }: PromoBannerProviderProps) {
  const [banners, setBanners] = useState<PromoBannerItem[]>([])

  const addBanner = useCallback((banner: PromoBannerItem) => {
    setBanners((prev) => {
      // Don't add duplicate banners
      if (prev.some((b) => b.id === banner.id)) return prev
      return [...prev, banner]
    })
  }, [])

  const removeBanner = useCallback((id: string) => {
    setBanners((prev) => prev.filter((b) => b.id !== id))
  }, [])

  const clearAllBanners = useCallback(() => {
    setBanners([])
  }, [])

  const addMockBanner = useCallback(() => {
    // Pick a random mock banner that isn't already shown
    const availableMocks = mockPromoBanners.filter(
      (mock) => !banners.some((b) => b.id === mock.id),
    )

    if (availableMocks.length > 0) {
      const randomMock =
        availableMocks[Math.floor(Math.random() * availableMocks.length)]
      addBanner(randomMock)
    } else {
      // If all mocks are shown, create a new unique one
      const newBanner: PromoBannerItem = {
        id: `promo-custom-${Date.now()}`,
        title: 'New Feature Available',
        description:
          'Check out our latest updates and improvements to enhance your development experience.',
        ctaText: 'Explore',
      }
      addBanner(newBanner)
    }
  }, [banners, addBanner])

  return (
    <PromoBannerContext.Provider
      value={{
        banners,
        addBanner,
        removeBanner,
        clearAllBanners,
        addMockBanner,
      }}
    >
      {children}
      <PromoBannerComponent
        banners={banners}
        onDismiss={removeBanner}
        onDismissAll={clearAllBanners}
      />
    </PromoBannerContext.Provider>
  )
}

// Export PromoBanner component for direct use if needed
export { PromoBannerComponent as PromoBanner }
