import { useState, useEffect, useRef } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Save, Send, Code, Eye, Clock, Tag, Mail, X, FileText } from 'lucide-react'
import { toast } from 'sonner'
import { format } from 'date-fns'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DateTimePicker } from '@/components/ui/date-time-picker'
import { api } from '@/lib/api'

interface MailingList {
  id: number
  name: string
  subscribers_count?: number
}

interface SmtpServer {
  id: number
  name: string
  type: string
  is_default: boolean
  is_active: boolean
  sender_emails?: string
}

interface CustomTag {
  id: number
  name: string
  label: string
  placeholder: string
  values: string
  values_count: number
}

interface Template {
  id: number
  name: string
  category: string
  description: string | null
  html_content: string
}

export default function CampaignEditorPage() {
  const navigate = useNavigate()
  const { id } = useParams()
  const queryClient = useQueryClient()
  const isEditing = !!id

  const [formData, setFormData] = useState({
    list_ids: [] as number[],
    smtp_server_id: '', // 始终使用空字符串，确保组件始终是 controlled
    name: '',
    subject: '',
    preview_text: '',
    from_name: '',
    from_email: '',
    reply_to: '',
    html_content: '<h1>你好 {first_name}!</h1><p>这是一封测试邮件。</p>',
  })

  const [viewMode, setViewMode] = useState<'code' | 'preview'>('code')
  const [sendMode, setSendMode] = useState<'draft' | 'now' | 'schedule'>('draft')
  const [scheduledDateTime, setScheduledDateTime] = useState<Date | undefined>()
  const [isSendDialogOpen, setIsSendDialogOpen] = useState(false)
  const [isTemplateDialogOpen, setIsTemplateDialogOpen] = useState(false)
  const [isSaveBeforeSend, setIsSaveBeforeSend] = useState(false) // 标记是否是"保存后发送"操作
  const defaultServerSetRef = useRef(false) // 标记是否已设置默认服务器
  const subjectInputRef = useRef<HTMLInputElement>(null)
  const contentTextareaRef = useRef<HTMLTextAreaElement>(null)

  // 获取自定义标签
  const { data: customTags } = useQuery<CustomTag[]>({
    queryKey: ['tags'],
    queryFn: async () => {
      const response = await api.get('/tags')
      return response.data.data
    },
  })

  // 获取邮件模板
  const { data: templates } = useQuery<Template[]>({
    queryKey: ['templates-for-campaign'],
    queryFn: async () => {
      const response = await api.get('/templates?active_only=true')
      return response.data.data
    },
  })

  // 获取列表（获取所有列表用于选择）
  const { data: lists } = useQuery<MailingList[]>({
    queryKey: ['lists-all'],
    queryFn: async () => {
      const response = await api.get('/lists?all=true')
      return response.data.data
    },
  })

  // 获取SMTP服务器
  const { data: smtpServers } = useQuery<SmtpServer[]>({
    queryKey: ['smtp-servers'],
    queryFn: async () => {
      const response = await api.get('/smtp-servers')
      return response.data.data
    },
  })

  // 获取活动详情（编辑模式）
  const { data: campaign } = useQuery({
    queryKey: ['campaign', id],
    queryFn: async () => {
      const response = await api.get(`/campaigns/${id}`)
      return response.data.data
    },
    enabled: isEditing,
  })

  useEffect(() => {
    if (campaign) {
      // 确保 smtp_server_id 始终是字符串（空字符串或有值）
      const serverId = campaign.smtp_server_id ? campaign.smtp_server_id.toString() : ''
      
      // 🔑 关键：在 setFormData 之前先设置 ref，防止时序窗口问题
      // 这样可以确保当 setFormData 触发第二个 useEffect 时，ref 已经是 true
      if (serverId) {
        defaultServerSetRef.current = true
      }
      
      setFormData({
        list_ids: campaign.list_ids || (campaign.list_id ? [campaign.list_id] : []),
        smtp_server_id: serverId, // 始终是字符串
        name: campaign.name || '',
        subject: campaign.subject || '',
        preview_text: campaign.preview_text || '',
        from_name: campaign.from_name || '',
        from_email: campaign.from_email || '',
        reply_to: campaign.reply_to || '',
        html_content: campaign.html_content || '',
      })
      
      // 如果有定时发送时间，设置为定时模式
      if (campaign.scheduled_at) {
        setSendMode('schedule')
        setScheduledDateTime(new Date(campaign.scheduled_at))
      }
    }
  }, [campaign])

  // 自动选择默认SMTP服务器（仅在创建新活动时）
  useEffect(() => {
    // 编辑模式下完全跳过（多重检查确保万无一失）
    if (isEditing) {
      return
    }
    
    // 如果已经设置过（包括编辑模式加载的数据或用户手动选择），跳过
    if (defaultServerSetRef.current) {
      return
    }
    
    // 创建模式下：如果有SMTP服务器列表，且当前未选择服务器，自动选择默认服务器
    if (smtpServers && smtpServers.length > 0 && !formData.smtp_server_id) {
      const defaultServer = smtpServers.find(s => s.is_default && s.is_active)
      if (defaultServer) {
        // 使用函数式更新，确保基于最新状态
        setFormData(prev => {
          // 再次检查当前状态，防止竞态
          if (prev.smtp_server_id) {
            return prev // 如果已有值，不覆盖
          }
          return { ...prev, smtp_server_id: defaultServer.id.toString() }
        })
        defaultServerSetRef.current = true // 标记已设置
      }
    }
  }, [smtpServers, isEditing, formData.smtp_server_id])

  // 保存/更新活动
  const saveMutation = useMutation({
    mutationFn: async (data: typeof formData) => {
      if (isEditing) {
        return api.put(`/campaigns/${id}`, data)
      }
      return api.post('/campaigns', data)
    },
    onSuccess: (response) => {
      // 刷新列表页缓存
      queryClient.invalidateQueries({ queryKey: ['campaigns'] })
      
      // 刷新当前活动的缓存（如果是编辑模式）
      if (isEditing && id) {
        queryClient.invalidateQueries({ queryKey: ['campaign', id] })
      }
      
      // 如果是"保存后发送"操作，不显示提示（由 sendMutation 统一提示）
      if (isSaveBeforeSend) {
        setIsSaveBeforeSend(false)
        return
      }
      
      // 如果是创建新活动且选择了发送，则自动发送
      if (!isEditing && sendMode !== 'draft') {
        const newCampaignId = response.data.data.id
        
        if (sendMode === 'now') {
          // 立即发送
          sendMutation.mutate({ campaignId: newCampaignId })
        } else if (sendMode === 'schedule') {
          // 定时发送
          if (!scheduledDateTime) {
            toast.error('请选择发送日期和时间')
            return
          }
          const scheduledAt = format(scheduledDateTime, 'yyyy-MM-dd HH:mm:ss')
          sendMutation.mutate({ campaignId: newCampaignId, scheduledAt })
        }
      } else {
        toast.success(isEditing ? '活动已更新' : '活动已保存为草稿')
        navigate('/campaigns')
      }
    },
    // onError 已由全局拦截器处理
  })

  // 发送活动
  const sendMutation = useMutation({
    mutationFn: async ({ campaignId, scheduledAt }: { campaignId: number; scheduledAt?: string }) => {
      if (scheduledAt) {
        return api.post(`/campaigns/${campaignId}/schedule`, { scheduled_at: scheduledAt })
      }
      return api.post(`/campaigns/${campaignId}/send`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] })
      
      // 如果是编辑模式，统一显示"活动已更新"
      if (isEditing) {
        toast.success('活动已更新')
      } else {
        // 如果是创建新活动，保持原有提示
        toast.success('活动已创建')
      }
      navigate('/campaigns')
    },
    // onError 已由全局拦截器处理
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!formData.list_ids || formData.list_ids.length === 0) {
      toast.error('请至少选择一个邮件列表')
      return
    }
    if (!formData.smtp_server_id) {
      toast.error('请选择发送服务器')
      return
    }
    
    // 如果选择了定时发送，验证时间
    if (sendMode === 'schedule' && !scheduledDateTime) {
      toast.error('请选择发送日期和时间')
      return
    }
    
    saveMutation.mutate(formData)
  }

  const handleSendNow = async () => {
    if (!id) {
      toast.error('请先保存活动')
      return
    }
    
    // 先保存修改
    try {
      if (isEditing) {
        setIsSaveBeforeSend(true) // 标记为"保存后发送"，不显示保存成功提示
        await saveMutation.mutateAsync(formData)
      }
      
      sendMutation.mutate({ campaignId: parseInt(id) })
    } catch (error) {
      // 保存失败，不继续发送
      setIsSaveBeforeSend(false)
      console.error('保存失败:', error)
    }
  }

  const handleScheduleSend = async () => {
    if (!id) {
      toast.error('请先保存活动')
      return
    }
    if (!scheduledDateTime) {
      toast.error('请选择发送日期和时间')
      return
    }

    // 先保存修改
    try {
      if (isEditing) {
        setIsSaveBeforeSend(true) // 标记为"保存后发送"，不显示保存成功提示
        await saveMutation.mutateAsync(formData)
      }
      
      const scheduledAt = format(scheduledDateTime, 'yyyy-MM-dd HH:mm:ss')
      sendMutation.mutate({ campaignId: parseInt(id), scheduledAt })
      setIsSendDialogOpen(false)
    } catch (error) {
      // 保存失败，不继续定时发送
      setIsSaveBeforeSend(false)
      console.error('保存失败:', error)
    }
  }

  const getPreviewHtml = () => {
    let html = formData.html_content
    
    // 获取当前选择的列表名称
    const selectedListNames = lists
      ?.filter(list => formData.list_ids.includes(list.id))
      .map(list => list.name)
      .join(', ') || '示例列表'
    
    // 获取当前选择的服务器名称
    const selectedServerName = smtpServers
      ?.find(server => server.id.toString() === formData.smtp_server_id)
      ?.name || '示例服务器'
    
    // 提取发件人域名
    let senderDomain = 'example.com'
    
    // 优先使用活动的 from_email
    if (formData.from_email) {
      const parts = formData.from_email.split('@')
      senderDomain = parts[1] || 'example.com'
    } else if (formData.smtp_server_id) {
      // 如果活动没有设置发件人，尝试从选中的服务器的 sender_emails 中获取第一个
      const selectedServer = smtpServers?.find(s => s.id.toString() === formData.smtp_server_id)
      if (selectedServer?.sender_emails) {
        const senderEmails = selectedServer.sender_emails
          .split('\n')
          .map(email => email.trim())
          .filter(email => email && email.includes('@'))
        
        if (senderEmails.length > 0) {
          const parts = senderEmails[0].split('@')
          senderDomain = parts[1] || 'example.com'
        }
      }
    }
    
    const exampleEmail = 'user@example.com'
    const unsubscribeUrl = 'https://example.com/unsubscribe/abc123'
    
    // 订阅者标签（示例值，纯文本替换）
    // 只支持 {} 花括号格式
    html = html
      .replace(/{first_name}/g, '张三')
      .replace(/{last_name}/g, '李四')
      .replace(/{email}/g, exampleEmail)
      .replace(/{full_name}/g, '张三 李四')
    
    // 系统标签（使用真实值，纯文本替换）
    // 只支持 {} 花括号格式
    html = html
      .replace(/{campaign_id}/g, (id || 'new').toString())
      .replace(/{date}/g, new Date().toLocaleDateString('zh-CN', { month: '2-digit', day: '2-digit' }).replace(/\//g, ''))
      .replace(/{list_name}/g, selectedListNames)
      .replace(/{server_name}/g, selectedServerName)
      .replace(/{sender_domain}/g, senderDomain)
      .replace(/{unsubscribe_url}/g, unsubscribeUrl)
    
    // 替换自定义标签（随机选择一个值，纯文本替换）
    customTags?.forEach((tag) => {
      const values = tag.values.split('\n').filter(v => v.trim())
      const randomValue = values.length > 0 
        ? values[Math.floor(Math.random() * values.length)]
        : `{${tag.name}}` // 如果没有值，保持原样
      const regex = new RegExp(`{${tag.name}}`, 'g')
      html = html.replace(regex, randomValue)
    })
    
    // 未匹配的标签保持原样，方括号内容不处理
    return html
  }

  // 插入标签到内容
  const insertTagToContent = (placeholder: string) => {
    if (contentTextareaRef.current) {
      const textarea = contentTextareaRef.current
      const start = textarea.selectionStart || 0
      const end = textarea.selectionEnd || 0
      const text = formData.html_content
      const newText = text.substring(0, start) + placeholder + text.substring(end)
      setFormData({ ...formData, html_content: newText })
      
      setTimeout(() => {
        textarea.focus()
        textarea.setSelectionRange(start + placeholder.length, start + placeholder.length)
      }, 0)
    }
  }

  // 应用模板
  const applyTemplate = (template: Template) => {
    setFormData({ ...formData, html_content: template.html_content })
    setIsTemplateDialogOpen(false)
    toast.success(`已应用模板"${template.name}"`)
  }

  return (
    <div className="space-y-6">
      {/* 页头 */}
      <div>
        <div className="flex items-center gap-2 text-sm text-muted-foreground mb-2">
          <Link to="/campaigns" className="hover:text-primary flex items-center gap-1">
            <Mail className="w-4 h-4" />
            邮件活动
          </Link>
          <span>/</span>
          <span className="text-foreground font-medium">
            {isEditing ? '编辑活动' : '创建活动'}
          </span>
        </div>
        <h1 className="text-xl md:text-2xl font-bold tracking-tight">
          {isEditing ? '编辑活动' : '创建新活动'}
        </h1>
        <p className="text-muted-foreground mt-2">
          {isEditing ? '修改邮件活动内容' : '创建一个新的邮件营销活动'}
        </p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* 基本信息 */}
        <Card>
          <CardHeader>
            <CardTitle>基本信息</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* 第一行：活动名称 + 发送服务器 */}
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="name">活动名称 *</Label>
                <Input
                  id="name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="例如：春季促销活动"
                  required
                />
              </div>
              <div className="space-y-2">
                <Label>发送服务器 *</Label>
                <Select
                  value={formData.smtp_server_id || ''}
                  onValueChange={(value) => setFormData({ ...formData, smtp_server_id: value })}
                >
                  <SelectTrigger id="smtp_server_id">
                    <SelectValue placeholder="选择服务器" />
                  </SelectTrigger>
                  <SelectContent>
                    {smtpServers?.filter(s => s.is_active).map((server) => (
                      <SelectItem key={server.id} value={server.id.toString()}>
                        {server.name} {server.is_default && '(默认)'}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            {/* 第二行：邮件列表（单独一行） */}
            <div className="space-y-2">
              <Label>邮件列表 *</Label>
              <div className="border rounded-md p-3 space-y-2 max-h-[200px] overflow-y-auto">
                {lists?.map((list) => (
                  <div key={list.id} className="flex items-center space-x-2">
                    <Checkbox
                      id={`list-${list.id}`}
                      checked={formData.list_ids.includes(list.id)}
                      onCheckedChange={(checked) => {
                        if (checked) {
                          setFormData({
                            ...formData,
                            list_ids: [...formData.list_ids, list.id]
                          })
                        } else {
                          setFormData({
                            ...formData,
                            list_ids: formData.list_ids.filter(id => id !== list.id)
                          })
                        }
                      }}
                    />
                    <label
                      htmlFor={`list-${list.id}`}
                      className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70 cursor-pointer flex-1"
                    >
                      {list.name}
                      <span className="text-xs text-muted-foreground ml-2">
                        ({list.subscribers_count} 订阅者)
                      </span>
                    </label>
                  </div>
                ))}
              </div>
              {formData.list_ids.length > 0 && (
                <div className="flex flex-wrap gap-1 mt-2">
                  {formData.list_ids.map(listId => {
                    const list = lists?.find(l => l.id === listId)
                    return list ? (
                      <Badge key={listId} variant="secondary" className="gap-1">
                        {list.name}
                        <X
                          className="h-3 w-3 cursor-pointer hover:text-destructive"
                          onClick={() => {
                            setFormData({
                              ...formData,
                              list_ids: formData.list_ids.filter(id => id !== listId)
                            })
                          }}
                        />
                      </Badge>
                    ) : null
                  })}
                </div>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="subject">邮件主题 *</Label>
              <Input
                ref={subjectInputRef}
                id="subject"
                value={formData.subject}
                onChange={(e) => setFormData({ ...formData, subject: e.target.value })}
                placeholder="例如：限时优惠！春季大促销"
                required
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="preview_text">
                预览文本
                <span className="text-muted-foreground ml-2 text-xs font-normal">
                  (显示在邮件客户端收件箱中，提高打开率)
                </span>
              </Label>
              <Input
                id="preview_text"
                value={formData.preview_text}
                onChange={(e) => setFormData({ ...formData, preview_text: e.target.value })}
                placeholder="例如：快来看看我们的春季新品，限时85折优惠！"
                maxLength={150}
              />
              <p className="text-xs text-muted-foreground">
                建议长度：40-150字符。支持个性化标签，如 {'{first_name}'}, {'{email}'}
              </p>
            </div>
          </CardContent>
        </Card>

        {/* 发件人信息 */}
        <Card>
          <CardHeader>
            <CardTitle>发件人信息</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="from_name">发件人名称 *</Label>
                <Input
                  id="from_name"
                  value={formData.from_name}
                  onChange={(e) => setFormData({ ...formData, from_name: e.target.value })}
                  placeholder="SendWalk"
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="from_email">
                  发件人邮箱
                  <span className="text-muted-foreground ml-2 text-xs font-normal">
                    (可选，留空则随机使用服务器邮箱池)
                  </span>
                </Label>
                <Input
                  id="from_email"
                  type="email"
                  value={formData.from_email}
                  onChange={(e) => setFormData({ ...formData, from_email: e.target.value })}
                  placeholder="留空则从服务器邮箱池随机选择"
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="reply_to">
                回复邮箱
                <span className="text-muted-foreground ml-2 text-xs font-normal">
                  (可选，留空则使用发件人邮箱)
                </span>
              </Label>
              <Input
                id="reply_to"
                type="email"
                value={formData.reply_to}
                onChange={(e) => setFormData({ ...formData, reply_to: e.target.value })}
                placeholder="留空则使用发件人邮箱"
              />
            </div>
          </CardContent>
        </Card>

        {/* 邮件内容 */}
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle>邮件内容</CardTitle>
              <div className="flex gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setIsTemplateDialogOpen(true)}
                >
                  <FileText className="w-4 h-4 mr-1" />
                  使用模板
                </Button>
                <Button
                  type="button"
                  variant={viewMode === 'code' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setViewMode('code')}
                >
                  <Code className="w-4 h-4 mr-1" />
                  代码
                </Button>
                <Button
                  type="button"
                  variant={viewMode === 'preview' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setViewMode('preview')}
                >
                  <Eye className="w-4 h-4 mr-1" />
                  预览
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* 标签提示卡片 */}
            <div className="bg-gradient-to-r from-blue-50 to-indigo-50 p-4 rounded-lg border border-blue-200">
              <div className="flex items-start gap-3">
                <div className="flex-1 space-y-3">
                  {/* 订阅者标签 */}
                  <div>
                    <div className="text-xs font-semibold text-blue-900 mb-1.5">订阅者标签</div>
                    <div className="flex flex-wrap gap-1.5">
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{first_name}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{last_name}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{email}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{full_name}'}</code>
                    </div>
                  </div>

                  {/* 系统标签 */}
                  <div>
                    <div className="text-xs font-semibold text-blue-900 mb-1.5">系统标签</div>
                    <div className="flex flex-wrap gap-1.5">
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{campaign_id}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{date}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{list_name}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{server_name}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{sender_domain}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{unsubscribe_url}'}</code>
                    </div>
                  </div>

                  {/* 自定义标签 */}
                  {customTags && customTags.length > 0 && (
                    <div>
                      <div className="text-xs font-semibold text-blue-900 mb-1.5">
                        自定义标签 ({customTags.length}个)
                      </div>
                      <div className="flex flex-wrap gap-1.5">
                        {customTags.map((tag) => (
                          <code 
                            key={tag.id} 
                            className="bg-white px-2 py-0.5 rounded text-xs border border-purple-200 text-purple-700 cursor-pointer hover:bg-purple-50"
                            onClick={() => insertTagToContent(tag.placeholder)}
                            title={`点击插入 · ${tag.values_count}个值`}
                          >
                            {tag.placeholder}
                          </code>
                        ))}
                      </div>
                    </div>
                  )}
                </div>

                {customTags && customTags.length > 0 && viewMode === 'code' && (
                  <Select onValueChange={insertTagToContent}>
                    <SelectTrigger className="w-[200px] h-8 bg-white">
                      <Tag className="w-3 h-3 mr-1" />
                      <SelectValue placeholder="插入标签" />
                    </SelectTrigger>
                    <SelectContent>
                      <div className="px-2 py-1.5 text-xs font-semibold text-muted-foreground">
                        订阅者标签
                      </div>
                      <SelectItem value="{first_name}">名</SelectItem>
                      <SelectItem value="{last_name}">姓</SelectItem>
                      <SelectItem value="{email}">邮箱</SelectItem>
                      <SelectItem value="{full_name}">全名</SelectItem>
                      
                      <div className="px-2 py-1.5 text-xs font-semibold text-muted-foreground border-t mt-1 pt-2">
                        系统标签
                      </div>
                      <SelectItem value="{campaign_id}">活动ID</SelectItem>
                      <SelectItem value="{date}">日期</SelectItem>
                      <SelectItem value="{list_name}">列表名称</SelectItem>
                      <SelectItem value="{server_name}">服务器名称</SelectItem>
                      <SelectItem value="{sender_domain}">发件人域名</SelectItem>
                      <SelectItem value="{unsubscribe_url}">退订链接</SelectItem>
                      
                      <div className="px-2 py-1.5 text-xs font-semibold text-muted-foreground border-t mt-1 pt-2">
                        自定义标签
                      </div>
                      {customTags.map((tag) => (
                        <SelectItem key={tag.id} value={tag.placeholder}>
                          <div className="flex items-center justify-between w-full">
                            <span>{tag.label}</span>
                            <span className="text-xs text-muted-foreground ml-2">
                              {tag.values_count}个值
                            </span>
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </div>
            </div>

            {viewMode === 'code' ? (
              <div className="space-y-2">
                <Label htmlFor="html_content">HTML 代码</Label>
                <textarea
                  ref={contentTextareaRef}
                  id="html_content"
                  value={formData.html_content}
                  onChange={(e) => setFormData({ ...formData, html_content: e.target.value })}
                  className="w-full min-h-[800px] p-4 border rounded-md font-mono text-sm bg-gray-50"
                  placeholder="<h1>你好 {first_name}!</h1>"
                />
              </div>
            ) : (
              <div className="space-y-2">
                <Label>预览效果</Label>
                <div className="w-full min-h-[800px] border rounded-md bg-white overflow-hidden">
                  <iframe
                    ref={(iframe) => {
                      if (iframe && formData.html_content) {
                        const doc = iframe.contentDocument || iframe.contentWindow?.document
                        if (doc) {
                          const previewHtml = getPreviewHtml()
                          doc.open()
                          doc.write(previewHtml)
                          doc.close()
                        }
                      }
                    }}
                    className="w-full h-full min-h-[800px]"
                    title="邮件预览"
                  />
                </div>
                <p className="text-xs text-muted-foreground">
                  * 预览中显示的是替换后的示例数据
                </p>
              </div>
            )}
          </CardContent>
        </Card>

        {/* 发送选项 */}
        {!isEditing && (
          <Card>
            <CardHeader>
              <CardTitle>发送设置</CardTitle>
              <CardDescription>选择保存为草稿或直接发送</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div className="grid grid-cols-3 gap-4">
                  <button
                    type="button"
                    onClick={() => setSendMode('draft')}
                    className={`p-4 border-2 rounded-lg transition-all ${
                      sendMode === 'draft'
                        ? 'border-primary bg-primary/5'
                        : 'border-gray-200 hover:border-gray-300'
                    }`}
                  >
                    <div className="flex flex-col items-center gap-2">
                      <Save className="w-6 h-6" />
                      <span className="font-medium">保存草稿</span>
                      <span className="text-xs text-muted-foreground">稍后手动发送</span>
                    </div>
                  </button>

                  <button
                    type="button"
                    onClick={() => setSendMode('now')}
                    className={`p-4 border-2 rounded-lg transition-all ${
                      sendMode === 'now'
                        ? 'border-primary bg-primary/5'
                        : 'border-gray-200 hover:border-gray-300'
                    }`}
                  >
                    <div className="flex flex-col items-center gap-2">
                      <Send className="w-6 h-6" />
                      <span className="font-medium">立即发送</span>
                      <span className="text-xs text-muted-foreground">保存并立即发送</span>
                    </div>
                  </button>

                  <button
                    type="button"
                    onClick={() => setSendMode('schedule')}
                    className={`p-4 border-2 rounded-lg transition-all ${
                      sendMode === 'schedule'
                        ? 'border-primary bg-primary/5'
                        : 'border-gray-200 hover:border-gray-300'
                    }`}
                  >
                    <div className="flex flex-col items-center gap-2">
                      <Clock className="w-6 h-6" />
                      <span className="font-medium">定时发送</span>
                      <span className="text-xs text-muted-foreground">设置发送时间</span>
                    </div>
                  </button>
                </div>

                {sendMode === 'schedule' && (
                  <div className="p-4 bg-muted rounded-lg space-y-4">
                    {/* 快捷日期选择 */}
                    <div>
                      <Label className="mb-2 block">快捷选择</Label>
                      <div className="flex flex-wrap gap-2">
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            const date = new Date()
                            date.setHours(date.getHours() + 1)
                            setScheduledDateTime(date)
                          }}
                        >
                          1小时后
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            const date = new Date()
                            date.setDate(date.getDate() + 1)
                            date.setHours(9, 0, 0, 0)
                            setScheduledDateTime(date)
                          }}
                        >
                          明天上午9点
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            const date = new Date()
                            date.setDate(date.getDate() + 2)
                            date.setHours(9, 0, 0, 0)
                            setScheduledDateTime(date)
                          }}
                        >
                          后天上午9点
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            const date = new Date()
                            date.setDate(date.getDate() + 7)
                            date.setHours(9, 0, 0, 0)
                            setScheduledDateTime(date)
                          }}
                        >
                          下周一上午9点
                        </Button>
                      </div>
                    </div>

                    {/* 日期时间选择器 */}
                    <div className="space-y-2">
                      <Label>选择日期和时间</Label>
                      <DateTimePicker 
                        date={scheduledDateTime}
                        setDate={setScheduledDateTime}
                      />
                    </div>

                    {/* 预览提示 */}
                    {scheduledDateTime && (
                      <div className="flex items-center gap-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <Clock className="w-4 h-4 text-blue-600" />
                        <p className="text-sm text-blue-900">
                          将在 <strong className="font-semibold">{format(scheduledDateTime, 'yyyy-MM-dd HH:mm')}</strong> 发送
                        </p>
                      </div>
                    )}
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        )}

        {/* 操作按钮 */}
        <div className="flex justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => navigate('/campaigns')}
          >
            取消
          </Button>
          <Button
            type="submit"
            disabled={saveMutation.isPending || sendMutation.isPending}
          >
            {saveMutation.isPending || sendMutation.isPending ? (
              <>
                <Clock className="w-4 h-4 mr-2 animate-spin" />
                处理中...
              </>
            ) : (
              <>
                {sendMode === 'draft' && <Save className="w-4 h-4 mr-2" />}
                {sendMode === 'now' && <Send className="w-4 h-4 mr-2" />}
                {sendMode === 'schedule' && <Clock className="w-4 h-4 mr-2" />}
                {isEditing
                  ? '保存修改'
                  : sendMode === 'draft'
                  ? '保存为草稿'
                  : sendMode === 'now'
                  ? '保存并立即发送'
                  : '保存并定时发送'}
              </>
            )}
          </Button>
          {isEditing && (
            <Button
              type="button"
              onClick={() => setIsSendDialogOpen(true)}
              disabled={!id}
            >
              <Send className="w-4 h-4 mr-2" />
              发送活动
            </Button>
          )}
        </div>
      </form>

      {/* 发送对话框 */}
      <Dialog open={isSendDialogOpen} onOpenChange={setIsSendDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>发送邮件活动</DialogTitle>
            <DialogDescription>选择立即发送或定时发送</DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div className="flex gap-4">
              <button
                type="button"
                onClick={() => setSendMode('now')}
                className={`flex-1 p-4 border-2 rounded-lg transition-all ${
                  sendMode === 'now'
                    ? 'border-primary bg-primary/5'
                    : 'border-gray-200 hover:border-gray-300'
                }`}
              >
                <div className="flex flex-col items-center gap-2">
                  <Send className="w-8 h-8" />
                  <span className="font-medium">立即发送</span>
                  <span className="text-xs text-muted-foreground">马上发送给所有订阅者</span>
                </div>
              </button>

              <button
                type="button"
                onClick={() => setSendMode('schedule')}
                className={`flex-1 p-4 border-2 rounded-lg transition-all ${
                  sendMode === 'schedule'
                    ? 'border-primary bg-primary/5'
                    : 'border-gray-200 hover:border-gray-300'
                }`}
              >
                <div className="flex flex-col items-center gap-2">
                  <Clock className="w-8 h-8" />
                  <span className="font-medium">定时发送</span>
                  <span className="text-xs text-muted-foreground">选择发送时间</span>
                </div>
              </button>
            </div>

            {sendMode === 'schedule' && (
              <div className="space-y-4 p-4 bg-muted rounded-lg">
                {/* 快捷日期选择 */}
                <div>
                  <Label className="mb-2 block">快捷选择</Label>
                  <div className="flex flex-wrap gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        const date = new Date()
                        date.setHours(date.getHours() + 1)
                        setScheduledDateTime(date)
                      }}
                    >
                      1小时后
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        const date = new Date()
                        date.setDate(date.getDate() + 1)
                        date.setHours(9, 0, 0, 0)
                        setScheduledDateTime(date)
                      }}
                    >
                      明天上午9点
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        const date = new Date()
                        date.setDate(date.getDate() + 2)
                        date.setHours(9, 0, 0, 0)
                        setScheduledDateTime(date)
                      }}
                    >
                      后天上午9点
                    </Button>
                  </div>
                </div>

                {/* 日期时间选择器 */}
                <div className="space-y-2">
                  <Label>选择日期和时间</Label>
                  <DateTimePicker 
                    date={scheduledDateTime}
                    setDate={setScheduledDateTime}
                  />
                </div>

                {/* 预览提示 */}
                {scheduledDateTime && (
                  <div className="flex items-center gap-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <Clock className="w-4 h-4 text-blue-600" />
                    <p className="text-sm text-blue-900">
                      将在 <strong className="font-semibold">{format(scheduledDateTime, 'yyyy-MM-dd HH:mm')}</strong> 发送
                    </p>
                  </div>
                )}
              </div>
            )}

            <div className="flex justify-end gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsSendDialogOpen(false)}
              >
                取消
              </Button>
              <Button
                type="button"
                onClick={sendMode === 'now' ? handleSendNow : handleScheduleSend}
                disabled={sendMutation.isPending}
              >
                {sendMutation.isPending ? '发送中...' : sendMode === 'now' ? '立即发送' : '定时发送'}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* 模板选择Dialog */}
      <Dialog open={isTemplateDialogOpen} onOpenChange={setIsTemplateDialogOpen}>
        <DialogContent className="max-w-4xl max-h-[80vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>选择邮件模板</DialogTitle>
            <DialogDescription>
              选择一个模板作为邮件内容的起点，您可以在应用后继续编辑
            </DialogDescription>
          </DialogHeader>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 py-4">
            {templates && templates.length > 0 ? (
              templates.map((template) => (
                <Card 
                  key={template.id} 
                  className="cursor-pointer hover:border-primary transition-colors"
                  onClick={() => applyTemplate(template)}
                >
                  <CardHeader>
                    <CardTitle className="text-base">{template.name}</CardTitle>
                    <CardDescription className="line-clamp-2">
                      {template.description || '暂无描述'}
                    </CardDescription>
                  </CardHeader>
                  <CardContent>
                    <div className="flex items-center justify-between">
                      <Badge variant="secondary">
                        {template.category}
                      </Badge>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={(e) => {
                          e.stopPropagation()
                          applyTemplate(template)
                        }}
                      >
                        使用此模板
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              ))
            ) : (
              <div className="col-span-2 flex flex-col items-center justify-center py-12">
                <FileText className="w-12 h-12 text-muted-foreground mb-4" />
                <p className="text-muted-foreground mb-4">
                  还没有可用的模板
                </p>
                <Button
                  variant="outline"
                  onClick={() => {
                    setIsTemplateDialogOpen(false)
                    navigate('/templates/create')
                  }}
                >
                  创建模板
                </Button>
              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
