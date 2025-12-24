import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, FileText, Search, Copy, Trash2, Eye, Edit, Filter } from 'lucide-react'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
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
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { useConfirm } from '@/hooks/use-confirm'

interface Template {
  id: number
  name: string
  category: string
  description: string | null
  html_content: string
  is_default: boolean
  is_active: boolean
  usage_count: number
  last_used_at: string | null
  created_at: string
  updated_at: string
}

interface Category {
  value: string
  label: string
}

export default function TemplatesPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { confirm, ConfirmDialog } = useConfirm()

  const [searchTerm, setSearchTerm] = useState('')
  const [categoryFilter, setCategoryFilter] = useState<string>('all')
  const [previewTemplate, setPreviewTemplate] = useState<Template | null>(null)
  const [previewHtml, setPreviewHtml] = useState<string>('')

  // 获取分类列表
  const { data: categoriesData } = useQuery({
    queryKey: ['template-categories'],
    queryFn: async () => {
      const response = await api.get('/templates/categories')
      return response.data.data as Category[]
    },
  })

  // 获取模板列表
  const { data: templatesData, isLoading } = useQuery({
    queryKey: ['templates', searchTerm, categoryFilter],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchTerm) params.append('search', searchTerm)
      if (categoryFilter && categoryFilter !== 'all') params.append('category', categoryFilter)
      
      const response = await api.get(`/templates?${params.toString()}`)
      return response.data
    },
  })

  const templates = templatesData?.data || []

  // 删除模板
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/templates/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['templates'] })
      toast.success('模板删除成功')
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || '删除失败')
    },
  })

  // 复制模板
  const duplicateMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post(`/templates/${id}/duplicate`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['templates'] })
      toast.success('模板复制成功')
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || '复制失败')
    },
  })

  // 预览模板
  const previewMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.get(`/templates/${id}/preview`)
      return response.data.data
    },
    onSuccess: (data, templateId) => {
      const template = templates.find((t: Template) => t.id === templateId)
      if (template) {
        setPreviewTemplate(template)
        setPreviewHtml(data.html)
      }
    },
  })

  const handleDelete = async (template: Template) => {
    if (template.is_default) {
      toast.error('系统默认模板不能删除')
      return
    }

    const confirmed = await confirm({
      title: '删除模板',
      description: `确定要删除模板"${template.name}"吗？此操作不可恢复。`,
      confirmText: '删除',
      cancelText: '取消',
      variant: 'destructive',
    })

    if (confirmed) {
      deleteMutation.mutate(template.id)
    }
  }

  const handleDuplicate = (template: Template) => {
    duplicateMutation.mutate(template.id)
  }

  const handlePreview = (template: Template) => {
    previewMutation.mutate(template.id)
  }

  const handleEdit = (template: Template) => {
    if (template.is_default) {
      // 系统默认模板，先复制再编辑
      duplicateMutation.mutate(template.id, {
        onSuccess: (data) => {
          navigate(`/templates/${data.data.id}/edit`)
        },
      })
    } else {
      navigate(`/templates/${template.id}/edit`)
    }
  }

  const getCategoryLabel = (category: string) => {
    const cat = categoriesData?.find((c: Category) => c.value === category)
    return cat?.label || category
  }

  const getCategoryColor = (category: string) => {
    const colors: Record<string, string> = {
      general: 'bg-gray-100 text-gray-700',
      marketing: 'bg-purple-100 text-purple-700',
      transactional: 'bg-blue-100 text-blue-700',
      newsletter: 'bg-green-100 text-green-700',
      welcome: 'bg-yellow-100 text-yellow-700',
      announcement: 'bg-red-100 text-red-700',
    }
    return colors[category] || 'bg-gray-100 text-gray-700'
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
          <h1 className="text-xl md:text-2xl font-bold">邮件模板</h1>
          <p className="text-muted-foreground mt-2">
            管理您的邮件模板，快速创建专业的邮件活动
          </p>
        </div>
        <Button onClick={() => navigate('/templates/create')}>
          <Plus className="w-4 h-4 mr-2" />
          创建模板
        </Button>
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex flex-col md:flex-row gap-4">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                <Input
                  placeholder="搜索模板名称或描述..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="pl-10"
                />
              </div>
            </div>
            <div className="w-full md:w-48">
              <Select value={categoryFilter} onValueChange={setCategoryFilter}>
                <SelectTrigger>
                  <Filter className="w-4 h-4 mr-2" />
                  <SelectValue placeholder="选择分类" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">全部分类</SelectItem>
                  {categoriesData?.map((category: Category) => (
                    <SelectItem key={category.value} value={category.value}>
                      {category.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Templates Grid */}
      {isLoading ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {[...Array(6)].map((_, i) => (
            <Card key={i}>
              <CardHeader>
                <Skeleton className="h-6 w-3/4" />
                <Skeleton className="h-4 w-1/2 mt-2" />
              </CardHeader>
              <CardContent>
                <Skeleton className="h-20 w-full" />
              </CardContent>
            </Card>
          ))}
        </div>
      ) : templates.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <FileText className="w-12 h-12 text-muted-foreground mb-4" />
            <p className="text-lg font-medium mb-2">还没有模板</p>
            <p className="text-muted-foreground mb-4">
              创建您的第一个邮件模板
            </p>
            <Button onClick={() => navigate('/templates/create')}>
              <Plus className="w-4 h-4 mr-2" />
              创建模板
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {templates.map((template: Template) => (
            <Card key={template.id} className="hover:shadow-lg transition-shadow">
              <CardHeader>
                <div className="flex items-start justify-between">
                  <div className="flex-1">
                    <CardTitle className="text-lg flex items-center gap-2">
                      {template.name}
                      {template.is_default && (
                        <Badge variant="secondary" className="text-xs">
                          系统
                        </Badge>
                      )}
                    </CardTitle>
                    <CardDescription className="mt-2">
                      <Badge className={getCategoryColor(template.category)}>
                        {getCategoryLabel(template.category)}
                      </Badge>
                    </CardDescription>
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-muted-foreground line-clamp-2 mb-4">
                  {template.description || '暂无描述'}
                </p>
                
                <div className="flex items-center justify-between text-xs text-muted-foreground mb-4">
                  <span>使用 {template.usage_count} 次</span>
                  {template.last_used_at && (
                    <span>
                      最后使用: {new Date(template.last_used_at).toLocaleDateString()}
                    </span>
                  )}
                </div>

                <div className="flex gap-2 flex-wrap">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handlePreview(template)}
                    disabled={previewMutation.isPending}
                  >
                    <Eye className="w-3 h-3 mr-1" />
                    预览
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleEdit(template)}
                  >
                    <Edit className="w-3 h-3 mr-1" />
                    {template.is_default ? '复制编辑' : '编辑'}
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleDuplicate(template)}
                    disabled={duplicateMutation.isPending}
                  >
                    <Copy className="w-3 h-3 mr-1" />
                    复制
                  </Button>
                  {!template.is_default && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handleDelete(template)}
                      disabled={deleteMutation.isPending}
                      className="text-red-600 hover:text-red-700 hover:bg-red-50"
                    >
                      <Trash2 className="w-3 h-3" />
                    </Button>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Preview Dialog */}
      <Dialog open={!!previewTemplate} onOpenChange={() => setPreviewTemplate(null)}>
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{previewTemplate?.name}</DialogTitle>
            <DialogDescription>
              {previewTemplate?.description}
            </DialogDescription>
          </DialogHeader>
          <div className="border rounded-lg p-4 bg-white">
            <div dangerouslySetInnerHTML={{ __html: previewHtml }} />
          </div>
        </DialogContent>
      </Dialog>

      <ConfirmDialog />
    </div>
  )
}

