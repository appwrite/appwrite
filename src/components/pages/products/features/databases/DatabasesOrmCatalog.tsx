import { Terminal, type LucideIcon } from 'lucide-react'
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

type DatabasesOrmCatalogProps = {
  className?: string
}

export function DatabasesOrmCatalog({ className }: DatabasesOrmCatalogProps) {
  const t = useT()
  return (
    <div
      className={cn(
        'grid overflow-hidden rounded-xl border border-border bg-card/45 sm:grid-cols-2 lg:grid-cols-3',
        className,
      )}
    >
      {DATABASE_TOOLS.map((tool, index) => {
        const Lucide = tool.icon
        return (
          <div
            key={tool.id}
            className={cn(
              'p-4 sm:p-5',
              index < DATABASE_TOOLS.length - 1 && 'border-b border-border sm:border-b',
              index % 2 === 0 && index < DATABASE_TOOLS.length - 1 && 'sm:border-e',
              index < 3 && 'lg:border-b',
              index % 3 !== 2 && 'lg:border-e',
              index >= 3 && 'lg:border-b-0',
              index === 4 && 'sm:border-e-0 lg:border-e',
              index === 5 && 'border-b-0 sm:border-b-0',
            )}
          >
            <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
              {tool.iconSrc ? (
                <ProductFeaturePublicIcon src={tool.iconSrc} />
              ) : Lucide ? (
                <Lucide className="size-3.5 text-muted-foreground" aria-hidden />
              ) : null}
            </span>
            <h3 className="mt-3 text-[14px] font-semibold text-foreground">{tool.name}</h3>
            <p className="mt-1.5 text-[13px] leading-5 text-muted-foreground">
              {t(tool.description)}
            </p>
          </div>
        )
      })}
    </div>
  )
}
