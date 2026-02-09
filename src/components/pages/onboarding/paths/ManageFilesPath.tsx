import { useState } from 'react'
import { useOnboarding } from '../OnboardingLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  Copy,
  Folder,
  SkipForward,
  Upload,
} from 'lucide-react'
import { motion } from 'motion/react'
import { codeSnippets } from '../data'

type StorageStep = 'create-bucket' | 'upload-file' | 'get-file' | 'file-preview'

export function ManageFilesPath() {
  const { state, setSelectedIntent } = useOnboarding()
  const [currentStep, setCurrentStep] = useState<StorageStep>('create-bucket')
  const [copiedSnippet, setCopiedSnippet] = useState<string | null>(null)
  const [bucketName, setBucketName] = useState('')

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
      case 'create-bucket':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Create a storage bucket</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Buckets organize your files and define access permissions.
              </p>
            </div>

            {/* Bucket name */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Bucket name</label>
              <Input
                placeholder="Images"
                value={bucketName}
                onChange={(e) => setBucketName(e.target.value)}
              />
            </div>

            {/* Permissions */}
            <div className="space-y-3">
              <p className="text-sm font-medium">Permissions</p>
              <div className="space-y-2">
                {[
                  {
                    id: 'read',
                    label: 'Read',
                    desc: 'Allow users to view files',
                  },
                  {
                    id: 'create',
                    label: 'Create',
                    desc: 'Allow users to upload files',
                  },
                  {
                    id: 'update',
                    label: 'Update',
                    desc: 'Allow users to modify files',
                  },
                  {
                    id: 'delete',
                    label: 'Delete',
                    desc: 'Allow users to remove files',
                  },
                ].map((perm) => (
                  <label
                    key={perm.id}
                    className="flex items-center gap-3 rounded-lg border border-border p-3"
                  >
                    <input
                      type="checkbox"
                      className="rounded"
                      defaultChecked={perm.id === 'read'}
                    />
                    <div>
                      <p className="text-sm font-medium">{perm.label}</p>
                      <p className="text-xs text-muted-foreground">
                        {perm.desc}
                      </p>
                    </div>
                  </label>
                ))}
              </div>
            </div>

            {/* Code snippet */}
            <div className="space-y-2">
              <p className="text-sm font-medium">Code</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('createBucket')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(getSnippet('createBucket'), 'createBucket')
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'createBucket' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>
          </div>
        )

      case 'upload-file':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Upload a file</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Upload your first file to the storage bucket.
              </p>
            </div>

            {/* Upload area */}
            <div className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-border p-8">
              <Upload className="h-10 w-10 text-muted-foreground" />
              <p className="mt-3 text-sm font-medium">
                Drop files here or click to upload
              </p>
              <p className="mt-1 text-xs text-muted-foreground">
                PNG, JPG, PDF up to 10MB
              </p>
              <Button variant="outline" size="sm" className="mt-4">
                Select file
              </Button>
            </div>

            {/* Code snippet */}
            <div className="space-y-2">
              <p className="text-sm font-medium">Code</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('uploadFile')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(getSnippet('uploadFile'), 'uploadFile')
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'uploadFile' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <p className="text-sm font-medium">File size limits</p>
              <p className="mt-1 text-sm text-muted-foreground">
                Maximum file size depends on your plan. Free tier supports up to
                50MB per file.
              </p>
            </div>
          </div>
        )

      case 'get-file':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Retrieve files</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Get file metadata and download URLs.
              </p>
            </div>

            {/* Code snippet */}
            <div className="space-y-2">
              <p className="text-sm font-medium">Get file</p>
              <div className="group relative">
                <pre className="overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 font-mono text-sm">
                  <code>{getSnippet('getFile')}</code>
                </pre>
                <button
                  onClick={() =>
                    copyToClipboard(getSnippet('getFile'), 'getFile')
                  }
                  className="absolute right-2 top-2 rounded-md p-2 text-muted-foreground opacity-0 transition-opacity hover:bg-accent hover:text-foreground group-hover:opacity-100"
                >
                  {copiedSnippet === 'getFile' ? (
                    <Check className="h-4 w-4 text-green-500" />
                  ) : (
                    <Copy className="h-4 w-4" />
                  )}
                </button>
              </div>
            </div>

            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <p className="text-sm font-medium">File metadata</p>
              <div className="mt-3 space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">$id</span>
                  <span>Unique file identifier</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">name</span>
                  <span>Original filename</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">mimeType</span>
                  <span>File content type</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">sizeOriginal</span>
                  <span>File size in bytes</span>
                </div>
              </div>
            </div>
          </div>
        )

      case 'file-preview':
        return (
          <div className="space-y-6">
            <div>
              <h2 className="text-lg font-medium">Generate previews</h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Create thumbnails and transformed versions of images.
              </p>
            </div>

            {/* Preview options */}
            <div className="grid grid-cols-3 gap-4">
              {[
                { label: 'Thumbnail', size: '100x100' },
                { label: 'Medium', size: '400x400' },
                { label: 'Large', size: '800x800' },
              ].map((preset) => (
                <div
                  key={preset.label}
                  className="flex flex-col items-center rounded-lg border border-border p-4"
                >
                  <div className="flex h-16 w-16 items-center justify-center rounded bg-muted text-xs text-muted-foreground">
                    {preset.size}
                  </div>
                  <p className="mt-2 text-sm font-medium">{preset.label}</p>
                </div>
              ))}
            </div>

            {/* Transformation options */}
            <div className="rounded-lg border border-border bg-muted/30 p-4">
              <p className="text-sm font-medium">Available transformations</p>
              <div className="mt-3 flex flex-wrap gap-2">
                {[
                  'width',
                  'height',
                  'gravity',
                  'quality',
                  'borderWidth',
                  'borderColor',
                  'borderRadius',
                  'opacity',
                  'rotation',
                  'background',
                  'output',
                ].map((opt) => (
                  <code
                    key={opt}
                    className="rounded bg-muted px-2 py-1 font-mono text-xs"
                  >
                    {opt}
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
                    Storage setup complete!
                  </p>
                  <p className="text-sm text-muted-foreground">
                    You can now upload and manage files in your app.
                  </p>
                </div>
              </div>
            </motion.div>
          </div>
        )
    }
  }

  const steps: StorageStep[] = [
    'create-bucket',
    'upload-file',
    'get-file',
    'file-preview',
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
          <Folder className="h-4 w-4 text-[#f02e65]" />
          <span className="text-sm font-medium">Upload and manage files</span>
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
