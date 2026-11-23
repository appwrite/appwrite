import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { useT } from '@/lib/i18n/translate'

interface CreateUserDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (userData: {
    userId?: string
    email?: string
    phone?: string
    password?: string
    name?: string
  }) => void
  isLoading?: boolean
}

export function CreateUserDrawer({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
}: CreateUserDrawerProps) {
  const t = useT()
  const [userId, setUserId] = useState<string | undefined>(undefined)
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [password, setPassword] = useState('')
  const [name, setName] = useState('')

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setUserId(undefined)
      setEmail('')
      setPhone('')
      setPassword('')
      setName('')
    }
  }, [open])

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    onCreate({
      userId,
      email: email.trim() || undefined,
      phone: phone.trim() || undefined,
      password: password.trim() || undefined,
      name: name.trim() || undefined,
    })
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={t('Create User')}
      maxWidth="sm:max-w-lg"
    >
      <>
        <div className="border-t border-border shrink-0" />

        <form onSubmit={handleSubmit} className="flex flex-1 flex-col min-h-0">
          <div className="flex-1 overflow-y-auto">
            <div className="px-6 py-6">
              <div className="space-y-5">
                <div className="space-y-2">
                  <Label htmlFor="user-id">{t('User ID')}</Label>
                  <IdInput
                    id="user-id"
                    value={userId}
                    onChange={setUserId}
                    maxLength={36}
                    disabled={isLoading}
                    placeholder={t('Leave blank to auto-generate')}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="name">{t('Name')}</Label>
                  <Input
                    id="name"
                    type="text"
                    placeholder={t('John Doe')}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    disabled={isLoading}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="email">{t('Email')}</Label>
                  <Input
                    id="email"
                    type="email"
                    placeholder="user@example.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    disabled={isLoading}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="phone">{t('Phone')}</Label>
                  <Input
                    id="phone"
                    type="tel"
                    placeholder="+1234567890"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    disabled={isLoading}
                  />
                  <p className="text-[12px] text-muted-foreground">
                    {t(
                      "Format with a leading '+' and country code, e.g., +16175551212",
                    )}
                  </p>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="password">{t('Password')}</Label>
                  <Input
                    id="password"
                    type="password"
                    placeholder={t('At least 8 characters')}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    disabled={isLoading}
                  />
                  <p className="text-[12px] text-muted-foreground">
                    {t(
                      'Optional. If not provided, user will need to set password via recovery.',
                    )}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <div className="flex-shrink-0 flex items-center justify-start gap-2 border-t border-border bg-muted/30 px-6 py-4">
            <Button type="submit" disabled={isLoading}>
              {t('Create User')}
            </Button>
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isLoading}
            >
              {t('Cancel')}
            </Button>
          </div>
        </form>
      </>
    </BaseDrawer>
  )
}
