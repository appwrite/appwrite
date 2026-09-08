import {
  createStartHandler,
  defaultStreamHandler,
} from '@tanstack/react-start/server'
import { createServerEntry } from '@tanstack/react-start/server-entry'
import { getAssetBase } from './lib/asset-url'

export default createServerEntry({
  fetch: createStartHandler({
    handler: defaultStreamHandler,
    transformAssets: ({ url }) => ({
      href: `${getAssetBase()}${url}`,
      crossOrigin: 'anonymous',
    }),
  }),
})
