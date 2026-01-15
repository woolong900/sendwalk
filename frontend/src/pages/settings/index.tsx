import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Server, Trash2, Edit, Check, X, Zap, Copy } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { api } from '@/lib/api'
import { useConfirm } from '@/hooks/use-confirm'

interface RateLimitPeriod {
  limit: number | null
  current: number
  available: number | null
  percentage: number
  status: string
}

interface PausedSender {
  email: string
  remaining_seconds: number
}

interface SmtpServer {
  id: number
  name: string
  type: string
  host: string
  port: number
  username: string
  encryption: string
  sender_emails: string | null
  is_default: boolean
  is_active: boolean
  rate_limit_second: number | null
  rate_limit_minute: number | null
  rate_limit_hour: number | null
  rate_limit_day: number | null
  emails_sent_today: number
  rate_limit_status?: {
    second?: RateLimitPeriod
    minute?: RateLimitPeriod
    hour?: RateLimitPeriod
    day?: RateLimitPeriod
    paused_senders?: PausedSender[]
  }
}

const serverTypes = [
  { value: 'smtp', label: 'SMTP 服务器' },
  { value: 'ses', label: 'Amazon SES' },
]

export default function SettingsPage() {
  const { confirm, ConfirmDialog } = useConfirm()
  
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const [isEditOpen, setIsEditOpen] = useState(false)
  const [editingServer, setEditingServer] = useState<SmtpServer | null>(null)
  const [formData, setFormData] = useState({
    name: '',
    type: 'smtp',
    host: '',
    port: '587',
    username: '',
    password: '',
    encryption: 'tls',
    sender_emails: '',
    is_default: false,
    rate_limit_second: '',
    rate_limit_minute: '',
    rate_limit_hour: '',
    rate_limit_day: '',
  })
  
  // 发件人列表管理
  const [senderEmails, setSenderEmails] = useState<string[]>([])
  const [newSenderEmail, setNewSenderEmail] = useState('')
  const [pausedSenders, setPausedSenders] = useState<PausedSender[]>([])

  const queryClient = useQueryClient()

  // 获取服务器列表
  const { data: servers, isLoading } = useQuery<SmtpServer[]>({
    queryKey: ['smtp-servers'],
    queryFn: async () => {
      const response = await api.get('/smtp-servers')
      return response.data.data
    },
  })

  // 创建服务器
  const createMutation = useMutation({
    mutationFn: async (data: any) => {
      return api.post('/smtp-servers', data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['smtp-servers'] })
      toast.success('SMTP服务器创建成功')
      setIsCreateOpen(false)
      resetForm()
    },
  })

  // 更新服务器
  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: any }) => {
      return api.put(`/smtp-servers/${id}`, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['smtp-servers'] })
      toast.success('SMTP服务器更新成功')
      setIsEditOpen(false)
      setEditingServer(null)
      resetForm()
    },
  })

  // 删除服务器
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/smtp-servers/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['smtp-servers'] })
      toast.success('SMTP服务器删除成功')
    },
    // onError 已由全局拦截器处理
  })

  // 切换启用状态
  const toggleMutation = useMutation({
    mutationFn: async ({ id, is_active }: { id: number; is_active: boolean }) => {
      return api.put(`/smtp-servers/${id}`, { is_active })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['smtp-servers'] })
      toast.success('状态更新成功')
    },
  })

  // 测试连接
  const testMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.post(`/smtp-servers/${id}/test`)
    },
    onSuccess: () => {
      toast.success('连接测试成功')
    },
    // onError 已由全局拦截器处理，无需重复显示
  })

  // 复制服务器
  const duplicateMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.post(`/smtp-servers/${id}/duplicate`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['smtp-servers'] })
      toast.success('服务器复制成功')
    },
  })

  const resetForm = () => {
    setFormData({
      name: '',
      type: 'smtp',
      host: '',
      port: '587',
      username: '',
      password: '',
      encryption: 'tls',
      sender_emails: '',
      is_default: false,
      rate_limit_second: '',
      rate_limit_minute: '',
      rate_limit_hour: '',
      rate_limit_day: '',
    })
    setSenderEmails([])
    setNewSenderEmail('')
    setPausedSenders([])
  }
  
  const handleCreate = () => {
    resetForm()
    setIsCreateOpen(true)
  }

  const handleEdit = (server: SmtpServer) => {
    setEditingServer(server)
    setFormData({
      name: server.name,
      type: server.type,
      host: server.host || '',
      port: server.port?.toString() || '587',
      username: server.username || '',
      password: '',
      encryption: server.encryption || 'tls',
      sender_emails: server.sender_emails || '',
      is_default: server.is_default,
      rate_limit_second: server.rate_limit_second?.toString() || '',
      rate_limit_minute: server.rate_limit_minute?.toString() || '',
      rate_limit_hour: server.rate_limit_hour?.toString() || '',
      rate_limit_day: server.rate_limit_day?.toString() || '',
    })
    
    // 解析发件人列表
    const emails = server.sender_emails 
      ? server.sender_emails.split('\n').map(e => e.trim()).filter(e => e)
      : []
    setSenderEmails(emails)
    
    // 获取暂停状态
    setPausedSenders(server.rate_limit_status?.paused_senders || [])
    
    setIsEditOpen(true)
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    
    // SES 类型必须至少有一个发件人
    if (formData.type === 'ses' && senderEmails.length === 0) {
      toast.error('AWS SES 服务器至少需要配置一个发件人邮箱')
      return
    }
    
    const data = {
      ...formData,
      sender_emails: senderEmails.join('\n'), // 将数组转换为换行符分隔的字符串
      port: formData.port ? parseInt(formData.port) : null,
      rate_limit_second: formData.rate_limit_second ? parseInt(formData.rate_limit_second) : null,
      rate_limit_minute: formData.rate_limit_minute ? parseInt(formData.rate_limit_minute) : null,
      rate_limit_hour: formData.rate_limit_hour ? parseInt(formData.rate_limit_hour) : null,
      rate_limit_day: formData.rate_limit_day ? parseInt(formData.rate_limit_day) : null,
    }

    createMutation.mutate(data)
  }

  const handleUpdate = (e: React.FormEvent) => {
    e.preventDefault()
    if (!editingServer) return
    
    // SES 类型必须至少有一个发件人
    if (formData.type === 'ses' && senderEmails.length === 0) {
      toast.error('AWS SES 服务器至少需要配置一个发件人邮箱')
      return
    }

    const data = {
      ...formData,
      sender_emails: senderEmails.join('\n'), // 将数组转换为换行符分隔的字符串
      port: formData.port ? parseInt(formData.port) : null,
      rate_limit_second: formData.rate_limit_second ? parseInt(formData.rate_limit_second) : null,
      rate_limit_minute: formData.rate_limit_minute ? parseInt(formData.rate_limit_minute) : null,
      rate_limit_hour: formData.rate_limit_hour ? parseInt(formData.rate_limit_hour) : null,
      rate_limit_day: formData.rate_limit_day ? parseInt(formData.rate_limit_day) : null,
    }

    updateMutation.mutate({ id: editingServer.id, data })
  }
  
  // 添加发件人
  const handleAddSender = () => {
    const email = newSenderEmail.trim()
    if (!email) {
      toast.error('请输入发件人邮箱')
      return
    }
    
    // 简单的邮箱验证
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      toast.error('请输入有效的邮箱地址')
      return
    }
    
    if (senderEmails.includes(email)) {
      toast.error('该发件人已存在')
      return
    }
    
    setSenderEmails([...senderEmails, email])
    setNewSenderEmail('')
  }
  
  // 删除发件人
  const handleRemoveSender = (email: string) => {
    setSenderEmails(senderEmails.filter(e => e !== email))
  }

  const renderServerForm = (isEdit: boolean) => (
    <form onSubmit={isEdit ? handleUpdate : handleSubmit} className="space-y-4">
      <div className="space-y-2">
        <Label htmlFor="name">服务器名称 *</Label>
        <Input
          id="name"
          value={formData.name}
          onChange={(e) => setFormData({ ...formData, name: e.target.value })}
          placeholder="例如：主SMTP服务器"
          required
        />
      </div>

      <div className="space-y-2">
        <Label htmlFor="type">服务器类型 *</Label>
        <Select 
          value={formData.type} 
          onValueChange={(value) => setFormData({ ...formData, type: value })}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {serverTypes.map((type) => (
              <SelectItem key={type.value} value={type.value}>
                {type.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {formData.type === 'smtp' && (
        <>
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="host">主机地址 *</Label>
              <Input
                id="host"
                value={formData.host}
                onChange={(e) => setFormData({ ...formData, host: e.target.value })}
                placeholder="smtp.example.com"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="port">端口 *</Label>
              <Input
                id="port"
                type="number"
                value={formData.port}
                onChange={(e) => setFormData({ ...formData, port: e.target.value })}
                placeholder="587"
                required
              />
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="username">用户名</Label>
            <Input
              id="username"
              value={formData.username}
              onChange={(e) => setFormData({ ...formData, username: e.target.value })}
              placeholder="your@email.com"
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="password">密码 {isEdit && '(留空则不修改)'}</Label>
            <Input
              id="password"
              type="password"
              value={formData.password}
              onChange={(e) => setFormData({ ...formData, password: e.target.value })}
              placeholder="••••••"
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="encryption">加密方式</Label>
            <Select
              value={formData.encryption}
              onValueChange={(value) => setFormData({ ...formData, encryption: value })}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="tls">TLS</SelectItem>
                <SelectItem value="ssl">SSL</SelectItem>
                <SelectItem value="none">无加密</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>
              发件人邮箱管理
              <span className="text-muted-foreground ml-2 text-xs font-normal">
                (可选，配置多个发件人轮换使用)
              </span>
            </Label>
            
            {/* 发件人列表 */}
            <div className="space-y-2">
              {senderEmails.map((email, index) => {
                const isPaused = pausedSenders.some(s => s.email === email)
                const pausedInfo = pausedSenders.find(s => s.email === email)
                
                return (
                  <div key={index} className="grid gap-2" style={{ gridTemplateColumns: '1fr auto' }}>
                    <div className="flex items-center gap-2 min-w-0">
                      <Input
                        value={email}
                        readOnly
                        className={`font-mono text-sm ${isPaused ? 'bg-amber-50 border-amber-300' : 'bg-green-50 border-green-300'}`}
                      />
                      {isPaused && (
                        <span className="text-xs text-amber-600 whitespace-nowrap">
                          暂停中 ({Math.ceil((pausedInfo?.remaining_seconds || 0) / 60)}分钟)
                        </span>
                      )}
                    </div>
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => handleRemoveSender(email)}
                      title="删除此发件人"
                      className="h-8"
                    >
                      <Trash2 className="w-4 h-4 mr-1" />
                      删除
                    </Button>
                  </div>
                )
              })}
            </div>
            
            {/* 添加新发件人 */}
            <div className="grid gap-2" style={{ gridTemplateColumns: '1fr auto' }}>
              <div className="flex items-center gap-2 min-w-0">
                <Input
                  value={newSenderEmail}
                  onChange={(e) => setNewSenderEmail(e.target.value)}
                  placeholder="添加发件人邮箱 (例如: sender@example.com)"
                  onKeyPress={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault()
                      handleAddSender()
                    }
                  }}
              className="font-mono text-sm"
            />
              </div>
              <Button
                type="button"
                onClick={handleAddSender}
                size="sm"
                className="h-8"
              >
                <Plus className="w-4 h-4 mr-1" />
                添加
              </Button>
            </div>
            
            {senderEmails.length === 0 && (
              <p className="text-xs text-muted-foreground">
                💡 提示：配置多个发件人可以轮换使用，提高发送量和稳定性
              </p>
            )}
          </div>
        </>
      )}

      {formData.type === 'ses' && (
        <>
          <div className="space-y-2">
            <Label htmlFor="host">主机地址 *</Label>
            <Input
              id="host"
              value={formData.host}
              onChange={(e) => setFormData({ ...formData, host: e.target.value })}
              placeholder="email.us-east-1.amazonaws.com"
              required
            />
            <p className="text-xs text-muted-foreground">
              AWS SES API 端点地址，例如：email.us-east-1.amazonaws.com
            </p>
          </div>

          <div className="space-y-2">
            <Label htmlFor="username">Access Key ID *</Label>
            <Input
              id="username"
              value={formData.username}
              onChange={(e) => setFormData({ ...formData, username: e.target.value })}
              placeholder="AKIAIOSFODNN7EXAMPLE"
              required
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="password">Secret Access Key * {isEdit && '(留空则不修改)'}</Label>
            <Input
              id="password"
              type="password"
              value={formData.password}
              onChange={(e) => setFormData({ ...formData, password: e.target.value })}
              placeholder="wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
              required={!isEdit}
            />
          </div>

          <div className="space-y-2">
            <Label>
              发件人邮箱管理 *
              <span className="text-muted-foreground ml-2 text-xs font-normal">
                (必须是在 AWS SES 中已验证的邮箱)
              </span>
            </Label>
            
            {/* 发件人列表 */}
            <div className="space-y-2">
              {senderEmails.map((email, index) => {
                const isPaused = pausedSenders.some(s => s.email === email)
                const pausedInfo = pausedSenders.find(s => s.email === email)
                
                return (
                  <div key={index} className="grid gap-2" style={{ gridTemplateColumns: '1fr auto' }}>
                    <div className="flex items-center gap-2 min-w-0">
                      <Input
                        value={email}
                        readOnly
                        className={`font-mono text-sm ${isPaused ? 'bg-amber-50 border-amber-300' : 'bg-green-50 border-green-300'}`}
                      />
                      {isPaused && (
                        <span className="text-xs text-amber-600 whitespace-nowrap">
                          暂停中 ({Math.ceil((pausedInfo?.remaining_seconds || 0) / 60)}分钟)
                        </span>
                      )}
                    </div>
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => handleRemoveSender(email)}
                      title="删除此发件人"
                      className="h-8"
                    >
                      <Trash2 className="w-4 h-4 mr-1" />
                      删除
                    </Button>
                  </div>
                )
              })}
            </div>
            
            {/* 添加新发件人 */}
            <div className="flex gap-2">
              <Input
                value={newSenderEmail}
                onChange={(e) => setNewSenderEmail(e.target.value)}
                placeholder="添加发件人邮箱 (例如: sender@example.com)"
                onKeyPress={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault()
                    handleAddSender()
                  }
                }}
              className="font-mono text-sm"
              />
              <Button
                type="button"
                onClick={handleAddSender}
                size="sm"
              >
                <Plus className="w-4 h-4 mr-1" />
                添加
              </Button>
            </div>
            
            {senderEmails.length === 0 && (
              <p className="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded p-2">
                ⚠️ AWS SES 至少需要配置一个已验证的发件人邮箱
              </p>
            )}
            
            <p className="text-xs text-muted-foreground">
              💡 提示：必须是在 AWS SES 中已验证的邮箱地址或域名
            </p>
          </div>
        </>
      )}

      <div className="border-t pt-4">
        <h3 className="text-sm font-medium mb-3">速率限制</h3>
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor="rate_limit_second">每秒限制</Label>
            <Input
              id="rate_limit_second"
              type="number"
              value={formData.rate_limit_second}
              onChange={(e) => setFormData({ ...formData, rate_limit_second: e.target.value })}
              placeholder="例如：10"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="rate_limit_minute">每分钟限制</Label>
            <Input
              id="rate_limit_minute"
              type="number"
              value={formData.rate_limit_minute}
              onChange={(e) => setFormData({ ...formData, rate_limit_minute: e.target.value })}
              placeholder="例如：100"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="rate_limit_hour">每小时限制</Label>
            <Input
              id="rate_limit_hour"
              type="number"
              value={formData.rate_limit_hour}
              onChange={(e) => setFormData({ ...formData, rate_limit_hour: e.target.value })}
              placeholder="例如：1000"
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="rate_limit_day">每天限制</Label>
            <Input
              id="rate_limit_day"
              type="number"
              value={formData.rate_limit_day}
              onChange={(e) => setFormData({ ...formData, rate_limit_day: e.target.value })}
              placeholder="例如：10000"
            />
          </div>
        </div>
      </div>

      <div className="flex items-center space-x-2">
        <input
          type="checkbox"
          id="is_default"
          checked={formData.is_default}
          onChange={(e) => setFormData({ ...formData, is_default: e.target.checked })}
          className="rounded"
        />
        <Label htmlFor="is_default">设为默认服务器</Label>
      </div>

      <div className="flex justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          onClick={() => {
            isEdit ? setIsEditOpen(false) : setIsCreateOpen(false)
            resetForm()
          }}
        >
          取消
        </Button>
        <Button type="submit" disabled={createMutation.isPending || updateMutation.isPending}>
          {(createMutation.isPending || updateMutation.isPending)
            ? '保存中...'
            : isEdit
            ? '更新'
            : '创建'}
        </Button>
      </div>
    </form>
  )

  return (
    <div className="space-y-8">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl md:text-2xl font-bold">发送服务器</h1>
          <p className="text-muted-foreground mt-2">配置和管理邮件发送服务器</p>
        </div>
      </div>

      {/* SMTP 服务器配置 */}
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-xl font-semibold">服务器列表</h2>
          <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
            <DialogTrigger asChild>
              <Button>
                <Plus className="w-4 h-4 mr-2" />
                添加服务器
              </Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
              <DialogHeader>
                <DialogTitle>添加SMTP服务器</DialogTitle>
                <DialogDescription>配置邮件发送服务器</DialogDescription>
              </DialogHeader>
              {renderServerForm(false)}
            </DialogContent>
          </Dialog>
        </div>

        {isLoading ? (
          <div className="flex items-center justify-center h-32">
            <p className="text-muted-foreground">加载中...</p>
          </div>
        ) : servers && servers.length === 0 ? (
          <Card>
            <CardContent className="flex flex-col items-center justify-center py-12">
              <Server className="w-12 h-12 text-muted-foreground mb-4" />
              <p className="text-lg font-medium mb-2">还没有配置SMTP服务器</p>
              <p className="text-muted-foreground mb-4">添加邮件发送服务器以开始发送邮件</p>
              <Button onClick={handleCreate}>
                <Plus className="w-4 h-4 mr-2" />
                添加服务器
              </Button>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-4">
            {servers?.map((server: SmtpServer) => (
              <Card key={server.id} className="hover:shadow-md transition-shadow">
                <CardHeader className="pb-4">
                  <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <div className="flex items-center gap-3">
                      <div className={`w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 ${
                        server.is_active ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400'
                      }`}>
                        <Server className="w-5 h-5" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                          <CardTitle className="text-base md:text-lg">{server.name}</CardTitle>
                          {server.is_default && (
                            <span className="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs font-medium rounded whitespace-nowrap">
                              默认
                            </span>
                          )}
                          {server.is_active ? (
                            <span className="px-2 py-0.5 bg-green-100 text-green-700 text-xs font-medium rounded whitespace-nowrap">
                              已启用
                            </span>
                          ) : (
                            <span className="px-2 py-0.5 bg-gray-100 text-gray-600 text-xs font-medium rounded whitespace-nowrap">
                              已禁用
                            </span>
                          )}
                        </div>
                        <CardDescription className="mt-1 text-xs md:text-sm">
                          {serverTypes.find((t) => t.value === server.type)?.label}
                          {server.type === 'smtp' && ` • ${server.host}:${server.port}`}
                        </CardDescription>
                      </div>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap">
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handleEdit(server)}
                        className="text-xs md:text-sm"
                      >
                        <Edit className="w-3 h-3 md:w-4 md:h-4 mr-1" />
                        编辑
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => toggleMutation.mutate({ 
                          id: server.id, 
                          is_active: !server.is_active 
                        })}
                        disabled={toggleMutation.isPending}
                        className="text-xs md:text-sm"
                      >
                        {server.is_active ? (
                          <>
                            <X className="w-3 h-3 md:w-4 md:h-4 mr-1" />
                            禁用
                          </>
                        ) : (
                          <>
                            <Check className="w-3 h-3 md:w-4 md:h-4 mr-1" />
                            启用
                          </>
                        )}
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => testMutation.mutate(server.id)}
                        disabled={testMutation.isPending}
                        className="text-xs md:text-sm"
                      >
                        <Zap className="w-3 h-3 md:w-4 md:h-4 mr-1" />
                        测试
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => duplicateMutation.mutate(server.id)}
                        disabled={duplicateMutation.isPending}
                        className="text-xs md:text-sm"
                        title="复制服务器"
                      >
                        <Copy className="w-3 h-3 md:w-4 md:h-4 mr-1" />
                        复制
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={async () => {
                          const confirmed = await confirm({
                            title: '删除SMTP服务器',
                            description: `确定要删除服务器"${server.name}"吗？`,
                            confirmText: '删除',
                            cancelText: '取消',
                            variant: 'destructive',
                          })
                          if (confirmed) {
                            deleteMutation.mutate(server.id)
                          }
                        }}
                        disabled={deleteMutation.isPending || server.is_default}
                        className="text-xs md:text-sm text-red-600 hover:text-red-700 hover:bg-red-50"
                        title={server.is_default ? '默认服务器不能删除' : '删除服务器'}
                      >
                        <Trash2 className="w-3 h-3 md:w-4 md:h-4" />
                      </Button>
                    </div>
                  </div>
                </CardHeader>
                <CardContent>
                  {/* 速率限制 */}
                  <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div className="p-2 md:p-3 bg-blue-50 rounded-lg">
                      <div className="flex items-center justify-between mb-1 md:mb-2">
                        <span className="text-xs text-muted-foreground">每秒</span>
                        {server.rate_limit_second && (
                          <span className="text-xs font-medium text-blue-600">
                            {server.rate_limit_status?.second?.current || 0}/{server.rate_limit_second}
                          </span>
                        )}
                      </div>
                      <div className="text-lg md:text-2xl font-bold text-blue-600">
                        {server.rate_limit_second || '∞'}
                      </div>
                      {server.rate_limit_second && (
                        <div className="mt-2">
                          <div className="w-full bg-blue-200 rounded-full h-1.5">
                            <div
                              className="bg-blue-600 h-1.5 rounded-full transition-all"
                              style={{ width: '0%' }}
                            />
                          </div>
                        </div>
                      )}
                    </div>
                    <div className="p-2 md:p-3 bg-green-50 rounded-lg">
                      <div className="flex items-center justify-between mb-1 md:mb-2">
                        <span className="text-xs text-muted-foreground">每分钟</span>
                        {server.rate_limit_minute && (
                          <span className="text-xs font-medium text-green-600">
                            {server.rate_limit_status?.minute?.current || 0}/{server.rate_limit_minute}
                          </span>
                        )}
                      </div>
                      <div className="text-lg md:text-2xl font-bold text-green-600">
                        {server.rate_limit_minute || '∞'}
                      </div>
                      {server.rate_limit_minute && (
                        <div className="mt-2">
                          <div className="w-full bg-green-200 rounded-full h-1.5">
                            <div
                              className="bg-green-600 h-1.5 rounded-full transition-all"
                              style={{ width: '0%' }}
                            />
                          </div>
                        </div>
                      )}
                    </div>
                    <div className="p-2 md:p-3 bg-purple-50 rounded-lg">
                      <div className="flex items-center justify-between mb-1 md:mb-2">
                        <span className="text-xs text-muted-foreground">每小时</span>
                        {server.rate_limit_hour && (
                          <span className="text-xs font-medium text-purple-600">
                            {server.rate_limit_status?.hour?.current || 0}/{server.rate_limit_hour}
                          </span>
                        )}
                      </div>
                      <div className="text-lg md:text-2xl font-bold text-purple-600">
                        {server.rate_limit_hour || '∞'}
                      </div>
                      {server.rate_limit_hour && (
                        <div className="mt-2">
                          <div className="w-full bg-purple-200 rounded-full h-1.5">
                            <div
                              className="bg-purple-600 h-1.5 rounded-full transition-all"
                              style={{ width: '0%' }}
                            />
                          </div>
                        </div>
                      )}
                    </div>
                    <div className="p-2 md:p-3 bg-orange-50 rounded-lg">
                      <div className="flex items-center justify-between mb-1 md:mb-2">
                        <span className="text-xs text-muted-foreground">每天</span>
                        {server.rate_limit_day && (
                          <span className="text-xs font-medium text-orange-600">
                            {server.rate_limit_status?.day?.current || 0}/{server.rate_limit_day}
                          </span>
                        )}
                      </div>
                      <div className="text-lg md:text-2xl font-bold text-orange-600">
                        {server.rate_limit_day ? `${server.rate_limit_day - server.emails_sent_today}` : '∞'}
                      </div>
                      {server.rate_limit_day ? (
                        <>
                          <div className="text-xs text-muted-foreground mt-0.5 md:mt-1">剩余</div>
                          <div className="mt-2">
                            <div className="w-full bg-orange-200 rounded-full h-1.5">
                              <div
                                className="bg-orange-600 h-1.5 rounded-full transition-all"
                                style={{ 
                                  width: `${Math.min((server.emails_sent_today / server.rate_limit_day) * 100, 100)}%` 
                                }}
                              />
                            </div>
                          </div>
                        </>
                      ) : (
                        <div className="text-xs text-muted-foreground mt-0.5">无限制</div>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {/* 编辑对话框 */}
        <Dialog open={isEditOpen} onOpenChange={setIsEditOpen}>
          <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
            <DialogHeader>
              <DialogTitle>编辑SMTP服务器</DialogTitle>
              <DialogDescription>修改邮件服务器配置</DialogDescription>
            </DialogHeader>
            {renderServerForm(true)}
          </DialogContent>
        </Dialog>

        {/* 确认对话框 */}
        <ConfirmDialog />
      </div>
    </div>
  )
}
