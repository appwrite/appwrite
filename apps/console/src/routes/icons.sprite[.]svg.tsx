import { createFileRoute } from '@tanstack/react-router'
import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { spriteIcons } from '@/lib/icons/sprite'

let sprite: string | null = null

function getSprite(): string {
  sprite ??= [
    '<svg xmlns="http://www.w3.org/2000/svg">',
    ...[...spriteIcons].map(([id, icon]) => {
      const markup = renderToStaticMarkup(createElement(icon))
      const paths = markup.slice(
        markup.indexOf('>') + 1,
        markup.lastIndexOf('</svg>'),
      )
      return `<symbol id="${id}" viewBox="0 0 24 24">${paths}</symbol>`
    }),
    '</svg>',
  ].join('')
  return sprite
}

/** Lucide symbols for `SpriteIcon`, see `@/lib/icons/sprite`. */
export const Route = createFileRoute('/icons/sprite.svg')({
  server: {
    handlers: {
      GET: () =>
        new Response(getSprite(), {
          headers: {
            'Content-Type': 'image/svg+xml',
            'Cache-Control': 'public, max-age=3600',
          },
        }),
    },
  },
})
