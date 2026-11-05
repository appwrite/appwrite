import { Check, Code2, Package, Plug2, Send } from 'lucide-react'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { McpIcon } from '@/components/global/shared/McpIcon'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { Button } from '@/components/ui/button'
import type { AddAppKind } from '@/lib/add-app-wizard/types'
import { useT } from '@/lib/i18n/translate'
import { getPlatformDisplayName } from '@/lib/utils/platform'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: Plug2,
    title: 'Register your app',
    description:
      'Add its hostname, bundle ID, or package name so Appwrite accepts requests from it.',
  },
  {
    icon: Package,
    title: 'Install the SDK',
    description:
      'Pick the SDK for your stack and point it at this project with its ID and endpoint.',
  },
  {
    icon: Send,
    title: 'Make your first request',
    description:
      'Ping Appwrite from your app to confirm the connection, then start using Auth, Databases, and more.',
  },
]

const PLATFORMS = [
  'web',
  'react-native',
  'flutter',
  'apple',
  'android',
  'windows',
  'linux',
] as const

const REGISTERED = [
  { platform: 'web', identifier: 'app.acme.dev' },
  { platform: 'apple', identifier: 'dev.acme.ios' },
  { platform: 'android', identifier: 'dev.acme.android' },
] as const

/** Decorative list of registered apps and the ping that confirms one is connected. */
function AppsVisual() {
  return (
    <ProductEmptyStateVisual className="w-[340px] pb-4">
      <div className="overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          <Plug2 className="h-3.5 w-3.5 text-muted-foreground" />
          <span className="h-1.5 w-14 rounded-full bg-muted-foreground/25" />
        </div>
        <ul className="divide-y divide-border">
          {REGISTERED.map((app, index) => (
            <li key={app.platform} className="flex items-center gap-3 px-3 py-2.5">
              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40 text-muted-foreground">
                <PlatformIcon platform={app.platform} size="sm" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block h-1.5 w-14 rounded-full bg-foreground/50" />
                <span
                  dir="ltr"
                  className="mt-1.5 block truncate font-mono text-[10px] text-muted-foreground"
                >
                  {app.identifier}
                </span>
              </span>
              {index === 0 ? (
                <Check className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
              ) : (
                <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/30" />
              )}
            </li>
          ))}
        </ul>
      </div>

      <div
        dir="ltr"
        className="absolute -end-44 top-3 w-52 rounded-lg border border-border bg-popover p-2.5 text-start font-mono text-[10px] leading-relaxed shadow-lg"
      >
        <div className="flex items-center gap-1.5 text-muted-foreground">
          <Code2 className="h-3 w-3" />
          <span>app.ts</span>
        </div>
        <div className="mt-1.5 text-muted-foreground">
          <span className="text-[var(--brand-cta)]">await</span>{' '}
          <span className="text-foreground">client.ping()</span>
        </div>
      </div>

      <div className="absolute -end-28 top-20 flex items-center gap-1.5 rounded-lg border border-border bg-popover px-2.5 py-1.5 shadow-lg">
        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
        <span dir="ltr" className="font-mono text-[10px] text-foreground">
          pong
        </span>
        <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
          38ms
        </span>
      </div>
    </ProductEmptyStateVisual>
  )
}

export function AppsEmptyState({
  onAdd,
  onBuildWithAgent,
  addDisabled = false,
  addDisabledTooltip,
}: {
  onAdd: (kind?: AddAppKind) => void
  onBuildWithAgent?: () => void
  addDisabled?: boolean
  addDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-8 sm:py-12">
      <ProductEmptyStateHero
        visual={<AppsVisual />}
        icon={Plug2}
        title={t('Connect your first app')}
        description={t(
          'Register the web, mobile, or desktop apps that talk to this project. Appwrite only accepts client requests from apps you add here.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={() => onAdd()}
              disabled={addDisabled}
              disabledTooltip={addDisabledTooltip}
            >
              {t('Add app')}
            </ProductEmptyStateCreateButton>
            {onBuildWithAgent ? (
              <Button
                variant="outline"
                className="h-9 gap-1.5 text-[13px]"
                onClick={onBuildWithAgent}
              >
                <McpIcon className="h-4 w-4" />
                {t('Build with an agent')}
              </Button>
            ) : null}
          </>
        }
      />
      <div className="mt-8 flex flex-wrap justify-center gap-2">
        {PLATFORMS.map((platform) => (
          <Button
            key={platform}
            variant="outline"
            className="h-8 gap-1.5 rounded-full px-3 text-[12px]"
            disabled={addDisabled}
            onClick={() => onAdd(platform as AddAppKind)}
          >
            <PlatformIcon platform={platform} size="sm" />
            {getPlatformDisplayName(platform)}
          </Button>
        ))}
      </div>
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
