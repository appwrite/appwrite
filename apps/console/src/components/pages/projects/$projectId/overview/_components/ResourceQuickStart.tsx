import { useState, type ReactNode } from 'react'
import { Link, type LinkProps } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { Link2, Table2, Zap } from 'lucide-react'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import {
  MySQLDolphinIcon,
  PostgresElephantIcon,
} from '../../databases/_components/database-mascot-icons'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { isCloudDedicatedDatabasesEnabled } from '@/lib/database-routes'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import type { OverviewResourceId } from '@/lib/overview-resources'

type QuickStartTile = {
  key: string
  /** Proper noun or API name; shown as is. */
  label: string
  visual: ReactNode
  link?: LinkProps
}

const DOT_GRID_STYLE = {
  backgroundImage:
    'radial-gradient(color-mix(in oklch, var(--muted-foreground) 28%, transparent) 1px, transparent 1px)',
  backgroundSize: '10px 10px',
  maskImage: 'radial-gradient(ellipse at center, black 35%, transparent 85%)',
  WebkitMaskImage:
    'radial-gradient(ellipse at center, black 35%, transparent 85%)',
}

/** Templates with screenshots bundled in `public/images/sites/templates`. */
const SITE_TEMPLATES = [
  { key: 'template-for-store', label: 'Store template' },
  { key: 'template-for-blog', label: 'Blog template' },
  { key: 'template-for-portfolio', label: 'Portfolio template' },
]

/** The starter function template supports each of these runtimes. */
const FUNCTION_RUNTIMES = [
  { runtime: 'node', label: 'Node.js' },
  { runtime: 'python', label: 'Python' },
  { runtime: 'go', label: 'Go' },
]

const FUNCTION_STARTER_TEMPLATE_ID = 'starter'

const PITCH: Record<'databases' | 'sites' | 'functions', string> = {
  databases: 'Store, query, and sync your app data with a managed database.',
  sites: 'Deploy a web app from Git, or start from a ready-made template.',
  functions: 'Run backend code on HTTP requests, events, or a schedule.',
}

/**
 * Body of an empty primary card: a one-line pitch and three quick starts that
 * open the matching create flow one step in.
 */
export function ResourceQuickStart({
  id,
  projectId,
  canCreate,
}: {
  id: Extract<OverviewResourceId, 'databases' | 'sites' | 'functions'>
  projectId: string
  canCreate: boolean
}) {
  const t = useT()
  const { resolvedTheme } = useTheme()
  const isDark = resolvedTheme !== 'light'
  const tiles =
    id === 'databases'
      ? databaseTiles(projectId, t)
      : id === 'sites'
        ? siteTiles(projectId, isDark)
        : functionTiles(projectId)

  return (
    <div className="flex flex-1 flex-col gap-3 px-2.5 pb-1.5 pt-2">
      <p className="line-clamp-2 text-[12px] leading-5 text-muted-foreground">
        {t(PITCH[id])}
      </p>
      <div className="mt-auto grid grid-cols-3 gap-2">
        {tiles.map((tile) => (
          <QuickStartTileLink
            key={tile.key}
            tile={tile}
            disabled={!canCreate || !tile.link}
          />
        ))}
      </div>
    </div>
  )
}

function QuickStartTileLink({
  tile,
  disabled,
}: {
  tile: QuickStartTile
  disabled: boolean
}) {
  const content = (
    <>
      <span
        className={cn(
          'relative block aspect-[16/10] w-full overflow-hidden rounded-lg border border-border bg-muted/30 transition-colors',
          !disabled && 'group-hover/tile:border-foreground/25',
        )}
      >
        {tile.visual}
      </span>
      <span
        className={cn(
          'mt-1.5 block truncate text-[12px] text-muted-foreground transition-colors',
          !disabled && 'group-hover/tile:text-foreground',
        )}
        title={tile.label}
      >
        {tile.label}
      </span>
    </>
  )
  if (disabled || !tile.link) {
    return <div className="min-w-0 opacity-70">{content}</div>
  }
  return (
    <Link
      {...tile.link}
      className="group/tile min-w-0 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
      {...analyticsAttrs('overview-quick-start')}
    >
      {content}
    </Link>
  )
}

