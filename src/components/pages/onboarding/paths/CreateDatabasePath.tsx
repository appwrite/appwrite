import { useState } from 'react'
import { useOnboarding } from '../OnboardingLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  Copy,
  Database,
  Plus,
  SkipForward,
} from 'lucide-react'
import { motion } from 'motion/react'
import { codeSnippets } from '../data'

type DatabaseStep =
  | 'create-db'
  | 'create-collection'
  | 'add-document'
  | 'query-documents'

export function CreateDatabasePath() {
  const { state, setSelectedIntent } = useOnboarding()
  const [currentStep, setCurrentStep] = useState<DatabaseStep>('create-db')
  const [copiedSnippet, setCopiedSnippet] = useState<string | null>(null)
  const [databaseName, setDatabaseName] = useState('')
  const [collectionName, setCollectionName] = useState('')
  const [attributes, setAttributes] = useState([
    { name: 'title', type: 'string', required: true },
    { name: 'content', type: 'string', required: false },
  ])

  const copyToClipboard = (text: string, id: string) => {
    navigator.clipboard.writeText(text)
    setCopiedSnippet(id)
    setTimeout(() => setCopiedSnippet(null), 2000)
  }

  const getSnippet = (snippetKey: keyof typeof codeSnippets) => {
    const snippets = codeSnippets[snippetKey] as Record<string, string>
    return snippets[state.selectedSdk] || snippets['web'] || ''
  }

  const renderStepContent = () => {
    switch (currentStep) {
      case 'create-db':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Create a database</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Databases contain collections of documents. Create one to get
                started.
              </p>
            </div>

            {/* Database name input */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Database name</label>
              <Input
                placeholder="My Database"
                value={databaseName}
                onChange={(e) => setDatabaseName(e.target.value)}
              />
            </div>

            {/* Code snippet */}
            <div className="space-y-2">
              <p className="text-sm font-medium">Code</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('createDatabase')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(getSnippet('createDatabase'), 'createDb')
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'createDb' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            {/* Or create via console */}
            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <p className="text-sm font-medium">Or create via console</p>
              <p className="mt-1 text-sm text-muted-foreground">
                You can also create databases directly in the Appwrite console.
              </p>
              <Button variant="outline" size="sm" className="mt-3">
                Open Databases
              </Button>
            </div>
          </div>
        )

      case 'create-collection':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Create a collection</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Collections store documents with a defined schema.
              </p>
            </div>

            {/* Collection name */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Collection name</label>
              <Input
                placeholder="Articles"
                value={collectionName}
                onChange={(e) => setCollectionName(e.target.value)}
              />
            </div>

            {/* Attributes */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <p className="text-sm font-medium">Attributes</p>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 gap-1 text-xs"
                  onClick={() =>
                    setAttributes([
                      ...attributes,
                      { name: '', type: 'string', required: false },
                    ])
                  }
                >
                  <Plus className="h-3 w-3" />
                  Add
                </Button>
              </div>
              <div className="space-y-2">
                {attributes.map((attr, index) => (
                  <div
                    key={index}
                    className="flex items-center gap-2 rounded-lg border border-border p-3"
                  >
                    <Input
                      placeholder="Attribute name"
                      value={attr.name}
                      onChange={(e) => {
                        const newAttrs = [...attributes]
                        newAttrs[index].name = e.target.value
                        setAttributes(newAttrs)
                      }}
                      className="flex-1"
                    />
                    <select
                      value={attr.type}
                      onChange={(e) => {
                        const newAttrs = [...attributes]
                        newAttrs[index].type = e.target.value
                        setAttributes(newAttrs)
                      }}
                      className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                      <option value="string">String</option>
                      <option value="integer">Integer</option>
                      <option value="float">Float</option>
                      <option value="boolean">Boolean</option>
                      <option value="datetime">Datetime</option>
                    </select>
                    <label className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={attr.required}
                        onChange={(e) => {
                          const newAttrs = [...attributes]
                          newAttrs[index].required = e.target.checked
                          setAttributes(newAttrs)
                        }}
                        className="rounded"
                      />
                      Required
                    </label>
                  </div>
                ))}
              </div>
            </div>

            {/* Code snippet */}
            <div className="space-y-2">
              <p className="text-sm font-medium">Code</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('createCollection')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(
                      getSnippet('createCollection'),
                      'createCollection',
                    )
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'createCollection' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>
          </div>
        )

      case 'add-document':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Add a document</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Insert your first document into the collection.
              </p>
            </div>

            {/* Code snippet */}
            <div className="space-y-2">
              <p className="text-sm font-medium">Create document</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('createDocument')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(
                      getSnippet('createDocument'),
                      'createDocument',
                    )
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'createDocument' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <p className="text-sm font-medium">Document IDs</p>
              <p className="mt-1 text-sm text-muted-foreground">
                Use{' '}
                <code className="rounded bg-muted px-1 font-mono text-xs">
                  ID.unique()
                </code>{' '}
                to auto-generate IDs, or provide your own custom ID.
              </p>
            </div>
          </div>
        )

      case 'query-documents':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Query documents</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Fetch and filter documents from your collection.
              </p>
            </div>

            {/* Code snippet */}
            <div className="space-y-2">
              <p className="text-sm font-medium">List documents with queries</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('listDocuments')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(
                      getSnippet('listDocuments'),
                      'listDocuments',
                    )
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'listDocuments' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            {/* Query methods */}
            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <p className="text-sm font-medium">Available query methods</p>
              <div className="mt-3 grid grid-cols-3 gap-2">
                {[
                  'equal',
                  'notEqual',
                  'lessThan',
                  'greaterThan',
                  'search',
                  'orderAsc',
                  'orderDesc',
                  'limit',
                  'offset',
                ].map((method) => (
                  <code
                    key={method}
                    className="rounded bg-muted px-2 py-1 text-center font-mono text-xs"
                  >
                    {method}
                  </code>
                ))}
              </div>
            </div>

            <motion.div
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              className="rounded-lg border border-green-500/30 bg-green-500/10 p-4"
            >
              <div className="flex items-center gap-3">
                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-green-500">
                  <Check className="h-4 w-4 text-white" />
                </div>
                <div>
                  <p className="font-medium text-green-600 dark:text-green-400">
                    Database setup complete!
                  </p>
                  <p className="text-sm text-muted-foreground">
                    You can now store and query data in your app.
                  </p>
                </div>
              </div>
            </motion.div>
          </div>
        )
    }
  }

  const steps: DatabaseStep[] = [
    'create-db',
    'create-collection',
    'add-document',
    'query-documents',
  ]
  const currentStepIndex = steps.indexOf(currentStep)

  return (
    <div>
      {/* Header */}
      <div className="mb-8 flex items-center gap-4">
        <button
          onClick={() => setSelectedIntent(null)}
          className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" />
          Back
        </button>
        <div className="h-4 w-px bg-border" />
        <div className="flex items-center gap-2">
          <Database className="h-4 w-4 text-[#f02e65]" />
          <span className="text-sm font-medium">Create a database</span>
        </div>
      </div>

      {/* Content */}
      <motion.div
        key={currentStep}
        initial={{ opacity: 0, x: 20 }}
        animate={{ opacity: 1, x: 0 }}
        transition={{ duration: 0.2 }}
      >
        {renderStepContent()}
      </motion.div>

      {/* Navigation */}
      <div className="mt-8 flex items-center justify-between border-t border-border pt-6">
        <Button
          variant="ghost"
          onClick={() => {
            if (currentStepIndex > 0) {
              setCurrentStep(steps[currentStepIndex - 1])
            }
          }}
          disabled={currentStepIndex === 0}
          className="gap-2"
        >
          <ArrowLeft className="h-4 w-4" />
          Previous
        </Button>

        <div className="flex gap-3">
          {currentStepIndex < steps.length - 1 && (
            <>
              <Button
                variant="ghost"
                onClick={() => setCurrentStep(steps[currentStepIndex + 1])}
                className="gap-2 text-muted-foreground"
              >
                <SkipForward className="h-4 w-4" />
                Skip
              </Button>
              <Button
                onClick={() => setCurrentStep(steps[currentStepIndex + 1])}
                className="gap-2 bg-[#f02e65] text-white hover:bg-[#f02e65]/90"
              >
                Continue
                <ArrowRight className="h-4 w-4" />
              </Button>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
