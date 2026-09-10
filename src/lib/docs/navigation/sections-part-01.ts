import type { DocsSectionNavConfig } from './sections-types'

export const navPart1_0 =   {
    prefix: 'apis/realtime',
    parent: {
      href: '/docs/apis',
      label: 'Realtime',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/apis/realtime',
          },
          {
            label: 'Authentication',
            href: '/docs/apis/realtime/authentication',
          },
          {
            label: 'Subscribe',
            href: '/docs/apis/realtime/subscribe',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Channels',
            href: '/docs/apis/realtime/channels',
          },
          {
            label: 'Queries',
            href: '/docs/apis/realtime/queries',
          },
          {
            label: 'Payload',
            href: '/docs/apis/realtime/payload',
          },
          {
            label: 'Presences',
            href: '/docs/apis/realtime/presences',
          },
        ],
      },
      {
        label: 'Configuration',
        items: [
          {
            label: 'Custom endpoint',
            href: '/docs/apis/realtime/custom-endpoint',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart1_1 =   {
    prefix: 'partners/apps',
    parent: {
      href: '/docs',
      label: 'Apps',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/partners/apps',
          },
          {
            label: 'Quick start',
            href: '/docs/partners/apps/quick-start',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Registration',
            href: '/docs/partners/apps/registration',
          },
          {
            label: 'Scopes',
            href: '/docs/partners/apps/scopes',
          },
          {
            label: 'Consent',
            href: '/docs/partners/apps/consent',
          },
          {
            label: 'Tokens',
            href: '/docs/partners/apps/tokens',
          },
          {
            label: 'Installations',
            href: '/docs/partners/apps/installations',
          },
          {
            label: 'Device flow',
            href: '/docs/partners/apps/device-flow',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart1_2 =   {
    prefix: 'partners/project',
    parent: {
      href: '/docs/partners',
      label: 'Project',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/partners/project',
          },
          {
            label: 'Create a project',
            href: '/docs/partners/project/create',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Auth methods',
            href: '/docs/partners/project/auth-methods',
          },
          {
            label: 'OAuth providers',
            href: '/docs/partners/project/oauth',
          },
          {
            label: 'API keys',
            href: '/docs/partners/project/api-keys',
          },
          {
            label: 'Platforms',
            href: '/docs/partners/project/platforms',
          },
          {
            label: 'Protocols',
            href: '/docs/partners/project/protocols',
          },
          {
            label: 'Services',
            href: '/docs/partners/project/services',
          },
          {
            label: 'Policies',
            href: '/docs/partners/project/policies',
          },
          {
            label: 'Mock phones',
            href: '/docs/partners/project/mock-phones',
          },
          {
            label: 'Environment variables',
            href: '/docs/partners/project/environment-variables',
          },
          {
            label: 'SMTP',
            href: '/docs/partners/project/smtp',
          },
          {
            label: 'Email templates',
            href: '/docs/partners/project/email-templates',
          },
          {
            label: 'Labels',
            href: '/docs/partners/project/labels',
          },
        ],
      },
      {
        label: 'Guides',
        items: [
          {
            label: 'Provisioning',
            href: '/docs/partners/project/provisioning',
          },
          {
            label: 'Key rotation',
            href: '/docs/partners/project/key-rotation',
          },
          {
            label: 'Branded emails',
            href: '/docs/partners/project/branded-emails',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig

export const navPart1_3 =   {
    prefix: 'products/ai',
    parent: {
      href: '/docs',
      label: 'AI',
    },
    navigation: [
      {
        label: 'Getting started',
        items: [
          {
            label: 'Overview',
            href: '/docs/products/ai',
          },
        ],
      },
      {
        label: 'Concepts',
        items: [
          {
            label: 'Computer vision',
            href: '/docs/products/ai/computer-vision',
          },
          {
            label: 'Natural language processing',
            href: '/docs/products/ai/natural-language',
          },
          {
            label: 'Audio processing',
            href: '/docs/products/ai/audio-processing',
          },
        ],
      },
      {
        label: 'Computer vision',
        items: [
          {
            label: 'Image classification',
            href: '/docs/products/ai/tutorials/image-classification',
          },
          {
            label: 'Object detection',
            href: '/docs/products/ai/tutorials/object-detection',
          },
        ],
      },
      {
        label: 'Natural language processing',
        items: [
          {
            label: 'Text generation',
            href: '/docs/products/ai/tutorials/text-generation',
          },
          {
            label: 'Language translation',
            href: '/docs/products/ai/tutorials/language-translation',
          },
        ],
      },
      {
        label: 'Audio processing',
        items: [
          {
            label: 'Speech recognition',
            href: '/docs/products/ai/tutorials/speech-recognition',
          },
          {
            label: 'Text to speech',
            href: '/docs/products/ai/tutorials/text-to-speech',
          },
          {
            label: 'Music generation',
            href: '/docs/products/ai/tutorials/music-generation',
          },
        ],
      },
      {
        label: 'Integrations',
        items: [
          {
            label: 'Perplexity',
            href: '/docs/products/ai/integrations/perplexity',
          },
          {
            label: 'Replicate',
            href: '/docs/products/ai/integrations/replicate',
          },
          {
            label: 'OpenAI',
            href: '/docs/products/ai/integrations/openai',
          },
          {
            label: 'Pinecone',
            href: '/docs/products/ai/integrations/pinecone',
          },
          {
            label: 'ElevenLabs',
            href: '/docs/products/ai/integrations/elevenlabs',
          },
          {
            label: 'LangChain',
            href: '/docs/products/ai/integrations/langchain',
          },
          {
            label: 'Anyscale',
            href: '/docs/products/ai/integrations/anyscale',
          },
          {
            label: 'LMNT',
            href: '/docs/products/ai/integrations/lmnt',
          },
          {
            label: 'Together AI',
            href: '/docs/products/ai/integrations/togetherai',
          },
          {
            label: 'fal.ai',
            href: '/docs/products/ai/integrations/fal-ai',
          },
        ],
      },
    ],
  } satisfies DocsSectionNavConfig
