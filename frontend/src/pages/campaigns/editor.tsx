import { useState, useEffect, useRef } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
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
  const { t } = useTranslation()
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
    html_content: '',
  })

  const [viewMode, setViewMode] = useState<'code' | 'preview'>('code')
  const [sendMode, setSendMode] = useState<'draft' | 'now' | 'schedule'>('draft')
  const [scheduledDateTime, setScheduledDateTime] = useState<Date | undefined>()
  const [isSendDialogOpen, setIsSendDialogOpen] = useState(false)
  const [isTemplateDialogOpen, setIsTemplateDialogOpen] = useState(false)
  const [isSaveBeforeSend, setIsSaveBeforeSend] = useState(false) // 标记是否是"保存后发送"操作
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

  // 加载活动数据（仅编辑模式）- 等待必要数据加载完成以避免竞态条件
  useEffect(() => {
    if (!campaign) {
      return
    }

    // 如果是编辑模式，等待必要的数据加载完成
    if (isEditing) {
      // 等待 smtpServers 加载（如果有 smtp_server_id）
      if (campaign.smtp_server_id && (!smtpServers || smtpServers.length === 0)) {
        return
      }
      
      // 等待 lists 加载（如果有 list_ids）
      const hasListIds = campaign.list_ids && campaign.list_ids.length > 0
      if (hasListIds && (!lists || lists.length === 0)) {
        return
      }
    }
    
    const newFormData = {
      list_ids: campaign.list_ids || (campaign.list_id ? [campaign.list_id] : []),
      smtp_server_id: campaign.smtp_server_id ? campaign.smtp_server_id.toString() : '',
      name: campaign.name || '',
      subject: campaign.subject || '',
      preview_text: campaign.preview_text || '',
      from_name: campaign.from_name || '',
      from_email: campaign.from_email || '',
      reply_to: campaign.reply_to || '',
      html_content: campaign.html_content || '',
    }
    
    setFormData(newFormData)
    
    // 如果有定时发送时间，设置为定时模式
    if (campaign.scheduled_at) {
      setSendMode('schedule')
      setScheduledDateTime(new Date(campaign.scheduled_at))
    }
  }, [campaign, smtpServers, lists, isEditing])


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
      
      if (isSaveBeforeSend) {
        setIsSaveBeforeSend(false)
        return
      }
      
      if (!isEditing && sendMode !== 'draft') {
        const newCampaignId = response.data.data.id
        
        if (sendMode === 'now') {
          sendMutation.mutate({ campaignId: newCampaignId })
        } else if (sendMode === 'schedule') {
          if (!scheduledDateTime) {
            toast.error(t('campaigns.editor.selectDateTimeRequired'))
            return
          }
          const scheduledAt = format(scheduledDateTime, 'yyyy-MM-dd HH:mm:ss')
          sendMutation.mutate({ campaignId: newCampaignId, scheduledAt })
        }
      } else {
        toast.success(isEditing ? t('campaigns.updateSuccess') : t('campaigns.editor.savedAsDraft'))
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
      
      if (isEditing) {
        toast.success(t('campaigns.updateSuccess'))
      } else {
        toast.success(t('campaigns.createSuccess'))
      }
      navigate('/campaigns')
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!formData.list_ids || formData.list_ids.length === 0) {
      toast.error(t('campaigns.editor.selectAtLeastOneList'))
      return
    }
    if (!formData.smtp_server_id) {
      toast.error(t('campaigns.editor.selectServerRequired'))
      return
    }
    
    if (sendMode === 'schedule' && !scheduledDateTime) {
      toast.error(t('campaigns.editor.selectDateTimeRequired'))
      return
    }
    
    saveMutation.mutate(formData)
  }

  const handleSendNow = async () => {
    if (!id) {
      toast.error(t('campaigns.editor.saveCampaignFirst'))
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
      setIsSaveBeforeSend(false)
      console.error('Save failed:', error)
    }
  }

  const handleScheduleSend = async () => {
    if (!id) {
      toast.error(t('campaigns.editor.saveCampaignFirst'))
      return
    }
    if (!scheduledDateTime) {
      toast.error(t('campaigns.editor.selectDateTimeRequired'))
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
      setIsSaveBeforeSend(false)
      console.error('Save failed:', error)
    }
  }

  const getPreviewHtml = () => {
    let html = formData.html_content
    
    const selectedListNames = lists
      ?.filter(list => formData.list_ids.includes(list.id))
      .map(list => list.name)
      .join(', ') || t('campaigns.sampleList')
    
    const selectedServerName = smtpServers
      ?.find(server => server.id.toString() === formData.smtp_server_id)
      ?.name || t('campaigns.sampleServer')
    
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
    
    html = html
      .replace(/{first_name}/g, t('campaigns.editor.sampleFirstName'))
      .replace(/{last_name}/g, t('campaigns.editor.sampleLastName'))
      .replace(/{email}/g, exampleEmail)
      .replace(/{full_name}/g, t('campaigns.editor.sampleFullName'))
    
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

  const applyTemplate = (template: Template) => {
    setFormData({ ...formData, html_content: template.html_content })
    setIsTemplateDialogOpen(false)
    toast.success(t('campaigns.editor.templateApplied', { name: template.name }))
  }

  return (
    <div className="space-y-6">
      {/* 页头 */}
      <div>
        <div className="flex items-center gap-2 text-sm text-muted-foreground mb-2">
          <Link to="/campaigns" className="hover:text-primary flex items-center gap-1">
            <Mail className="w-4 h-4" />
            {t('campaigns.title')}
          </Link>
          <span>/</span>
          <span className="text-foreground font-medium">
            {isEditing ? t('campaigns.editCampaign') : t('campaigns.createCampaign')}
          </span>
        </div>
        <h1 className="text-xl md:text-2xl font-bold tracking-tight">
          {isEditing ? t('campaigns.editCampaign') : t('campaigns.editor.createNewCampaign')}
        </h1>
        <p className="text-muted-foreground mt-2">
          {isEditing ? t('campaigns.editor.editCampaignContent') : t('campaigns.editor.createCampaignDesc')}
        </p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* 基本信息 */}
        <Card>
          <CardHeader>
            <CardTitle>{t('campaigns.editor.basicInfo')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* 第一行：活动名称 + 发送服务器 */}
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="name">{t('campaigns.campaignName')} *</Label>
                <Input
                  id="name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder={t('campaigns.editor.campaignNamePlaceholder')}
                  required
                />
              </div>
              <div className="space-y-2">
                <Label>{t('campaigns.sendServer')} *</Label>
                <Select
                  value={formData.smtp_server_id || ''}
                  onValueChange={(value) => setFormData({ ...formData, smtp_server_id: value })}
                >
                  <SelectTrigger id="smtp_server_id">
                    <SelectValue placeholder={t('campaigns.selectServer')} />
                  </SelectTrigger>
                  <SelectContent>
                    {smtpServers?.filter(s => s.is_active).map((server) => (
                      <SelectItem key={server.id} value={server.id.toString()}>
                        {server.name} {server.is_default && t('campaigns.editor.default')}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            {/* 第二行：邮件列表（单独一行） */}
            <div className="space-y-2">
              <Label>{t('campaigns.editor.mailingList')} *</Label>
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
                        ({list.subscribers_count} {t('campaigns.editor.subscribers')})
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
              <Label htmlFor="subject">{t('campaigns.editor.emailSubject')} *</Label>
              <Input
                ref={subjectInputRef}
                id="subject"
                value={formData.subject}
                onChange={(e) => setFormData({ ...formData, subject: e.target.value })}
                placeholder={t('campaigns.editor.subjectPlaceholder')}
                required
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="preview_text">
                {t('campaigns.previewText')}
                <span className="text-muted-foreground ml-2 text-xs font-normal">
                  {t('campaigns.editor.previewTextHint')}
                </span>
              </Label>
              <Input
                id="preview_text"
                value={formData.preview_text}
                onChange={(e) => setFormData({ ...formData, preview_text: e.target.value })}
                placeholder={t('campaigns.editor.previewTextPlaceholder')}
                maxLength={150}
              />
              <p className="text-xs text-muted-foreground">
                {t('campaigns.editor.previewTextTip')}
              </p>
            </div>
          </CardContent>
        </Card>

        {/* 发件人信息 */}
        <Card>
          <CardHeader>
            <CardTitle>{t('campaigns.editor.senderInfo')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="from_name">{t('campaigns.fromName')} *</Label>
                <Input
                  id="from_name"
                  value={formData.from_name}
                  onChange={(e) => setFormData({ ...formData, from_name: e.target.value })}
                  placeholder={t('campaigns.editor.fromNamePlaceholder')}
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="from_email">
                  {t('campaigns.fromEmail')}
                  <span className="text-muted-foreground ml-2 text-xs font-normal">
                    {t('campaigns.editor.fromEmailHint')}
                  </span>
                </Label>
                <Input
                  id="from_email"
                  type="email"
                  value={formData.from_email}
                  onChange={(e) => setFormData({ ...formData, from_email: e.target.value })}
                  placeholder={t('campaigns.editor.fromEmailPlaceholder')}
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="reply_to">
                {t('campaigns.replyTo')}
                <span className="text-muted-foreground ml-2 text-xs font-normal">
                  {t('campaigns.editor.replyToHint')}
                </span>
              </Label>
              <Input
                id="reply_to"
                type="email"
                value={formData.reply_to}
                onChange={(e) => setFormData({ ...formData, reply_to: e.target.value })}
                placeholder={t('campaigns.editor.replyToPlaceholder')}
              />
            </div>
          </CardContent>
        </Card>

        {/* 邮件内容 */}
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle>{t('campaigns.editor.emailContent')}</CardTitle>
              <div className="flex gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setIsTemplateDialogOpen(true)}
                >
                  <FileText className="w-4 h-4 mr-1" />
                  {t('campaigns.editor.useTemplate')}
                </Button>
                <Button
                  type="button"
                  variant={viewMode === 'code' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setViewMode('code')}
                >
                  <Code className="w-4 h-4 mr-1" />
                  {t('campaigns.editor.code')}
                </Button>
                <Button
                  type="button"
                  variant={viewMode === 'preview' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setViewMode('preview')}
                >
                  <Eye className="w-4 h-4 mr-1" />
                  {t('common.preview')}
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
                    <div className="text-xs font-semibold text-blue-900 mb-1.5">{t('campaigns.editor.subscriberTags')}</div>
                    <div className="flex flex-wrap gap-1.5">
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{first_name}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{last_name}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{email}'}</code>
                      <code className="bg-white px-2 py-0.5 rounded text-xs border">{'{full_name}'}</code>
                    </div>
                  </div>

                  {/* 系统标签 */}
                  <div>
                    <div className="text-xs font-semibold text-blue-900 mb-1.5">{t('campaigns.editor.systemTags')}</div>
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
                        {t('campaigns.editor.customTags')} {t('campaigns.editor.customTagsCount', { count: customTags.length })}
                      </div>
                      <div className="flex flex-wrap gap-1.5">
                        {customTags.map((tag) => (
                          <code 
                            key={tag.id} 
                            className="bg-white px-2 py-0.5 rounded text-xs border border-purple-200 text-purple-700 cursor-pointer hover:bg-purple-50"
                            onClick={() => insertTagToContent(tag.placeholder)}
                            title={`${t('campaigns.editor.clickToInsert')} · ${t('campaigns.editor.valuesCount', { count: tag.values_count })}`}
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
                      <SelectValue placeholder={t('campaigns.editor.insertTag')} />
                    </SelectTrigger>
                    <SelectContent>
                      <div className="px-2 py-1.5 text-xs font-semibold text-muted-foreground">
                        {t('campaigns.editor.subscriberTags')}
                      </div>
                      <SelectItem value="{first_name}">{t('campaigns.editor.firstName')}</SelectItem>
                      <SelectItem value="{last_name}">{t('campaigns.editor.lastName')}</SelectItem>
                      <SelectItem value="{email}">{t('campaigns.editor.email')}</SelectItem>
                      <SelectItem value="{full_name}">{t('campaigns.editor.fullName')}</SelectItem>
                      
                      <div className="px-2 py-1.5 text-xs font-semibold text-muted-foreground border-t mt-1 pt-2">
                        {t('campaigns.editor.systemTags')}
                      </div>
                      <SelectItem value="{campaign_id}">{t('campaigns.editor.campaignId')}</SelectItem>
                      <SelectItem value="{date}">{t('campaigns.editor.date')}</SelectItem>
                      <SelectItem value="{list_name}">{t('campaigns.editor.listName')}</SelectItem>
                      <SelectItem value="{server_name}">{t('campaigns.editor.serverName')}</SelectItem>
                      <SelectItem value="{sender_domain}">{t('campaigns.editor.senderDomain')}</SelectItem>
                      <SelectItem value="{unsubscribe_url}">{t('campaigns.editor.unsubscribeUrl')}</SelectItem>
                      
                      <div className="px-2 py-1.5 text-xs font-semibold text-muted-foreground border-t mt-1 pt-2">
                        {t('campaigns.editor.customTags')}
                      </div>
                      {customTags.map((tag) => (
                        <SelectItem key={tag.id} value={tag.placeholder}>
                          <div className="flex items-center justify-between w-full">
                            <span>{tag.label}</span>
                            <span className="text-xs text-muted-foreground ml-2">
                              {t('campaigns.editor.valuesCount', { count: tag.values_count })}
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
                <Label htmlFor="html_content">{t('campaigns.editor.htmlCode')}</Label>
                <textarea
                  ref={contentTextareaRef}
                  id="html_content"
                  value={formData.html_content}
                  onChange={(e) => setFormData({ ...formData, html_content: e.target.value })}
                  className="w-full min-h-[800px] p-4 border rounded-md font-mono text-sm bg-gray-50"
                  placeholder={t('campaigns.editor.htmlPlaceholder')}
                />
              </div>
            ) : (
              <div className="space-y-2">
                <Label>{t('campaigns.editor.previewEffect')}</Label>
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
                    title={t('campaigns.editor.emailPreview')}
                  />
                </div>
                <p className="text-xs text-muted-foreground">
                  {t('campaigns.editor.previewSampleData')}
                </p>
              </div>
            )}
          </CardContent>
        </Card>

        {/* 发送选项 */}
        {!isEditing && (
          <Card>
            <CardHeader>
              <CardTitle>{t('campaigns.editor.sendSettings')}</CardTitle>
              <CardDescription>{t('campaigns.editor.sendSettingsDesc')}</CardDescription>
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
                      <span className="font-medium">{t('campaigns.editor.saveDraft')}</span>
                      <span className="text-xs text-muted-foreground">{t('campaigns.editor.laterSendManually')}</span>
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
                      <span className="font-medium">{t('campaigns.sendNow')}</span>
                      <span className="text-xs text-muted-foreground">{t('campaigns.editor.saveAndSendNow')}</span>
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
                      <span className="font-medium">{t('campaigns.schedule')}</span>
                      <span className="text-xs text-muted-foreground">{t('campaigns.editor.setSendTime')}</span>
                    </div>
                  </button>
                </div>

                {sendMode === 'schedule' && (
                  <div className="p-4 bg-muted rounded-lg space-y-4">
                    {/* 快捷日期选择 */}
                    <div>
                      <Label className="mb-2 block">{t('campaigns.quickSchedule.title')}</Label>
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
                          {t('campaigns.quickSchedule.oneHourLater')}
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            const date = new Date()
                            date.setHours(18, 30, 0, 0)
                            if (date <= new Date()) {
                              setSendMode('now')
                              return
                            }
                            setScheduledDateTime(date)
                          }}
                        >
                          {t('campaigns.quickSchedule.tonightAt630')}
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
                          {t('campaigns.quickSchedule.tomorrowAt9')}
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
                          {t('campaigns.quickSchedule.dayAfterAt9')}
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
                          {t('campaigns.quickSchedule.nextMondayAt9')}
                        </Button>
                      </div>
                    </div>

                    {/* 日期时间选择器 */}
                    <div className="space-y-2">
                      <Label>{t('campaigns.editor.selectDateTime')}</Label>
                      <DateTimePicker 
                        date={scheduledDateTime}
                        setDate={setScheduledDateTime}
                      />
                    </div>

                    {/* 预览提示 */}
                    {scheduledDateTime && (
                      <div className="flex items-center gap-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <Clock className="w-4 h-4 text-blue-600" />
                        <p className="text-sm text-blue-900" dangerouslySetInnerHTML={{ __html: t('campaigns.editor.willSendAt', { time: format(scheduledDateTime, 'yyyy-MM-dd HH:mm') }) }} />
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
            {t('common.cancel')}
          </Button>
          <Button
            type="submit"
            disabled={saveMutation.isPending || sendMutation.isPending}
          >
            {saveMutation.isPending || sendMutation.isPending ? (
              <>
                <Clock className="w-4 h-4 mr-2 animate-spin" />
                {t('common.processing')}...
              </>
            ) : (
              <>
                {sendMode === 'draft' && <Save className="w-4 h-4 mr-2" />}
                {sendMode === 'now' && <Send className="w-4 h-4 mr-2" />}
                {sendMode === 'schedule' && <Clock className="w-4 h-4 mr-2" />}
                {isEditing
                  ? t('campaigns.editor.saveChanges')
                  : sendMode === 'draft'
                  ? t('campaigns.saveAsDraft')
                  : sendMode === 'now'
                  ? t('campaigns.editor.saveAndSendNow')
                  : t('campaigns.editor.saveAndSchedule')}
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
              {t('campaigns.editor.sendCampaign')}
            </Button>
          )}
        </div>
      </form>

      {/* 发送对话框 */}
      <Dialog open={isSendDialogOpen} onOpenChange={setIsSendDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('campaigns.sendDialog.title')}</DialogTitle>
            <DialogDescription>{t('campaigns.sendDialog.subtitle')}</DialogDescription>
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
                  <span className="font-medium">{t('campaigns.sendNow')}</span>
                  <span className="text-xs text-muted-foreground">{t('campaigns.sendDialog.sendNowDesc')}</span>
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
                  <span className="font-medium">{t('campaigns.schedule')}</span>
                  <span className="text-xs text-muted-foreground">{t('campaigns.sendDialog.scheduleDesc')}</span>
                </div>
              </button>
            </div>

            {sendMode === 'schedule' && (
              <div className="space-y-4 p-4 bg-muted rounded-lg">
                {/* 快捷日期选择 */}
                <div>
                  <Label className="mb-2 block">{t('campaigns.quickSchedule.title')}</Label>
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
                      {t('campaigns.quickSchedule.oneHourLater')}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        const date = new Date()
                        date.setHours(18, 30, 0, 0)
                        if (date <= new Date()) {
                          setSendMode('now')
                          return
                        }
                        setScheduledDateTime(date)
                      }}
                    >
                      {t('campaigns.quickSchedule.tonightAt630')}
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
                      {t('campaigns.quickSchedule.tomorrowAt9')}
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
                      {t('campaigns.quickSchedule.dayAfterAt9')}
                    </Button>
                  </div>
                </div>

                {/* 日期时间选择器 */}
                <div className="space-y-2">
                  <Label>{t('campaigns.editor.selectDateTime')}</Label>
                  <DateTimePicker 
                    date={scheduledDateTime}
                    setDate={setScheduledDateTime}
                  />
                </div>

                {/* 预览提示 */}
                {scheduledDateTime && (
                  <div className="flex items-center gap-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <Clock className="w-4 h-4 text-blue-600" />
                    <p className="text-sm text-blue-900" dangerouslySetInnerHTML={{ __html: t('campaigns.editor.willSendAt', { time: format(scheduledDateTime, 'yyyy-MM-dd HH:mm') }) }} />
                  </div>
                )}
              </div>
            )}

            <div className="flex justify-end gap-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsSendDialogOpen(false)}
                disabled={saveMutation.isPending || sendMutation.isPending}
              >
                {t('common.cancel')}
              </Button>
              <Button
                type="button"
                onClick={sendMode === 'now' ? handleSendNow : handleScheduleSend}
                disabled={saveMutation.isPending || sendMutation.isPending}
              >
                {saveMutation.isPending ? (
                  <>
                    <Clock className="w-4 h-4 mr-2 animate-spin" />
                    {t('campaigns.editor.saving')}
                  </>
                ) : sendMutation.isPending ? (
                  <>
                    <Clock className="w-4 h-4 mr-2 animate-spin" />
                    {t('campaigns.editor.sending')}
                  </>
                ) : sendMode === 'now' ? (
                  <>
                    <Send className="w-4 h-4 mr-2" />
                    {t('campaigns.sendNow')}
                  </>
                ) : (
                  <>
                    <Clock className="w-4 h-4 mr-2" />
                    {t('campaigns.schedule')}
                  </>
                )}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* 模板选择Dialog */}
      <Dialog open={isTemplateDialogOpen} onOpenChange={setIsTemplateDialogOpen}>
        <DialogContent className="max-w-4xl max-h-[80vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{t('campaigns.editor.selectTemplateTitle')}</DialogTitle>
            <DialogDescription>
              {t('campaigns.editor.selectTemplateDesc')}
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
                      {template.description || t('templates.noDescription')}
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
                        {t('campaigns.editor.useThisTemplate')}
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              ))
            ) : (
              <div className="col-span-2 flex flex-col items-center justify-center py-12">
                <FileText className="w-12 h-12 text-muted-foreground mb-4" />
                <p className="text-muted-foreground mb-4">
                  {t('campaigns.editor.noTemplates')}
                </p>
                <Button
                  variant="outline"
                  onClick={() => {
                    setIsTemplateDialogOpen(false)
                    navigate('/templates/create')
                  }}
                >
                  {t('campaigns.editor.createTemplate')}
                </Button>
              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
