import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { AlertCircle, CheckCircle, Loader2, Ban } from 'lucide-react'
import { api } from '@/lib/api'

export default function BlockAddressPage() {
  const [searchParams] = useSearchParams()
  const emailFromUrl = searchParams.get('email') || ''
  
  const [email, setEmail] = useState(emailFromUrl)
  const [loading, setLoading] = useState(false)
  const [success, setSuccess] = useState(false)
  const [error, setError] = useState('')

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError('')

    try {
      const response = await api.post('/abuse/block', { email })

      if (response.data.success) {
        setSuccess(true)
      } else {
        setError(response.data.message || '操作失败，请稍后重试')
      }
    } catch (err: any) {
      setError(err.response?.data?.message || '操作失败，请稍后重试')
    } finally {
      setLoading(false)
    }
  }

  if (success) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 flex items-center justify-center p-4">
        <Card className="max-w-md w-full">
          <CardHeader className="text-center">
            <div className="mx-auto w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mb-4">
              <CheckCircle className="w-6 h-6 text-green-600" />
            </div>
            <CardTitle>Email Blocked</CardTitle>
            <CardDescription>
              Your email address <strong className="text-slate-900">{email}</strong> has been successfully blocked.
              <br />
              You will no longer receive any emails from us.
            </CardDescription>
          </CardHeader>
        </Card>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 flex items-center justify-center p-4">
      <Card className="max-w-md w-full">
        <CardHeader>
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
              <Ban className="w-5 h-5 text-red-600" />
            </div>
            <div>
              <CardTitle>Block Email Address</CardTitle>
              <CardDescription>
                Once blocked, you will no longer receive any emails from us
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="email">Email Address *</Label>
              <Input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="your@email.com"
                required
                disabled={!!emailFromUrl}
              />
              {emailFromUrl && (
                <p className="text-xs text-muted-foreground">
                  Email address has been automatically filled from URL
                </p>
              )}
            </div>

            {error && (
              <div className="flex items-start gap-2 p-3 bg-red-50 border border-red-200 rounded-md">
                <AlertCircle className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" />
                <p className="text-sm text-red-600">{error}</p>
              </div>
            )}

            <div className="bg-amber-50 border border-amber-200 rounded-md p-3">
              <p className="text-sm text-amber-800">
                <strong>Warning:</strong> This action is irreversible. Once blocked, you will not be able to receive any email notifications from us.
              </p>
            </div>

            <Button
              type="submit"
              disabled={loading}
              variant="destructive"
              className="w-full"
            >
              {loading ? (
                <>
                  <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                  Processing...
                </>
              ) : (
                <>
                  <Ban className="w-4 h-4 mr-2" />
                  Confirm Block
                </>
              )}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}

