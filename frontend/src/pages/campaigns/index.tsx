import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, Send, Copy, Trash2, Edit, Filter, Search, Mail, XCircle, Eye, Pause, Play } from 'lucide-react'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
} from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { api } from '@/lib/api'
import { useConfirm } from '@/hooks/use-confirm'
import { SendLogsDialog, EmailOpensDialog, AbuseReportsDialog } from './analytics-dialogs'
import { BouncesDialog, UnsubscribesDialog } from './stats-dialogs'

interface CustomTag {
  id: number
  name: string
  values: string
}

interface Campaign {
  id: number
  name: string
  subject: string
  preview_text?: string
  html_content?: string
  from_name?: string
  from_email?: string
  reply_to?: string
  status: string
  list: {
    id: number
    name: string
  }
  lists?: Array<{
    id: number
    name: string
  }>
  list_ids?: number[]
  smtp_server?: {
    id: number
    name: string
    sender_emails?: string
  }
  total_recipients: number
  total_sent: number
  total_delivered: number
  total_opened: number
  total_clicked: number
  total_bounced: number
  total_complained: number
  total_unsubscribed: number
  open_rate: number
  click_rate: number
  complaint_rate: number
  delivery_rate: number
  bounce_rate: number
  unsubscribe_rate: number
  scheduled_at: string | null
  sent_at: string | null
  created_at: string
}

