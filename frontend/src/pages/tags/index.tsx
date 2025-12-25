import { useState } from 'react'
import { Skeleton } from '@/components/ui/skeleton'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Tag as TagIcon, Edit, Trash2, Zap, Copy } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Card,
  CardContent,
} from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { api } from '@/lib/api'
import { useConfirm } from '@/hooks/use-confirm'

interface Tag {
  id: number
  name: string
  values: string
  placeholder: string
  values_count: number
  created_at: string
}

interface TagsResponse {
  data: Tag[]
  reserved_tags: string[]
}

export default function TagsPage() {
  const { confirm, ConfirmDialog } = useConfirm()
  
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const [isEditOpen, setIsEditOpen] = useState(false)
  const [editingTag, setEditingTag] = useState<Tag | null>(null)
  const [formData, setFormData] = useState({
    name: '',
    values: '',
  })

  const queryClient = useQueryClient()

  // 获取标签列表和系统保留标签
  const { data: tagsData, isLoading } = useQuery<TagsResponse>({
    queryKey: ['tags'],
    queryFn: async () => {
      const response = await api.get('/tags')
      return response.data
    },
  })

  const tags = tagsData?.data || []
  const reservedTags = tagsData?.reserved_tags || []

  // 创建标签
  const createMutation = useMutation({
    mutationFn: async (data: typeof formData) => {
      return api.post('/tags', data)
    },
    onSuccess: async () => {
      // 立即重新获取数据以确保显示最新内容
      await queryClient.invalidateQueries({ queryKey: ['tags'] })
      await queryClient.refetchQueries({ queryKey: ['tags'] })
      toast.success('标签创建成功')
      setIsCreateOpen(false)
      resetForm()
    },
    // onError 已由全局拦截器处理
  })

  // 更新标签
  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: typeof formData }) => {
      return api.put(`/tags/${id}`, data)
    },
    onSuccess: async () => {
      // 立即重新获取数据以确保显示最新内容
      await queryClient.invalidateQueries({ queryKey: ['tags'] })
      await queryClient.refetchQueries({ queryKey: ['tags'] })
      toast.success('标签更新成功')
      setIsEditOpen(false)
      setEditingTag(null)
      resetForm()
    },
    // onError 已由全局拦截器处理
  })

  // 删除标签
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/tags/${id}`)
    },
    onSuccess: async () => {
      // 立即重新获取数据以确保显示最新内容
      await queryClient.invalidateQueries({ queryKey: ['tags'] })
      await queryClient.refetchQueries({ queryKey: ['tags'] })
      toast.success('标签删除成功')
    },
    // onError 已由全局拦截器处理
  })

  // 测试标签
  const testMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.post(`/tags/${id}/test`)
    },
    onSuccess: (response) => {
      toast.success(`随机值: ${response.data.random_value}`, {
        duration: 3000,
      })
    },
    // onError 已由全局拦截器处理
  })

  const resetForm = () => {
    setFormData({
      name: '',
      values: '',
    })
  }

  const handleCreate = () => {
    resetForm()
    setIsCreateOpen(true)
  }

  const handleEdit = (tag: Tag) => {
    setEditingTag(tag)
    setFormData({
      name: tag.name,
      values: tag.values,
    })
    setIsEditOpen(true)
  }

  const handleDelete = async (tag: Tag) => {
    const confirmed = await confirm({
      title: '删除标签',
      description: `确定要删除标签"${tag.name}"吗？`,
      confirmText: '删除',
      cancelText: '取消',
      variant: 'destructive',
    })
    if (confirmed) {
      deleteMutation.mutate(tag.id)
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (editingTag) {
      updateMutation.mutate({ id: editingTag.id, data: formData })
    } else {
      createMutation.mutate(formData)
    }
  }

  const copyPlaceholder = (placeholder: string) => {
    navigator.clipboard.writeText(placeholder)
    toast.success('已复制到剪贴板')
  }

  return (
    <div className="space-y-6">
      {/* 页头 */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl md:text-2xl font-bold tracking-tight">自定义标签</h1>
          <p className="text-muted-foreground mt-2">
            创建和管理邮件模板中的自定义变量
          </p>
        </div>
        <Button onClick={handleCreate}>
          <Plus className="w-4 h-4 mr-2" />
          创建标签
        </Button>
      </div>

      {/* 使用说明 */}
      <Card className="bg-blue-50 border-blue-200">
        <CardContent className="pt-6">
          <div className="flex gap-4">
            <div className="flex-shrink-0">
              <div className="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                <TagIcon className="w-5 h-5 text-blue-600" />
              </div>
            </div>
            <div className="space-y-2">
              <h3 className="font-semibold text-blue-900">如何使用自定义标签？</h3>
              <ul className="text-sm text-blue-800 space-y-1">
                <li>• 在活动的主题或内容中使用 <code className="bg-blue-100 px-1.5 py-0.5 rounded">{'{标签名}'}</code></li>
                <li>• 发送时会自动替换为标签的值</li>
                <li>• 如果标签有多个值（多行），系统会随机选择一个</li>
                <li>• 例如：<code className="bg-blue-100 px-1.5 py-0.5 rounded">{'{company_name}'}</code> 会替换为具体的公司名称</li>
              </ul>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* 标签列表 */}
      {isLoading || !tags ? (
        // 加载中显示骨架屏
        <Card>
          <div className="overflow-x-auto">
            <Table className="min-w-[900px]">
              <colgroup>
                <col className="w-[50px]" />
                <col className="w-[180px]" />
                <col className="w-[200px]" />
                <col className="w-[250px]" />
                <col className="w-[150px]" />
                <col className="w-[120px]" />
              </colgroup>
              <TableHeader>
              <TableRow>
                <TableHead>ID</TableHead>
                <TableHead>标签名称</TableHead>
                <TableHead>占位符</TableHead>
                <TableHead>值</TableHead>
                <TableHead>创建时间</TableHead>
                <TableHead className="text-right">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {[...Array(5)].map((_, i) => (
                <TableRow key={i}>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-12" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-32" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-24" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-40" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-32" /></TableCell>
                  <TableCell className="whitespace-nowrap"><Skeleton className="h-8 w-20 ml-auto" /></TableCell>
                </TableRow>
              ))}
            </TableBody>
            </Table>
          </div>
        </Card>
      ) : tags.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <TagIcon className="w-12 h-12 text-muted-foreground mb-4" />
            <p className="text-lg font-medium mb-2">还没有自定义标签</p>
            <p className="text-muted-foreground mb-4">创建您的第一个标签</p>
            <Button onClick={handleCreate}>
              <Plus className="w-4 h-4 mr-2" />
              创建标签
            </Button>
          </CardContent>
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <Table className="min-w-[720px]">
              <colgroup>
                <col className="w-[200px]" />
                <col className="w-[250px]" />
                <col className="w-[120px]" />
                <col className="w-[150px]" />
              </colgroup>
              <TableHeader>
              <TableRow>
                <TableHead>标签名称</TableHead>
                <TableHead>占位符</TableHead>
                <TableHead className="text-center">值数量</TableHead>
                <TableHead className="text-right">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {tags?.map((tag) => (
                <TableRow key={tag.id}>
                  <TableCell className="whitespace-nowrap">
                    <code className="px-2 py-1 bg-slate-100 rounded text-sm font-mono">
                      {tag.name}
                    </code>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <div className="flex items-center gap-2">
                      <code className="px-2 py-1 bg-purple-100 text-purple-700 rounded text-sm font-mono truncate">
                        {tag.placeholder}
                      </code>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => copyPlaceholder(tag.placeholder)}
                        title="复制"
                        className="flex-shrink-0"
                      >
                        <Copy className="w-3 h-3" />
                      </Button>
                    </div>
                  </TableCell>
                  <TableCell className="text-center whitespace-nowrap">
                    <Badge variant={tag.values_count > 1 ? 'default' : 'secondary'}>
                      {tag.values_count} 个值
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right whitespace-nowrap">
                    <div className="flex items-center justify-end gap-1">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => testMutation.mutate(tag.id)}
                        disabled={testMutation.isPending}
                        title="测试随机值"
                      >
                        <Zap className="w-4 h-4" />
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => handleEdit(tag)}
                        title="编辑"
                      >
                        <Edit className="w-4 h-4" />
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => handleDelete(tag)}
                        disabled={deleteMutation.isPending}
                        title="删除"
                      >
                        <Trash2 className="w-4 h-4 text-red-500" />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
            </Table>
          </div>
        </Card>
      )}

      {/* 创建对话框 */}
      <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>创建自定义标签</DialogTitle>
            <DialogDescription>
              创建一个可在邮件模板中使用的自定义变量
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="name">
                标签名称 <span className="text-red-500">*</span>
              </Label>
              <Input
                id="name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="company_name"
                required
              />
              <p className="text-xs text-muted-foreground mt-1.5">
                只能包含字母、数字和下划线，将作为 <code className="bg-slate-100 px-1 rounded">{'{标签名称}'}</code> 在邮件中使用
              </p>
              {reservedTags.length > 0 && (
                <div className="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded p-2 mt-2">
                  <p className="font-medium mb-1">⚠️ 以下标签名称为系统保留，不能使用：</p>
                  <div className="flex flex-wrap gap-1">
                    {reservedTags.map((tag) => (
                      <code key={tag} className="bg-amber-100 px-1.5 py-0.5 rounded text-amber-800">
                        {tag}
                      </code>
                    ))}
                  </div>
                </div>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="values">
                标签值 <span className="text-red-500">*</span>
              </Label>
              <Textarea
                id="values"
                value={formData.values}
                onChange={(e) => setFormData({ ...formData, values: e.target.value })}
                placeholder="每行一个值，如：&#10;阿里巴巴&#10;腾讯&#10;字节跳动"
                rows={8}
                required
              />
              <p className="text-xs text-muted-foreground mt-1.5">
                每行一个值。如果有多个值，发送时会随机选择一个
              </p>
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsCreateOpen(false)}
              >
                取消
              </Button>
              <Button type="submit" disabled={createMutation.isPending}>
                创建
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* 编辑对话框 */}
      <Dialog open={isEditOpen} onOpenChange={setIsEditOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>编辑自定义标签</DialogTitle>
            <DialogDescription>
              修改标签的名称或值
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="edit-name">
                标签名称 <span className="text-red-500">*</span>
              </Label>
              <Input
                id="edit-name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                required
              />
              <p className="text-xs text-muted-foreground mt-1.5">
                只能包含字母、数字和下划线
              </p>
              {reservedTags.length > 0 && (
                <div className="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded p-2 mt-2">
                  <p className="font-medium mb-1">⚠️ 以下标签名称为系统保留，不能使用：</p>
                  <div className="flex flex-wrap gap-1">
                    {reservedTags.map((tag) => (
                      <code key={tag} className="bg-amber-100 px-1.5 py-0.5 rounded text-amber-800">
                        {tag}
                      </code>
                    ))}
                  </div>
                </div>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="edit-values">
                标签值 <span className="text-red-500">*</span>
              </Label>
              <Textarea
                id="edit-values"
                value={formData.values}
                onChange={(e) => setFormData({ ...formData, values: e.target.value })}
                rows={8}
                required
              />
              <p className="text-xs text-muted-foreground mt-1.5">
                每行一个值。如果有多个值，发送时会随机选择一个
              </p>
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setIsEditOpen(false)
                  setEditingTag(null)
                }}
              >
                取消
              </Button>
              <Button type="submit" disabled={updateMutation.isPending}>
                保存
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* 确认对话框 */}
      <ConfirmDialog />
    </div>
  )
}
