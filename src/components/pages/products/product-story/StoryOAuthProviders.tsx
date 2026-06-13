import { StoryAnimated } from '@/components/pages/products/product-story/StoryAnimated'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

const OAUTH_PROVIDERS = [
  { name: 'Google', icon: '/icons/google.svg' },
  { name: 'GitHub', icon: '/icons/github.svg' },
  { name: 'Apple', icon: '/icons/apple.svg' },
  { name: 'X', icon: '/icons/x.svg' },
  { name: 'Microsoft', icon: '/icons/microsoft.svg' },
  { name: 'Discord', icon: '/icons/discord.svg' },
  { name: 'Facebook', icon: '/icons/facebook.svg' },
  { name: 'LinkedIn', icon: '/icons/linkedin.svg' },
] as const

export function StoryOAuthProviders({ className }: { className?: string }) {
  return (
    <div className={cn('space-y-3', className)}>
      <StoryAnimated delayMs={80}>
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          Continue with
        </p>
      </StoryAnimated>
      <div className="grid grid-cols-4 gap-2 sm:grid-cols-4">
        {OAUTH_PROVIDERS.map((provider, index) => (
          <StoryAnimated key={provider.name} delayMs={120 + index * 60}>
            <div
              className="flex flex-col items-center gap-1.5 rounded-lg border border-border bg-muted/10 px-2 py-2.5 transition-colors duration-300 hover:border-border hover:bg-muted/20"
              title={provider.name}
            >
              <img
                src={provider.icon}
                alt=""
                className={cn('size-5 object-contain sm:size-6', PUBLIC_ICON_MUTED_CLASSES)}
              />
              <span className="text-[9px] font-medium text-muted-foreground sm:text-[10px]">
                {provider.name}
              </span>
            </div>
          </StoryAnimated>
        ))}
      </div>
      <StoryAnimated delayMs={640}>
        <p className="text-center text-[10px] text-muted-foreground sm:text-[11px]">
          30+ OAuth providers supported
        </p>
      </StoryAnimated>
    </div>
  )
}
