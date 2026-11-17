import { useEffect, useState } from 'react'
import { Globe2, Loader2, RefreshCw } from 'lucide-react'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

interface DomainValuesResponse {
  data: {
    name: string
    values: string[]
    updated_at: string | null
  }
}

export default function DomainValuesPage() {
  const [values, setValues] = useState<string[]>([])
  const [updatedAt, setUpdatedAt] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const loadValues = async () => {
    setLoading(true)
    setError('')

    try {
      const response = await api.get<DomainValuesResponse>('/public/domain-values')
      setValues(response.data.data.values)
      setUpdatedAt(response.data.data.updated_at)
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'DOMAIN 标签加载失败，请稍后重试')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadValues()
  }, [])

  return (
    <main className="min-h-screen bg-slate-950 px-4 py-12 text-slate-100 sm:px-6">
      <div className="mx-auto w-full max-w-4xl">
        <div className="mb-8 flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-500/15 ring-1 ring-blue-400/30">
            <Globe2 className="h-6 w-6 text-blue-300" />
          </div>
          <div>
            <p className="text-sm font-medium uppercase tracking-[0.24em] text-blue-300">SendWalk</p>
            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">DOMAIN 标签</h1>
          </div>
        </div>

        <Card className="border-slate-800 bg-slate-900/80 text-slate-100 shadow-2xl shadow-black/20">
          <CardHeader className="border-b border-slate-800">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <CardTitle className="text-xl">标签值列表</CardTitle>
                <CardDescription className="mt-1 text-slate-400">
                  当前共 {loading ? '—' : values.length} 个值
                  {updatedAt ? ` · 更新于 ${new Date(updatedAt).toLocaleString('zh-CN')}` : ''}
                </CardDescription>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={loadValues}
                disabled={loading}
                className="border-slate-700 bg-transparent text-slate-200 hover:bg-slate-800 hover:text-white"
              >
                {loading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                刷新
              </Button>
            </div>
          </CardHeader>

          <CardContent className="p-5 sm:p-6">
            {loading ? (
              <div className="flex min-h-52 items-center justify-center text-slate-400">
                <Loader2 className="mr-3 h-5 w-5 animate-spin" />
                正在加载 DOMAIN 标签…
              </div>
            ) : error ? (
              <div className="flex min-h-52 flex-col items-center justify-center rounded-xl border border-red-900/60 bg-red-950/30 px-6 text-center">
                <p className="font-medium text-red-200">{error}</p>
                <Button type="button" variant="outline" className="mt-4" onClick={loadValues}>
                  重新加载
                </Button>
              </div>
            ) : values.length === 0 ? (
              <div className="flex min-h-52 items-center justify-center rounded-xl border border-dashed border-slate-700 text-slate-400">
                DOMAIN 标签当前没有值
              </div>
            ) : (
              <ul className="grid gap-3 sm:grid-cols-2">
                {values.map((value, index) => (
                  <li
                    key={`${value}-${index}`}
                    className="break-all rounded-xl border border-slate-800 bg-slate-950/70 px-4 py-3 font-mono text-sm text-slate-200"
                  >
                    {value}
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </main>
  )
}
