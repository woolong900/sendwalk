import { useState, useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Eye, Save } from 'lucide-react'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
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
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

interface Category {
  value: string
  label: string
}

export default function TemplateEditorPage() {
  const navigate = useNavigate()
  const { id } = useParams()
  const queryClient = useQueryClient()
  const isEditing = !!id

  const [name, setName] = useState('')
  const [category, setCategory] = useState('general')
  const [description, setDescription] = useState('')
  const [htmlContent, setHtmlContent] = useState('')
  const [isPreviewOpen, setIsPreviewOpen] = useState(false)
  const [previewHtml, setPreviewHtml] = useState('')

  // 获取分类列表
  const { data: categoriesData } = useQuery({
    queryKey: ['template-categories'],
    queryFn: async () => {
      const response = await api.get('/templates/categories')
      return response.data.data as Category[]
    },
  })

  // 获取模板详情（编辑模式）
  const { data: templateData, isLoading } = useQuery({
    queryKey: ['template', id],
    queryFn: async () => {
      if (!id) return null
      const response = await api.get(`/templates/${id}`)
      return response.data.data
    },
    enabled: isEditing,
  })

  // 加载模板数据
  useEffect(() => {
    if (templateData) {
      setName(templateData.name)
      setCategory(templateData.category)
      setDescription(templateData.description || '')
      setHtmlContent(templateData.html_content)
    }
  }, [templateData])

  // 保存模板
  const saveMutation = useMutation({
    mutationFn: async () => {
      const data = {
        name,
        category,
        description,
        html_content: htmlContent,
      }

      if (isEditing) {
        return await api.put(`/templates/${id}`, data)
      } else {
        return await api.post('/templates', data)
      }
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['templates'] })
      toast.success(isEditing ? '模板更新成功' : '模板创建成功')
      navigate('/templates')
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || '保存失败')
    },
  })

  // 预览模板
  const handlePreview = async () => {
    // 简单的预览，替换基本变量
    let preview = htmlContent
    const previewData: Record<string, string> = {
      '{email}': 'subscriber@example.com',
      '{first_name}': '张',
      '{last_name}': '三',
      '{full_name}': '张三',
      '{campaign_id}': '123',
      '{date}': new Date().toLocaleDateString('zh-CN').replace(/\//g, ''),
      '{list_name}': '示例列表',
      '{server_name}': '示例服务器',
      '{sender_domain}': 'example.com',
      '{unsubscribe_url}': '#',
    }

    Object.entries(previewData).forEach(([key, value]) => {
      preview = preview.replace(new RegExp(key, 'g'), value)
    })

    setPreviewHtml(preview)
    setIsPreviewOpen(true)
  }

  const handleSave = () => {
    if (!name) {
      toast.error('请输入模板名称')
      return
    }
    if (!htmlContent) {
      toast.error('请输入模板内容')
      return
    }
    saveMutation.mutate()
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <p>加载中...</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => navigate('/templates')}
          >
            <ArrowLeft className="w-4 h-4" />
          </Button>
          <div>
            <h1 className="text-xl md:text-2xl font-bold">
              {isEditing ? '编辑模板' : '创建模板'}
            </h1>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handlePreview}>
            <Eye className="w-4 h-4 mr-2" />
            预览
          </Button>
          <Button onClick={handleSave} disabled={saveMutation.isPending}>
            <Save className="w-4 h-4 mr-2" />
            {saveMutation.isPending ? '保存中...' : '保存'}
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* 左侧：基本信息 */}
        <div className="lg:col-span-1">
          <Card>
            <CardHeader>
              <CardTitle>基本信息</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="name">模板名称 *</Label>
                <Input
                  id="name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="例如：欢迎邮件模板"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="category">分类 *</Label>
                <Select value={category} onValueChange={setCategory}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {categoriesData?.map((cat: Category) => (
                      <SelectItem key={cat.value} value={cat.value}>
                        {cat.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="description">描述</Label>
                <Textarea
                  id="description"
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="模板用途说明..."
                  rows={3}
                />
              </div>

              <div className="pt-4 border-t">
                <h4 className="text-sm font-medium mb-3">可用变量</h4>
                <div className="space-y-3">
                  <div>
                    <p className="text-xs font-semibold text-muted-foreground mb-2">订阅者信息</p>
                    <div className="space-y-1 text-xs text-muted-foreground pl-2">
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{email}'}</code> - 邮箱地址</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{first_name}'}</code> - 名</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{last_name}'}</code> - 姓</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{full_name}'}</code> - 全名</p>
                    </div>
                  </div>
                  
                  <div>
                    <p className="text-xs font-semibold text-muted-foreground mb-2">系统变量</p>
                    <div className="space-y-1 text-xs text-muted-foreground pl-2">
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{campaign_id}'}</code> - 活动ID</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{date}'}</code> - 日期(mmdd格式)</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{list_name}'}</code> - 列表名称</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{server_name}'}</code> - 服务器名称</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{sender_domain}'}</code> - 发件域名</p>
                      <p><code className="bg-muted px-1.5 py-0.5 rounded">{'{unsubscribe_url}'}</code> - 退订链接</p>
                    </div>
                  </div>
                  
                  <div>
                    <p className="text-xs font-semibold text-muted-foreground mb-2">自定义标签</p>
                    <div className="space-y-1 text-xs text-muted-foreground pl-2">
                      <p className="italic">在"自定义标签"页面创建标签后</p>
                      <p>使用 <code className="bg-muted px-1.5 py-0.5 rounded">{'{标签名}'}</code> 引用</p>
                      <p className="text-[10px] text-muted-foreground/70 mt-1">
                        例如：{'{utm_source}'}, {'{tracking_id}'}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* 右侧：HTML 编辑器 */}
        <div className="lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>HTML 内容</CardTitle>
            </CardHeader>
            <CardContent>
              <Textarea
                value={htmlContent}
                onChange={(e) => setHtmlContent(e.target.value)}
                placeholder="输入HTML邮件内容..."
                className="font-mono text-sm min-h-[600px]"
              />
            </CardContent>
          </Card>
        </div>
      </div>

      {/* Preview Dialog */}
      <Dialog open={isPreviewOpen} onOpenChange={setIsPreviewOpen}>
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>模板预览</DialogTitle>
          </DialogHeader>
          <div className="border rounded-lg p-4 bg-white">
            <div dangerouslySetInnerHTML={{ __html: previewHtml }} />
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