export default function CampaignsPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchTerm, setSearchTerm] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [previewCampaign, setPreviewCampaign] = useState<Campaign | null>(null)
  const [previewUnsubscribeUrl, setPreviewUnsubscribeUrl] = useState<string>('')
  const [previewSubscriberEmail, setPreviewSubscriberEmail] = useState<string>('')
  const [sendLogsDialog, setSendLogsDialog] = useState<{ open: boolean; campaignId: number | null; campaignName: string }>({
    open: false,
    campaignId: null,
    campaignName: '',
  })
  const [emailOpensDialog, setEmailOpensDialog] = useState<{ open: boolean; campaignId: number | null; campaignName: string }>({
    open: false,
    campaignId: null,
    campaignName: '',
  })
  const [abuseReportsDialog, setAbuseReportsDialog] = useState<{ open: boolean; campaignId: number | null; campaignName: string }>({
    open: false,
    campaignId: null,
    campaignName: '',
  })
  const [bouncesDialog, setBouncesDialog] = useState<{ open: boolean; campaignId: number | null; campaignName: string }>({
    open: false,
    campaignId: null,
    campaignName: '',
  })
  const [unsubscribesDialog, setUnsubscribesDialog] = useState<{ open: boolean; campaignId: number | null; campaignName: string }>({
    open: false,
    campaignId: null,
    campaignName: '',
  })
  const { confirm, ConfirmDialog } = useConfirm()

  // 格式化日期时间为两行显示
  const formatDateTimeTwoLines = (dateString: string) => {
    const date = new Date(dateString)
    const year = date.getFullYear()
    const month = (date.getMonth() + 1).toString().padStart(2, '0')
    const day = date.getDate().toString().padStart(2, '0')
    const hour = date.getHours().toString().padStart(2, '0')
    const minute = date.getMinutes().toString().padStart(2, '0')
    return {
      date: `${year}/${month}/${day}`,
      time: `${hour}:${minute}`
    }
  }

  const { data: campaigns, isLoading } = useQuery<Campaign[]>({
    queryKey: ['campaigns'],
    queryFn: async () => {
      const response = await api.get('/campaigns')
      return response.data.data
    },
  })

  // 获取自定义标签
  const { data: customTags } = useQuery<CustomTag[]>({
    queryKey: ['tags'],
    queryFn: async () => {
      const response = await api.get('/tags')
      return response.data.data
    },
  })

  // 筛选和搜索
  const filteredCampaigns = campaigns?.filter((campaign) => {
    const matchesSearch = campaign.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
                         campaign.subject.toLowerCase().includes(searchTerm.toLowerCase())
    const matchesStatus = statusFilter === 'all' || campaign.status === statusFilter
    return matchesSearch && matchesStatus
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/campaigns/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] })
      toast.success('活动删除成功')
    },
    // onError 已由全局拦截器处理
  })

  const duplicateMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.post(`/campaigns/${id}/duplicate`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] })
      toast.success('活动已复制')
    },
    // onError 已由全局拦截器处理
  })

  const cancelMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.post(`/campaigns/${id}/cancel`)
    },
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] })
      const deletedCount = response.data?.deleted_jobs || 0
      toast.success(`活动已取消并恢复为草稿，已删除 ${deletedCount} 个待发送任务`)
    },
    // onError 已由全局拦截器处理
  })

  const pauseMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.post(`/campaigns/${id}/pause`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] })
      toast.success('活动已暂停')
    },
    // onError 已由全局拦截器处理
  })

  const resumeMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.post(`/campaigns/${id}/resume`)
    },
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] })
      const resumedJobs = response.data?.resumed_jobs || 0
      toast.success(`活动已恢复，将继续发送 ${resumedJobs} 个待发送任务`)
    },
    // onError 已由全局拦截器处理
  })

  const handleDelete = async (id: number, name: string) => {
    const confirmed = await confirm({
      title: '删除活动',
      description: `确定要删除活动"${name}"吗？`,
      confirmText: '删除',
      cancelText: '取消',
      variant: 'destructive',
    })
    if (confirmed) {
      deleteMutation.mutate(id)
    }
  }

  const handleCancel = async (id: number, name: string, status: string) => {
    const statusText = status === 'sending' ? '正在发送' : '已定时'
    const message = `确定要取消${statusText}的活动"${name}"吗？\n\n这将删除队列中所有待发送的任务，活动将恢复为草稿状态。`
    const confirmed = await confirm({
      title: '取消活动',
      description: message,
      confirmText: '取消活动',
      cancelText: '返回',
      variant: 'destructive',
    })
    if (confirmed) {
      cancelMutation.mutate(id)
    }
  }

  // 打开预览并获取真实的取消订阅 token
  const handlePreview = async (campaign: Campaign) => {
    setPreviewCampaign(campaign)
    
    try {
      const response = await api.get(`/campaigns/${campaign.id}/preview-token`)
      setPreviewUnsubscribeUrl(response.data.unsubscribe_url || '')
      setPreviewSubscriberEmail(response.data.subscriber_email || '')
    } catch (error) {
      console.error('Failed to get preview token:', error)
      // 使用默认的示例 URL 和邮箱
      const exampleToken = 'eyJpdiI6IkV4YW1wbGVJdiIsInZhbHVlIjoiRXhhbXBsZVRva2VuRm9yUHJldmlldyIsIm1hYyI6ImV4YW1wbGVfbWFjX2hhc2gifQ'
      setPreviewUnsubscribeUrl(`${window.location.origin}/unsubscribe?token=${exampleToken}`)
      setPreviewSubscriberEmail('subscriber@example.com')
    }
  }

  // 替换邮件内容中的标签为示例值
  const replaceTagsForPreview = (content: string, campaign: Campaign) => {
    if (!content) return content

    // 提取发件人域名
    const getSenderDomain = () => {
      // 优先使用活动的 from_email
      if (campaign.from_email) {
        const parts = campaign.from_email.split('@')
        return parts[1] || 'example.com'
      }
      
      // 如果活动没有设置发件人，尝试从服务器的 sender_emails 中获取第一个
      if (campaign.smtp_server?.sender_emails) {
        const senderEmails = campaign.smtp_server.sender_emails
          .split('\n')
          .map(email => email.trim())
          .filter(email => email && email.includes('@'))
        
        if (senderEmails.length > 0) {
          const parts = senderEmails[0].split('@')
          return parts[1] || 'example.com'
        }
      }
      
      // 如果都没有，使用默认值
      return 'example.com'
    }

    // 系统标签（只支持花括号格式 {}）
    const senderDomain = getSenderDomain()
    const systemReplacements: Record<string, string> = {
      '{campaign_id}': campaign.id.toString(),
      '{date}': new Date().toLocaleDateString('zh-CN', { month: '2-digit', day: '2-digit' }).replace(/\//g, ''),
      '{list_name}': campaign.lists?.[0]?.name || campaign.list?.name || '示例列表',
      '{server_name}': campaign.smtp_server?.name || '示例服务器',
      '{sender_domain}': senderDomain,
      '{unsubscribe_url}': previewUnsubscribeUrl || '#',
    }

    // 订阅者标签（使用真实邮箱，其他字段用示例值）
    const subscriberEmail = previewSubscriberEmail || 'subscriber@example.com'
    const subscriberReplacements: Record<string, string> = {
      '{email}': subscriberEmail,
      '{first_name}': '张',
      '{last_name}': '三',
      '{full_name}': '张三',
    }

    // 合并所有替换
    const allReplacements = { ...systemReplacements, ...subscriberReplacements }

    // 执行替换（只替换花括号格式）
    let result = content
    Object.entries(allReplacements).forEach(([tag, value]) => {
      // 转义花括号
      const escapedTag = tag.replace(/[{}]/g, '\\$&')
      result = result.replace(new RegExp(escapedTag, 'g'), value)
    })

    // 替换自定义标签（随机选择一个值）
    if (customTags) {
      customTags.forEach((tag) => {
        const placeholder = `{${tag.name}}`
        if (result.includes(placeholder)) {
          // 从值中随机选择一个（值以换行符分隔）
          const values = tag.values.split('\n').filter(v => v.trim())
          const randomValue = values.length > 0 
            ? values[Math.floor(Math.random() * values.length)]
            : placeholder // 如果没有值，保持原样
          result = result.replace(new RegExp(placeholder.replace(/[{}]/g, '\\$&'), 'g'), randomValue)
        }
      })
    }

    // 未匹配的标签保持原样，不做任何处理
    return result
  }

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline', className: string, label: string }> = {
      draft: {
        variant: 'outline',
        className: 'bg-gray-100 text-gray-700 border-gray-300',
        label: '草稿'
      },
      scheduled: {
        variant: 'default',
        className: 'bg-blue-100 text-blue-700 border-blue-300',
        label: '已定时'
      },
      sending: {
        variant: 'secondary',
        className: 'bg-yellow-100 text-yellow-700 border-yellow-300',
        label: '发送中'
      },
      sent: {
        variant: 'default',
        className: 'bg-green-100 text-green-700 border-green-300',
        label: '已发送'
      },
      paused: {
        variant: 'secondary',
        className: 'bg-orange-100 text-orange-700 border-orange-300',
        label: '已暂停'
      },
      cancelled: {
        variant: 'destructive',
        className: 'bg-red-100 text-red-700 border-red-300',
        label: '已取消'
      },
    }
    
    const config = statusConfig[status] || statusConfig.draft
    
    return (
      <Badge variant={config.variant} className={`whitespace-nowrap ${config.className}`}>
        {config.label}
      </Badge>
    )
  }

  return (
    <TooltipProvider>
    <div className="space-y-6">
      {/* 页头 */}
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-xl md:text-2xl font-bold tracking-tight">邮件活动</h1>
          <p className="text-muted-foreground mt-2">创建和管理邮件营销活动</p>
        </div>
        <Button onClick={() => navigate('/campaigns/create')} size="default">
          <Plus className="w-4 h-4 mr-2" />
          创建活动
        </Button>
      </div>

      {/* 筛选和搜索 */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex flex-col gap-4 md:flex-row md:items-center">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="搜索活动名称或主题..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="pl-9"
              />
            </div>
            <div className="flex gap-2">
              <Select value={statusFilter} onValueChange={setStatusFilter}>
                <SelectTrigger className="w-[160px]">
                  <Filter className="h-4 w-4 mr-2" />
                  <SelectValue placeholder="筛选状态" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">全部状态</SelectItem>
                  <SelectItem value="draft">草稿</SelectItem>
                  <SelectItem value="scheduled">已定时</SelectItem>
                  <SelectItem value="sending">发送中</SelectItem>
                  <SelectItem value="sent">已发送</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
          {(searchTerm || statusFilter !== 'all') && (
            <div className="mt-3 text-sm text-muted-foreground">
              找到 {filteredCampaigns?.length || 0} 个活动
            </div>
          )}
        </CardContent>
      </Card>

      {/* 空状态或加载状态 */}
      {isLoading || !campaigns ? (
        // 加载中显示骨架屏
        <Card>
          <div className="overflow-x-auto">
            <Table className="min-w-[1190px]">
              <colgroup>
                <col className="w-[60px]" />
                <col className="w-[200px]" />
                <col className="w-[80px]" />
                <col className="w-[150px]" />
                <col className="w-[160px]" />
                <col className="w-[140px]" />
                <col className="w-[200px]" />
              </colgroup>
              <TableHeader>
              <TableRow>
                <TableHead>ID</TableHead>
                <TableHead>标题</TableHead>
                <TableHead className="text-center">状态</TableHead>
                <TableHead>目标列表</TableHead>
                <TableHead>发送进度</TableHead>
                <TableHead>预定发送时间</TableHead>
                <TableHead className="text-right">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {[...Array(5)].map((_, i) => (
                <TableRow key={i}>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-12" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-40" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-6 w-16 mx-auto" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-32" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-24" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-32" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-8 w-24 ml-auto" /></TableCell>
                </TableRow>
              ))}
            </TableBody>
            </Table>
          </div>
        </Card>
      ) : campaigns.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Send className="w-12 h-12 text-muted-foreground mb-4" />
            <p className="text-lg font-medium mb-2">还没有邮件活动</p>
            <p className="text-muted-foreground mb-4">创建您的第一个营销活动</p>
            <Button onClick={() => navigate('/campaigns/create')}>
              <Plus className="w-4 h-4 mr-2" />
              创建活动
            </Button>
          </CardContent>
        </Card>
      ) : filteredCampaigns && filteredCampaigns.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Search className="w-12 h-12 text-muted-foreground mb-4" />
            <p className="text-lg font-medium mb-2">没有找到匹配的活动</p>
            <p className="text-muted-foreground mb-4">尝试调整搜索条件或筛选器</p>
            <Button variant="outline" onClick={() => {
              setSearchTerm('')
              setStatusFilter('all')
            }}>
              清除筛选
            </Button>
          </CardContent>
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <Table className="min-w-[1590px]">
              <colgroup>
                <col className="w-[60px]" />
                <col className="w-[200px]" />
                <col className="w-[80px]" />
                <col className="w-[150px]" />
                <col className="w-[160px]" />
                <col className="w-[100px]" />
                <col className="w-[100px]" />
                <col className="w-[100px]" />
                <col className="w-[100px]" />
                <col className="w-[100px]" />
                <col className="w-[100px]" />
                <col className="w-[140px]" />
                <col className="w-[200px]" />
              </colgroup>
              <TableHeader>
              <TableRow>
                <TableHead>ID</TableHead>
                <TableHead>标题</TableHead>
                <TableHead className="text-center">状态</TableHead>
                <TableHead>列表</TableHead>
                <TableHead className="text-center">发送进度</TableHead>
                <TableHead className="text-center">送达率</TableHead>
                <TableHead className="text-center">打开率</TableHead>
                <TableHead className="text-center">点击率</TableHead>
                <TableHead className="text-center">取消订阅</TableHead>
                <TableHead className="text-center">投诉率</TableHead>
                <TableHead className="text-center">弹回率</TableHead>
                <TableHead className="text-center">时间</TableHead>
                <TableHead className="text-right">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredCampaigns?.map((campaign) => (
                <TableRow key={campaign.id} className="hover:bg-muted/50">
                  <TableCell className="whitespace-nowrap">
                    <div className="text-sm text-muted-foreground font-mono">
                      #{campaign.id}
                    </div>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <div className="font-medium truncate">{campaign.name}</div>
                  </TableCell>
                  <TableCell className="text-center whitespace-nowrap">
                    {getStatusBadge(campaign.status)}
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <div className="flex items-center gap-1 text-sm cursor-help">
                      <Mail className="w-4 h-4 text-muted-foreground flex-shrink-0" />
                          <span className="truncate max-w-[130px]">
                        {campaign.lists && campaign.lists.length > 0 
                          ? campaign.lists.map(l => l.name).join(', ')
                          : campaign.list?.name || '未指定列表'}
                      </span>
                    </div>
                      </TooltipTrigger>
                      <TooltipContent>
                        <div className="max-w-xs">
                          {campaign.lists && campaign.lists.length > 0 
                            ? campaign.lists.map(l => l.name).join(', ')
                            : campaign.list?.name || '未指定列表'}
                        </div>
                      </TooltipContent>
                    </Tooltip>
                  </TableCell>
                  <TableCell className="text-center whitespace-nowrap">
                    <button
                      onClick={() => setSendLogsDialog({ open: true, campaignId: campaign.id, campaignName: campaign.name })}
                      className="flex flex-col items-center gap-1 w-full hover:opacity-70 transition-opacity cursor-pointer"
                      disabled={campaign.total_sent === 0}
                    >
                      <span className="font-medium">
                        {campaign.total_sent || 0} / {campaign.total_recipients || 0}
                      </span>
                      <div className="w-full bg-muted rounded-full h-1.5">
                        <div
                          className="bg-primary h-1.5 rounded-full transition-all"
                          style={{
                            width: `${campaign.total_recipients > 0
                              ? (campaign.total_sent / campaign.total_recipients) * 100
                              : 0
                            }%`,
                          }}
                        />
                      </div>
                    </button>
                  </TableCell>
                  <TableCell className="text-center whitespace-nowrap">
                    <button
                      onClick={() => setSendLogsDialog({ open: true, campaignId: campaign.id, campaignName: campaign.name })}
                      className="flex flex-col items-center gap-1 w-full hover:opacity-70 transition-opacity cursor-pointer"
                      disabled={campaign.total_sent === 0}
                    >
                      <span className="font-medium">
                        {campaign.delivery_rate?.toFixed(1) || 0}%
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {campaign.total_delivered || 0} / {campaign.total_sent || 0}
                      </span>
                    </button>
                  </TableCell>
                  <TableCell className="text-center whitespace-nowrap">
                    <button
                      onClick={() => setEmailOpensDialog({ open: true, campaignId: campaign.id, campaignName: campaign.name })}
                      className="flex flex-col items-center gap-1 w-full hover:opacity-70 transition-opacity cursor-pointer"
                      disabled={campaign.total_opened === 0}
                    >
                      <span className="font-medium">
                        {campaign.open_rate?.toFixed(1) || 0}%
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {campaign.total_opened || 0} 次
                      </span>
                    </button>
                  </TableCell>
                  <TableCell className="text-center whitespace-nowrap">
                    <div className="flex flex-col items-center gap-1">
                      <span className="font-medium">
                        {campaign.click_rate?.toFixed(1) || 0}%
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {campaign.total_clicked || 0} 次
                      </span>
                    </div>
                  </TableCell>
                  <TableCell className="text-center whitespace-nowrap">
                    <button
                      onClick={() => setUnsubscribesDialog({ open: true, campaignId: campaign.id, campaignName: campaign.name })}
                      className="flex flex-col items-center gap-1 w-full hover:opacity-70 transition-opacity cursor-pointer"
                      disabled={campaign.total_unsubscribed === 0}
                    >
                      <span className="font-medium">
                        {campaign.unsubscribe_rate?.toFixed(1) || 0}%
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {campaign.total_unsubscribed || 0} 次
                      </span>
                    </button>
                  </TableCell>
                  <TableCell className="text-center whitespace-nowrap">
                    <button
                      onClick={() => setAbuseReportsDialog({ open: true, campaignId: campaign.id, campaignName: campaign.name })}
                      className="flex flex-col items-center gap-1 w-full hover:opacity-70 transition-opacity cursor-pointer"
                      disabled={campaign.total_complained === 0}
                    >
                      <span className="font-medium">
                        {campaign.complaint_rate?.toFixed(1) || 0}%
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {campaign.total_complained || 0} 次
                      </span>
                    </button>
                  </TableCell>
                  <TableCell className="text-center whitespace-nowrap">
                    <button
                      onClick={() => setBouncesDialog({ open: true, campaignId: campaign.id, campaignName: campaign.name })}
                      className="flex flex-col items-center gap-1 w-full hover:opacity-70 transition-opacity cursor-pointer"
                      disabled={campaign.total_bounced === 0}
                    >
                      <span className="font-medium">
                        {campaign.bounce_rate?.toFixed(1) || 0}%
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {campaign.total_bounced || 0} 次
                      </span>
                    </button>
                  </TableCell>
                  <TableCell className="text-center whitespace-nowrap">
                    {campaign.scheduled_at ? (
                      <div className="text-sm text-muted-foreground leading-tight">
                        <div>{formatDateTimeTwoLines(campaign.scheduled_at).date}</div>
                        <div>{formatDateTimeTwoLines(campaign.scheduled_at).time}</div>
                      </div>
                    ) : (
                      <span className="text-sm text-muted-foreground">/</span>
                    )}
                  </TableCell>
                  <TableCell className="text-right whitespace-nowrap">
                    <div className="flex items-center justify-end gap-0.5">
                      {(campaign.status === 'draft' || campaign.status === 'scheduled' || campaign.status === 'cancelled') && (
                        <Button
                          size="sm"
                          variant="ghost"
                          className="px-1.5"
                          onClick={() => navigate(`/campaigns/${campaign.id}/edit`)}
                          title="编辑"
                        >
                          <Edit className="w-4 h-4" />
                        </Button>
                      )}
                      <Button
                        size="sm"
                        variant="ghost"
                        className="px-1.5"
                        onClick={() => handlePreview(campaign)}
                        title="预览"
                      >
                        <Eye className="w-4 h-4" />
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        className="px-1.5"
                        onClick={() => duplicateMutation.mutate(campaign.id)}
                        disabled={duplicateMutation.isPending}
                        title="复制"
                      >
                        <Copy className="w-4 h-4" />
                      </Button>
                      {(campaign.status === 'scheduled' || campaign.status === 'sending') && (
                        <Button
                          size="sm"
                          variant="ghost"
                          className="px-1.5"
                          onClick={() => pauseMutation.mutate(campaign.id)}
                          disabled={pauseMutation.isPending}
                          title={campaign.status === 'scheduled' ? '暂停定时' : '暂停发送'}
                        >
                          <Pause className="w-4 h-4 text-orange-500" />
                        </Button>
                      )}
                      {campaign.status === 'paused' && (
                        <Button
                          size="sm"
                          variant="ghost"
                          className="px-1.5"
                          onClick={() => resumeMutation.mutate(campaign.id)}
                          disabled={resumeMutation.isPending}
                          title="恢复发送"
                        >
                          <Play className="w-4 h-4 text-green-500" />
                        </Button>
                      )}
                      {(campaign.status === 'scheduled' || campaign.status === 'sending') && (
                        <Button
                          size="sm"
                          variant="ghost"
                          className="px-1.5"
                          onClick={() => handleCancel(campaign.id, campaign.name, campaign.status)}
                          disabled={cancelMutation.isPending}
                          title="取消活动"
                        >
                          <XCircle className="w-4 h-4 text-red-500" />
                        </Button>
                      )}
                      {(campaign.status === 'draft' || campaign.status === 'scheduled' || campaign.status === 'cancelled' || campaign.status === 'sent' || campaign.status === 'paused') && (
                        <Button
                          size="sm"
                          variant="ghost"
                          className="px-1.5"
                          onClick={() => handleDelete(campaign.id, campaign.name)}
                          disabled={deleteMutation.isPending}
                          title="删除"
                        >
                          <Trash2 className="w-4 h-4 text-red-500" />
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
            </Table>
          </div>
        </Card>
      )}

      {/* 预览对话框 */}
      <Dialog open={!!previewCampaign} onOpenChange={() => {
        setPreviewCampaign(null)
        setPreviewUnsubscribeUrl('')
        setPreviewSubscriberEmail('')
      }}>
        <DialogContent className="max-w-4xl max-h-[80vh] overflow-hidden flex flex-col">
          <DialogHeader>
            <DialogTitle>{previewCampaign?.name}</DialogTitle>
            <DialogDescription asChild>
              <div className="space-y-1">
                <div>
                  <span className="font-medium">主题：</span>
                  {previewCampaign?.subject ? replaceTagsForPreview(previewCampaign.subject, previewCampaign) : ''}
                </div>
                {previewCampaign?.preview_text && (
                  <div>
                    <span className="font-medium">预览文本：</span>
                    {replaceTagsForPreview(previewCampaign.preview_text, previewCampaign)}
                  </div>
                )}
                {previewCampaign?.from_name && previewCampaign?.from_email && (
                  <div>
                    <span className="font-medium">发件人：</span>
                    {previewCampaign.from_name} &lt;{previewCampaign.from_email}&gt;
                  </div>
                )}
                {previewCampaign?.smtp_server && (
                  <div>
                    <span className="font-medium">发送服务器：</span>
                    {previewCampaign.smtp_server.name}
                  </div>
                )}
              </div>
            </DialogDescription>
          </DialogHeader>
          <div className="flex-1 overflow-auto border rounded-lg bg-white">
            {previewCampaign?.html_content ? (
              <>
                <iframe
                  ref={(iframe) => {
                    if (iframe && previewCampaign?.html_content) {
                      const doc = iframe.contentDocument || iframe.contentWindow?.document
                      if (doc) {
                        const replacedHtml = replaceTagsForPreview(previewCampaign.html_content, previewCampaign)
                        doc.open()
                        doc.write(replacedHtml)
                        doc.close()
                      }
                    }
                  }}
                  className="w-full h-full min-h-[500px]"
                  title="邮件预览"
                />
              </>
            ) : (
              <div className="flex items-center justify-center h-64 text-gray-500">
                暂无邮件内容
              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>

      {/* 确认对话框 */}
      <ConfirmDialog />

      {/* 发送日志对话框 */}
      <SendLogsDialog
        campaignId={sendLogsDialog.campaignId}
        campaignName={sendLogsDialog.campaignName}
        open={sendLogsDialog.open}
        onClose={() => setSendLogsDialog({ open: false, campaignId: null, campaignName: '' })}
      />

      {/* 打开记录对话框 */}
      <EmailOpensDialog
        campaignId={emailOpensDialog.campaignId}
        campaignName={emailOpensDialog.campaignName}
        open={emailOpensDialog.open}
        onClose={() => setEmailOpensDialog({ open: false, campaignId: null, campaignName: '' })}
      />

      {/* 投诉报告对话框 */}
      <AbuseReportsDialog
        campaignId={abuseReportsDialog.campaignId}
        campaignName={abuseReportsDialog.campaignName}
        open={abuseReportsDialog.open}
        onClose={() => setAbuseReportsDialog({ open: false, campaignId: null, campaignName: '' })}
      />

      {/* 弹回记录对话框 */}
      <BouncesDialog
        campaignId={bouncesDialog.campaignId}
        campaignName={bouncesDialog.campaignName}
        open={bouncesDialog.open}
        onClose={() => setBouncesDialog({ open: false, campaignId: null, campaignName: '' })}
      />

      {/* 取消订阅记录对话框 */}
      <UnsubscribesDialog
        campaignId={unsubscribesDialog.campaignId}
        campaignName={unsubscribesDialog.campaignName}
        open={unsubscribesDialog.open}
        onClose={() => setUnsubscribesDialog({ open: false, campaignId: null, campaignName: '' })}
      />
    </div>
    </TooltipProvider>
  )
}
