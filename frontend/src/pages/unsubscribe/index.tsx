import { useState, useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'
import axios from 'axios'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Mail, CheckCircle2, XCircle, Loader2 } from 'lucide-react'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'

export default function UnsubscribePage() {
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token')
  
  const [status, setStatus] = useState<'loading' | 'confirm' | 'success' | 'error' | 'already_unsubscribed'>('loading')
  const [message, setMessage] = useState('')
  const [subscriberEmail, setSubscriberEmail] = useState('')
  const [isUnsubscribing, setIsUnsubscribing] = useState(false)

  useEffect(() => {
    if (!token) {
      setStatus('error')
      setMessage('Invalid unsubscribe link')
      return
    }

    // Fetch unsubscribe info
    axios.get(`${API_URL}/unsubscribe`, {
      params: { token }
    })
      .then(response => {
        const data = response.data
        setSubscriberEmail(data.subscriber?.email || '')
        
        if (data.status === 'already_unsubscribed') {
          setStatus('already_unsubscribed')
          setMessage(data.message)
        } else {
          setStatus('confirm')
        }
      })
      .catch(error => {
        setStatus('error')
        setMessage(error.response?.data?.message || 'Invalid unsubscribe link')
      })
  }, [token])

  const handleUnsubscribe = async () => {
    if (!token) return

    setIsUnsubscribing(true)
    
    try {
      const response = await axios.post(`${API_URL}/unsubscribe`, { token })
      setStatus('success')
      setMessage(response.data.message)
    } catch (error: any) {
      setStatus('error')
      setMessage(error.response?.data?.message || 'Failed to unsubscribe. Please try again later.')
    } finally {
      setIsUnsubscribing(false)
    }
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center p-4">
      <Card className="w-full max-w-md">
        {status === 'loading' && (
          <>
            <CardHeader className="text-center">
              <div className="mx-auto w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mb-4">
                <Loader2 className="w-6 h-6 text-blue-600 animate-spin" />
              </div>
              <CardTitle>Loading...</CardTitle>
            </CardHeader>
          </>
        )}

        {status === 'confirm' && (
          <>
            <CardHeader className="text-center">
              <div className="mx-auto w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mb-4">
                <Mail className="w-6 h-6 text-blue-600" />
              </div>
              <CardTitle>Confirm Unsubscribe</CardTitle>
              <CardDescription>
                Are you sure you want to unsubscribe?
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {subscriberEmail && (
                <div className="bg-gray-50 rounded-lg p-4">
                  <div className="text-sm">
                    <span className="text-gray-500">Email: </span>
                    <span className="font-medium">{subscriberEmail}</span>
                  </div>
                </div>
              )}
              
              <div className="text-sm text-gray-600">
                <p>After unsubscribing, you will no longer receive emails from this mailing list.</p>
              </div>

              <Button
                className="w-full"
                variant="destructive"
                onClick={handleUnsubscribe}
                disabled={isUnsubscribing}
              >
                {isUnsubscribing ? (
                  <>
                    <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                    Processing...
                  </>
                ) : (
                  'Confirm Unsubscribe'
                )}
              </Button>
            </CardContent>
          </>
        )}

        {status === 'success' && (
          <>
            <CardHeader className="text-center">
              <div className="mx-auto w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mb-4">
                <CheckCircle2 className="w-6 h-6 text-green-600" />
              </div>
              <CardTitle>Successfully Unsubscribed</CardTitle>
              <CardDescription>
                You have been successfully unsubscribed
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {subscriberEmail && (
                <div className="bg-green-50 rounded-lg p-4 text-center">
                  <p className="text-sm text-green-700">
                    Email <span className="font-medium">{subscriberEmail}</span> has been removed from the mailing list
                  </p>
                </div>
              )}
              
              <p className="text-sm text-gray-600 text-center">
                You will no longer receive emails from this list.
              </p>
            </CardContent>
          </>
        )}

        {status === 'already_unsubscribed' && (
          <>
            <CardHeader className="text-center">
              <div className="mx-auto w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center mb-4">
                <Mail className="w-6 h-6 text-yellow-600" />
              </div>
              <CardTitle>Already Unsubscribed</CardTitle>
              <CardDescription>
                You have already unsubscribed
              </CardDescription>
            </CardHeader>
            <CardContent className="text-center">
              <p className="text-sm text-gray-600">
                You will not receive emails from this list.
              </p>
            </CardContent>
          </>
        )}

        {status === 'error' && (
          <>
            <CardHeader className="text-center">
              <div className="mx-auto w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mb-4">
                <XCircle className="w-6 h-6 text-red-600" />
              </div>
              <CardTitle>Error</CardTitle>
              <CardDescription>
                {message || 'Invalid unsubscribe link'}
              </CardDescription>
            </CardHeader>
            <CardContent className="text-center">
              <p className="text-sm text-gray-600">
                If you still wish to unsubscribe, please contact our support team.
              </p>
            </CardContent>
          </>
        )}
      </Card>
    </div>
  )
}