const TILE_ICON_CLASSNAME = 'h-5 w-5'

/** Icon chip over a dot grid, lifted on hover. */
function IconTileVisual({ children }: { children: ReactNode }) {
  return (
    <>
      <span aria-hidden className="absolute inset-0" style={DOT_GRID_STYLE} />
      <span className="absolute inset-0 flex items-center justify-center">
        <span className="flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-card text-muted-foreground shadow-md transition-[transform,color] duration-200 group-hover/tile:-translate-y-0.5 group-hover/tile:text-foreground motion-reduce:transform-none">
          {children}
        </span>
      </span>
    </>
  )
}

function TemplateScreenshot({ src, alt }: { src: string; alt: string }) {
  const [loaded, setLoaded] = useState(false)
  return (
    <img
      src={src}
      alt={alt}
      loading="lazy"
      onLoad={() => setLoaded(true)}
      className={cn(
        'absolute inset-0 h-full w-full object-cover object-top transition-[opacity,transform] duration-300 group-hover/tile:scale-[1.04] motion-reduce:transform-none',
        loaded ? 'opacity-100' : 'opacity-0',
      )}
    />
  )
}

function databaseTiles(
  projectId: string,
  t: (text: string) => string,
): QuickStartTile[] {
  if (!isCloudDedicatedDatabasesEnabled()) {
    // Self-hosted creates TablesDB only, so the tiles show what it does.
    return [
      {
        key: 'tables',
        label: 'TablesDB',
        visual: (
          <IconTileVisual>
            <Table2 className={TILE_ICON_CLASSNAME} />
          </IconTileVisual>
        ),
      },
      {
        key: 'relationships',
        label: t('Relationships'),
        visual: (
          <IconTileVisual>
            <Link2 className={TILE_ICON_CLASSNAME} />
          </IconTileVisual>
        ),
      },
      {
        key: 'realtime',
        label: t('Realtime'),
        visual: (
          <IconTileVisual>
            <Zap className={TILE_ICON_CLASSNAME} />
          </IconTileVisual>
        ),
      },
    ]
  }
  const wizard = (type: string): LinkProps => ({
    to: '/projects/$projectId/databases/create',
    params: { projectId },
    search: { type },
  })
  return [
    {
      key: 'tablesdb',
      label: 'TablesDB',
      visual: (
        <IconTileVisual>
          <Table2 className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link: wizard('tablesdb'),
    },
    {
      key: 'postgres',
      label: 'PostgreSQL',
      visual: (
        <IconTileVisual>
          <PostgresElephantIcon className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link: wizard('postgres'),
    },
    {
      key: 'mysql',
      label: 'MySQL',
      visual: (
        <IconTileVisual>
          <MySQLDolphinIcon className={TILE_ICON_CLASSNAME} />
        </IconTileVisual>
      ),
      link: wizard('mysql'),
    },
  ]
}

function siteTiles(projectId: string, isDark: boolean): QuickStartTile[] {
  return SITE_TEMPLATES.map((template) => ({
    key: template.key,
    label: template.label,
    visual: (
      <TemplateScreenshot
        src={`/images/sites/templates/${template.key}-${isDark ? 'dark' : 'light'}.png`}
        alt=""
      />
    ),
    link: {
      to: '/projects/$projectId/sites/create/templates/$template',
      params: { projectId, template: encodeURIComponent(template.key) },
    },
  }))
}

function functionTiles(projectId: string): QuickStartTile[] {
  return FUNCTION_RUNTIMES.map(({ runtime, label }) => ({
    key: runtime,
    label,
    visual: (
      <IconTileVisual>
        <RuntimeIcon
          runtime={runtime}
          size="md"
          className={TILE_ICON_CLASSNAME}
        />
      </IconTileVisual>
    ),
    link: {
      to: '/projects/$projectId/functions/create/template/$templateId',
      params: { projectId, templateId: FUNCTION_STARTER_TEMPLATE_ID },
      search: { runtime },
    },
  }))
}
