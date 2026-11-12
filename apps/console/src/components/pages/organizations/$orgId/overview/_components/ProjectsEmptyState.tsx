import {
  Database,
  Folder,
  FolderPlus,
  Globe,
  HardDrive,
  Layers,
  MapPin,
  MessageSquare,
  Plug,
  Users,
  Zap,
} from 'lucide-react'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateDocsButton,
  ProductEmptyStateHero,
  ProductEmptyStateSteps,
  ProductEmptyStateVisual,
  type ProductEmptyStateIcon,
  type ProductEmptyStateStep,
} from '@/components/global/shared/ProductEmptyState'
import { useT } from '@/lib/i18n/translate'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

const STEPS: ProductEmptyStateStep[] = [
  {
    icon: FolderPlus,
    title: 'Create a project',
    description:
      'Name it and pick the region closest to your users. Each project keeps its data, keys, and settings apart.',
  },
  {
    icon: Plug,
    title: 'Connect your app',
    description:
      'Add a web, mobile, or server platform and install an SDK. Your first request takes a few lines of code.',
  },
  {
    icon: Layers,
    title: 'Build with every product',
    description:
      'Auth, Databases, Storage, Functions, Messaging, and Sites are ready in every project, no setup required.',
  },
]

type Service = { icon: ProductEmptyStateIcon; label: string }

const SERVICES_START: Service[] = [
  { icon: Users, label: 'Auth' },
  { icon: Database, label: 'Databases' },
  { icon: HardDrive, label: 'Storage' },
]

const SERVICES_END: Service[] = [
  { icon: Zap, label: 'Functions' },
  { icon: MessageSquare, label: 'Messaging' },
  { icon: Globe, label: 'Sites' },
]

const PLATFORM_ICONS = [
  'react.svg',
  'nextjs.svg',
  'flutter.svg',
  'apple.svg',
  'android.svg',
]

function ServiceColumn({
  services,
  side,
}: {
  services: Service[]
  side: 'start' | 'end'
}) {
  const t = useT()
  return (
    <div className="flex flex-col gap-3">
      {services.map((service) => {
        const Icon = service.icon
        const chip = (
          <span className="flex w-32 items-center gap-2 rounded-lg border border-border bg-card px-2.5 py-2 shadow-sm">
            <Icon className="h-3.5 w-3.5 text-muted-foreground" />
            <span className="text-[11px] font-medium text-foreground">
              {t(service.label)}
            </span>
          </span>
        )
        const wire = (
          <span className="flex items-center">
            {side === 'end' && (
              <span className="h-1.5 w-1.5 rounded-full bg-[var(--brand-cta)]" />
            )}
            <span className="h-px w-8 bg-[color-mix(in_oklch,var(--brand-cta)_45%,transparent)]" />
            {side === 'start' && (
              <span className="h-1.5 w-1.5 rounded-full bg-[var(--brand-cta)]" />
            )}
          </span>
        )
        return (
          <div key={service.label} className="flex items-center">
            {side === 'start' ? (
              <>
                {chip}
                {wire}
              </>
            ) : (
              <>
                {wire}
                {chip}
              </>
            )}
          </div>
        )
      })}
    </div>
  )
}

/** Decorative project hub wired to every product, with the platforms it serves. */
function ProjectHubVisual() {
  return (
    <ProductEmptyStateVisual className="flex items-center pb-12">
      <ServiceColumn services={SERVICES_START} side="start" />
      <div className="w-48 overflow-hidden rounded-xl border border-border bg-card text-start shadow-xl">
        <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-3 py-2.5">
          <span className="flex h-6 w-6 items-center justify-center rounded-md bg-muted text-muted-foreground">
            <Folder className="h-3.5 w-3.5" />
          </span>
          <span dir="ltr" className="font-mono text-[11px] text-foreground">
            my-app
          </span>
        </div>
        <div className="space-y-2 px-3 py-3">
          <div className="flex items-center gap-1.5">
            <MapPin className="h-3 w-3 text-muted-foreground" />
            <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
              fra · Frankfurt
            </span>
          </div>
          <div
            dir="ltr"
            className="truncate rounded-md border border-border bg-background px-2 py-1 font-mono text-[10px] text-muted-foreground"
          >
            fra.cloud.appwrite.io/v1
          </div>
          <div className="flex items-center gap-1 pt-0.5">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
            <span className="h-1.5 w-16 rounded-full bg-muted-foreground/20" />
          </div>
        </div>
      </div>
      <ServiceColumn services={SERVICES_END} side="end" />

      <div className="absolute bottom-0 start-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full border border-border bg-popover px-2.5 py-1.5 shadow-lg rtl:translate-x-1/2">
        {PLATFORM_ICONS.map((icon) => (
          <img
            key={icon}
            src={`/icons/${icon}`}
            alt=""
            className={cn('h-4 w-4', PUBLIC_ICON_MUTED_CLASSES)}
          />
        ))}
      </div>
    </ProductEmptyStateVisual>
  )
}

export function ProjectsEmptyState({
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  return (
    <div className="mx-auto w-full max-w-4xl py-6 sm:py-10">
      <ProductEmptyStateHero
        visual={<ProjectHubVisual />}
        icon={Folder}
        title={t('Create your first project')}
        description={t(
          'A project is the backend for one app: its users, data, files, and code, hosted in the region you choose.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create project')}
            </ProductEmptyStateCreateButton>
            <ProductEmptyStateDocsButton path="/docs/quick-starts" />
          </>
        }
      />
      <ProductEmptyStateSteps steps={STEPS} />
    </div>
  )
}
