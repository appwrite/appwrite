/**
 * Default getting started Appwrite function template.
 */
export const GETTING_STARTED_MAIN_JS = `export default async ({ req, res, log, error }) => {
  // Log incoming request
  log('Request received: ' + req.method);

  // Parse request body if present
  const body = req.body ? JSON.parse(req.body) : {};

  // Your function logic here
  const result = {
    message: 'Hello from Appwrite Functions!',
    timestamp: Date.now(),
    input: body
  };

  // Return JSON response
  return res.json(result);
};
`

export const GETTING_STARTED_PACKAGE_JSON = `{
  "name": "appwrite-function",
  "version": "1.0.0",
  "main": "src/main.js"
}
`

export const DEFAULT_FILES: Record<string, string> = {
  'src/main.js': GETTING_STARTED_MAIN_JS,
  'package.json': GETTING_STARTED_PACKAGE_JSON,
}

/** Empty minimal function (no body parsing). */
const EMPTY_MAIN_JS = `export default async ({ req, res, log, error }) => {
  log('Request: ' + req.method + ' ' + req.path);
  return res.json({ ok: true });
};
`

/** Hello world – returns a simple message. */
const HELLO_WORLD_MAIN_JS = `export default async ({ req, res, log }) => {
  log('Hello from Appwrite!');
  return res.json({
    message: 'Hello, World!',
    time: new Date().toISOString(),
  });
};
`

/** Webhook-style – parse JSON body and echo. */
const WEBHOOK_MAIN_JS = `export default async ({ req, res, log, error }) => {
  let body = {};
  try {
    if (req.body) body = JSON.parse(req.body);
  } catch (e) {
    error('Invalid JSON body');
    return res.json({ error: 'Invalid JSON' }, 400);
  }
  log('Webhook received:', JSON.stringify(body));
  return res.json({ received: body });
};
`

const PACKAGE_JSON = `{
  "name": "appwrite-function",
  "version": "1.0.0",
  "main": "src/main.js"
}
`

export type EditorTemplate = {
  id: string
  label: string
  files: Record<string, string>
}

export const EDITOR_TEMPLATES: EditorTemplate[] = [
  {
    id: 'getting-started',
    label: 'Getting started',
    files: {
      'src/main.js': GETTING_STARTED_MAIN_JS,
      'package.json': GETTING_STARTED_PACKAGE_JSON,
    },
  },
  {
    id: 'empty',
    label: 'Empty',
    files: {
      'src/main.js': EMPTY_MAIN_JS,
      'package.json': PACKAGE_JSON,
    },
  },
  {
    id: 'hello-world',
    label: 'Hello world',
    files: {
      'src/main.js': HELLO_WORLD_MAIN_JS,
      'package.json': PACKAGE_JSON,
    },
  },
  {
    id: 'webhook',
    label: 'Webhook',
    files: {
      'src/main.js': WEBHOOK_MAIN_JS,
      'package.json': PACKAGE_JSON,
    },
  },
]
