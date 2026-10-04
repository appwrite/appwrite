import { Copy, Terminal, type LucideIcon } from 'lucide-react'
import { floatStyle, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type DatabaseTool = {
  id: string
  name: string
  description: string
  iconSrc?: string
  icon?: LucideIcon
}

const DATABASE_TOOLS: DatabaseTool[] = [
  {
    id: 'prisma',
    name: 'Prisma',
    description: 'Type-safe schema and client for Node.js and TypeScript.',
    iconSrc: '/icons/prisma.svg',
  },
  {
    id: 'drizzle',
    name: 'Drizzle',
    description: 'Lightweight TypeScript ORM with SQL-like query builder.',
    iconSrc: '/icons/drizzle.svg',
  },
  {
    id: 'sequelize',
    name: 'Sequelize',
    description: 'Promise-based ORM for Node.js with multi-dialect support.',
    iconSrc: '/icons/sequelize.svg',
  },
  {
    id: 'typeorm',
    name: 'TypeORM',
    description: 'Active Record and Data Mapper patterns for TypeScript.',
    iconSrc: '/icons/typeorm.svg',
  },
  {
    id: 'sqlalchemy',
    name: 'SQLAlchemy',
    description: 'Python SQL toolkit and ORM for expressive queries.',
    iconSrc: '/icons/sqlalchemy.svg',
  },
  {
    id: 'psql',
    name: 'psql',
    description: 'Official PostgreSQL CLI for ad-hoc SQL and schema exploration.',
    icon: Terminal,
  },
]

const floatingTileClassName =
  'border border-border bg-background shadow-[0_16px_40px_-20px_rgb(0_0_0/0.35)] dark:border-white/10 dark:bg-card'

function ConnectionStringChip() {
  const t = useT()
  return (
    <div className="flex flex-col items-center">
      <div
        className={cn(
          'product-hero-rise product-tone-shadow flex max-w-full items-center gap-3 rounded-full py-1.5 pe-1.5 ps-4',
          floatingTileClassName,
        )}
        style={riseStyle(60)}
      >
        <span className="hidden shrink-0 text-[11px] font-medium text-muted-foreground sm:inline">
          {t('Connection string')}
        </span>
        <span dir="ltr" className="min-w-0 truncate font-mono text-[11px] text-foreground sm:text-[12px]">
          <span className="text-[var(--tone-ink)]">postgresql://</span>
          app:••••@postgres.appwrite.cloud:5432/app
        </span>
        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground" aria-hidden>
          <Copy className="size-3.5" />
        </span>
      </div>
      <div className="relative hidden h-12 w-full lg:block" aria-hidden>
        <span className="absolute start-1/2 top-0 h-1/2 border-s border-dashed border-foreground/20" />
        <span className="absolute inset-x-[16.666%] top-1/2 border-t border-dashed border-foreground/20" />
        <span className="absolute start-[16.666%] top-1/2 h-1/2 border-s border-dashed border-foreground/20" />
        <span className="absolute start-1/2 top-1/2 h-1/2 border-s border-dashed border-foreground/20" />
        <span className="absolute end-[16.666%] top-1/2 h-1/2 border-e border-dashed border-foreground/20" />
      </div>
    </div>
  )
}

type DatabasesOrmCatalogProps = {
  className?: string
}

export function DatabasesOrmCatalog({ className }: DatabasesOrmCatalogProps) {
  const t = useT()
  return (
    <div className={className}>
      <ConnectionStringChip />
      <div className="mt-10 grid gap-x-10 gap-y-9 sm:grid-cols-2 lg:mt-0 lg:grid-cols-3">
        {DATABASE_TOOLS.map((tool, index) => {
          const Lucide = tool.icon
          return (
            <div
              key={tool.id}
              className="product-hero-rise flex flex-col items-center text-center"
              style={riseStyle(200 + index * 90)}
            >
              <span
                className={cn(
                  'product-hero-float flex size-12 items-center justify-center rounded-2xl',
                  floatingTileClassName,
                )}
                style={floatStyle(index * 380)}
              >
                {tool.iconSrc ? (
                  <ProductFeaturePublicIcon src={tool.iconSrc} className="size-5" />
                ) : Lucide ? (
                  <Lucide className="size-5 text-foreground" aria-hidden />
                ) : null}
              </span>
              <h3 className="mt-4 text-[14px] font-semibold text-foreground">{tool.name}</h3>
              <p className="mt-1.5 max-w-[280px] text-[13px] leading-5 text-muted-foreground">
                {t(tool.description)}
              </p>
            </div>
          )
        })}
      </div>
    </div>
  )
}
