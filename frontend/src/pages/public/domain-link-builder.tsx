import { useEffect, useState } from 'react'
import { Check, Clipboard, Code2, Dice5, Loader2, RefreshCw } from 'lucide-react'
import templateHtml from '@/assets/new-template.html?raw'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'

interface DomainsResponse {
  domains: string[]
}

interface SubjectsResponse {
  subjects: string[]
}

interface GeneratedHtml {
  html: string
  domain: string
  replacedLinks: number
  source: string
}

function normalizeDomain(value: string): string | null {
  try {
    const url = new URL(value.includes('://') ? value : `https://${value}`)
    return url.hostname || null
  } catch {
    return null
  }
}

function chooseRandom<T>(items: T[]): T {
  const random = new Uint32Array(1)
  crypto.getRandomValues(random)
  return items[random[0] % items.length]
}

function generateHtml(storeNumber: string, domains: string[]): GeneratedHtml {
  const domain = chooseRandom(domains)
  let replacedLinks = 0

  const html = templateHtml.replace(
    /(href\s*=\s*)(["'])(https?:\/\/[^"']+)\2/gi,
    (match, prefix: string, quote: string, rawUrl: string) => {
      try {
        const url = new URL(rawUrl)
        url.hostname = domain
        url.searchParams.set('utm_source', `sp${storeNumber}`)
        replacedLinks += 1
        return `${prefix}${quote}${url.toString()}${quote}`
      } catch {
        return match
      }
    },
  )

  return { html, domain, replacedLinks, source: `sp${storeNumber}` }
}

export default function DomainLinkBuilderPage() {
  const [storeNumber, setStoreNumber] = useState('')
  const [domains, setDomains] = useState<string[]>([])
  const [subjects, setSubjects] = useState<string[]>([])
  const [result, setResult] = useState<GeneratedHtml | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [copied, setCopied] = useState(false)
  const [subjectCopied, setSubjectCopied] = useState(false)
  const [lastSubject, setLastSubject] = useState('')

  const loadResources = async () => {
    setLoading(true)
    setError('')

    try {
      const [domainsResponse, subjectsResponse] = await Promise.all([
        api.get<DomainsResponse>('/public/domains'),
        api.get<SubjectsResponse>('/public/subjects'),
      ])
      const normalized = domainsResponse.data.domains
        .map(normalizeDomain)
        .filter((domain): domain is string => Boolean(domain))

      const normalizedSubjects = subjectsResponse.data.subjects
        .map((subject) => subject.trim())
        .filter(Boolean)

      if (normalized.length === 0) {
        throw new Error('DOMAIN 列表为空')
      }

      setDomains([...new Set(normalized)])
      setSubjects([...new Set(normalizedSubjects)])
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || requestError.message || 'DOMAIN 列表加载失败')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadResources()
  }, [])

  const handleGenerate = () => {
    const normalizedStoreNumber = storeNumber.trim()

    if (!normalizedStoreNumber) {
      setError('请输入店铺编号')
      return
    }

    if (!/^[a-zA-Z0-9_-]+$/.test(normalizedStoreNumber)) {
      setError('店铺编号只能包含字母、数字、下划线或短横线')
      return
    }

    if (domains.length === 0) {
      setError('没有可用的 DOMAIN，请先刷新列表')
      return
    }

    setError('')
    setCopied(false)
    setResult(generateHtml(normalizedStoreNumber, domains))
  }

  const handleCopy = async () => {
    if (!result) return

    try {
      await navigator.clipboard.writeText(result.html)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      setError('复制失败，请选中文本后手动复制')
    }
  }

  const handleCopySubject = async () => {
    if (subjects.length === 0) {
      setError('没有可用的 SUBJECT，请先刷新列表')
      return
    }

    const subject = chooseRandom(subjects)

    try {
      await navigator.clipboard.writeText(subject)
      setError('')
      setLastSubject(subject)
      setSubjectCopied(true)
      window.setTimeout(() => setSubjectCopied(false), 2000)
    } catch {
      setError('标题复制失败，请稍后重试')
    }
  }

  return (
    <main className="min-h-screen bg-slate-950 px-4 py-10 text-slate-100 sm:px-6">
      <div className="mx-auto w-full max-w-5xl">
        <div className="mb-8 flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-500/15 ring-1 ring-blue-400/30">
            <Code2 className="h-6 w-6 text-blue-300" />
          </div>
          <div>
            <p className="text-sm font-medium uppercase tracking-[0.24em] text-blue-300">SendWalk</p>
            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">店铺链接 HTML 生成器</h1>
          </div>
        </div>

        <Card className="border-slate-800 bg-slate-900/80 text-slate-100 shadow-2xl shadow-black/20">
          <CardHeader className="border-b border-slate-800">
            <CardTitle>生成带店铺来源标记的 HTML</CardTitle>
            <CardDescription className="text-slate-400">
              系统会随机选择一个 DOMAIN，替换模板中的可点击链接域名，并添加 utm_source。
            </CardDescription>
          </CardHeader>

          <CardContent className="space-y-6 p-5 sm:p-6">
            <div className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
              <div className="space-y-2">
                <Label htmlFor="store-number" className="text-slate-200">店铺编号</Label>
                <Input
                  id="store-number"
                  value={storeNumber}
                  onChange={(event) => setStoreNumber(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') handleGenerate()
                  }}
                  placeholder="例如：153667"
                  autoComplete="off"
                  className="border-slate-700 bg-slate-950 text-slate-100 placeholder:text-slate-600"
                />
              </div>

              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={loadResources}
                  disabled={loading}
                  className="border-slate-700 bg-transparent text-slate-200 hover:bg-slate-800 hover:text-white"
                >
                  {loading ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                  {loading ? '加载中' : `${domains.length} 个域名`}
                </Button>
                <Button type="button" onClick={handleGenerate} disabled={loading || domains.length === 0}>
                  <Dice5 className="mr-2 h-4 w-4" />
                  随机生成
                </Button>
                <Button
                  type="button"
                  variant="secondary"
                  onClick={handleCopySubject}
                  disabled={loading || subjects.length === 0}
                  title={`从 ${subjects.length} 个 SUBJECT 中随机复制`}
                >
                  {subjectCopied ? <Check className="mr-2 h-4 w-4" /> : <Clipboard className="mr-2 h-4 w-4" />}
                  {subjectCopied ? '标题已复制' : '复制标题'}
                </Button>
              </div>
            </div>

            {error && (
              <div className="rounded-lg border border-red-900/60 bg-red-950/30 px-4 py-3 text-sm text-red-200">
                {error}
              </div>
            )}

            {lastSubject && (
              <div className="rounded-lg border border-slate-800 bg-slate-950/70 px-4 py-3 text-sm text-slate-400">
                最近复制标题：<span className="text-slate-200">{lastSubject}</span>
              </div>
            )}

            {result && (
              <section className="space-y-3">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <div className="text-sm text-slate-400">
                    随机域名：<span className="font-mono text-blue-300">{result.domain}</span>
                    <span className="mx-2">·</span>
                    已处理 {result.replacedLinks} 个链接
                    <span className="mx-2">·</span>
                    来源：<span className="font-mono text-slate-200">{result.source}</span>
                  </div>
                  <Button type="button" onClick={handleCopy} className="shrink-0">
                    {copied ? <Check className="mr-2 h-4 w-4" /> : <Clipboard className="mr-2 h-4 w-4" />}
                    {copied ? '已复制' : '复制完整 HTML'}
                  </Button>
                </div>

                <Textarea
                  readOnly
                  value={result.html}
                  className="min-h-[460px] resize-y border-slate-700 bg-slate-950 font-mono text-xs leading-5 text-slate-300"
                  aria-label="生成后的 HTML"
                />
              </section>
            )}
          </CardContent>
        </Card>
      </div>
    </main>
  )
}
