import { useState } from 'react'
import { Skeleton } from '@/components/ui/skeleton'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, Edit, Trash2, Users, Search, ListFilter, Clock } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { api } from '@/lib/api'
import { formatDateTime } from '@/lib/utils'
import { useConfirm } from '@/hooks/use-confirm'

interface MailingList {
  id: number
  name: string
  description: string
  subscribers_count: number
  unsubscribed_count: number
  created_at: string
  updated_at: string
}

export default function ListsPage() {
  const { confirm, ConfirmDialog } = useConfirm()
  
  const navigate = useNavigate()
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const [isEditOpen, setIsEditOpen] = useState(false)
  const [editingList, setEditingList] = useState<MailingList | null>(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [formData, setFormData] = useState({
    name: '',
    description: '',
  })

  const queryClient = useQueryClient()

  // 获取列表
  const { data: lists, isLoading } = useQuery<MailingList[]>({
    queryKey: ['lists'],
    queryFn: async () => {
      const response = await api.get('/lists')
      return response.data.data
    },
  })

  // 创建列表
  const createMutation = useMutation({
    mutationFn: async (data: typeof formData) => {
      return api.post('/lists', data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lists'] })
      toast.success('列表创建成功')
      setIsCreateOpen(false)
      resetForm()
    },
    // onError 已由全局拦截器处理
  })

  // 更新列表
  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: typeof formData }) => {
      return api.put(`/lists/${id}`, data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lists'] })
      toast.success('列表更新成功')
      setIsEditOpen(false)
      setEditingList(null)
      resetForm()
    },
    // onError 已由全局拦截器处理
  })

  // 删除列表
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/lists/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lists'] })
      toast.success('列表删除成功')
    },
    // onError 已由全局拦截器处理
  })

  const resetForm = () => {
    setFormData({ name: '', description: '' })
  }

  const handleCreate = () => {
    resetForm()
    setIsCreateOpen(true)
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    createMutation.mutate(formData)
  }

  const handleEdit = (list: MailingList) => {
    setEditingList(list)
    setFormData({
      name: list.name,
      description: list.description || '',
    })
    setIsEditOpen(true)
  }

  const handleUpdate = (e: React.FormEvent) => {
    e.preventDefault()
    if (editingList) {
      updateMutation.mutate({ id: editingList.id, data: formData })
    }
  }

  const handleDelete = async (list: MailingList) => {
    const confirmed = await confirm({
      title: '删除邮件列表',
      description: `确定要删除列表"${list.name}"吗？\n\n注意：删除后订阅者不会被删除，只会解除与该列表的关联。`,
      confirmText: '删除',
      cancelText: '取消',
      variant: 'destructive',
    })
    if (confirmed) {
      deleteMutation.mutate(list.id)
    }
  }

  // 搜索过滤
  const filteredLists = lists?.filter((list) =>
    list.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
    list.description?.toLowerCase().includes(searchTerm.toLowerCase())
  )

  // 统计数据
  const stats = {
    totalLists: lists?.length || 0,
    totalSubscribers: lists?.reduce((sum, list) => sum + (list.subscribers_count || 0), 0) || 0,
    totalUnsubscribed: lists?.reduce((sum, list) => sum + (list.unsubscribed_count || 0), 0) || 0,
  }

  return (
    <div className="space-y-6">
      {/* 页头 */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">邮件列表</h1>
          <p className="text-muted-foreground mt-2">管理您的订阅者列表</p>
        </div>
        <Button onClick={handleCreate}>
          <Plus className="w-4 h-4 mr-2" />
          创建列表
        </Button>
      </div>

      {/* 统计卡片 */}
      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">总列表数</CardTitle>
            <ListFilter className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats.totalLists}</div>
            <p className="text-xs text-muted-foreground mt-1">
              已创建的邮件列表
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">总订阅者</CardTitle>
            <Users className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats.totalSubscribers}</div>
            <p className="text-xs text-muted-foreground mt-1">
              所有列表的订阅者总数
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">总取消订阅</CardTitle>
            <Badge variant="secondary" className="text-xs bg-orange-100 text-orange-700">
              {stats.totalUnsubscribed}
            </Badge>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-orange-600">{stats.totalUnsubscribed}</div>
            <p className="text-xs text-muted-foreground mt-1">
              从所有列表取消订阅的总数
            </p>
          </CardContent>
        </Card>
      </div>

      {/* 搜索栏 */}
      {lists && lists.length > 0 && (
        <div className="flex items-center gap-4">
          <div className="relative flex-1 max-w-sm">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="搜索列表名称或描述..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="pl-9"
            />
          </div>
          {searchTerm && (
            <p className="text-sm text-muted-foreground">
              找到 {filteredLists?.length || 0} 个结果
            </p>
          )}
        </div>
      )}

      {/* 列表表格 */}
      {isLoading || !lists ? (
        // 加载中显示骨架屏
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-[50px]">ID</TableHead>
                <TableHead className="w-[200px]">标题</TableHead>
                <TableHead className="text-center w-[120px]">订阅者</TableHead>
                <TableHead className="w-[180px]">创建时间</TableHead>
                <TableHead className="text-right w-[150px]">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {[...Array(5)].map((_, i) => (
                <TableRow key={i}>
                  <TableCell><Skeleton className="h-4 w-12" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-40" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-16 mx-auto" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-32" /></TableCell>
                  <TableCell><Skeleton className="h-8 w-24 ml-auto" /></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      ) : lists.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Users className="w-12 h-12 text-muted-foreground mb-4" />
            <p className="text-lg font-medium mb-2">还没有邮件列表</p>
            <p className="text-muted-foreground mb-4">创建您的第一个列表开始收集订阅者</p>
            <Button onClick={handleCreate}>
              <Plus className="w-4 h-4 mr-2" />
              创建列表
            </Button>
          </CardContent>
        </Card>
      ) : (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-[50px]">ID</TableHead>
                <TableHead className="w-[200px]">标题</TableHead>
                <TableHead className="text-center w-[120px]">订阅者</TableHead>
                <TableHead className="w-[180px]">创建时间</TableHead>
                <TableHead className="text-right w-[150px]">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredLists?.map((list) => (
                <TableRow 
                  key={list.id}
                  className="cursor-pointer hover:bg-muted/50 transition-colors"
                  onClick={() => navigate(`/lists/${list.id}/subscribers`)}
                >
                  <TableCell className="font-mono text-muted-foreground">
                    #{list.id}
                  </TableCell>
                  <TableCell>
                    <div className="font-medium text-primary">
                      {list.name}
                    </div>
                  </TableCell>
                  <TableCell className="text-center">
                    <div className="flex items-center justify-center gap-1">
                      <Users className="w-4 h-4 text-muted-foreground" />
                      <span className="font-semibold">{list.subscribers_count || 0}</span>
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
                      <Clock className="w-4 h-4" />
                      {formatDateTime(list.created_at)}
                    </div>
                  </TableCell>
                  <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                    <div className="flex items-center justify-end -space-x-px">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => handleEdit(list)}
                        title="编辑"
                        className="px-1.5"
                      >
                        <Edit className="w-4 h-4" />
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => handleDelete(list)}
                        disabled={deleteMutation.isPending}
                        title="删除"
                        className="px-1.5"
                      >
                        <Trash2 className="w-4 h-4 text-red-500" />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      )}

      {/* 创建对话框 */}
      <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>创建新列表</DialogTitle>
            <DialogDescription>创建一个新的邮件订阅者列表</DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="name">
                列表名称 <span className="text-red-500">*</span>
              </Label>
              <Input
                id="name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="例如：新闻订阅者"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="description">描述</Label>
              <Input
                id="description"
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                placeholder="列表的用途说明"
              />
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
                {createMutation.isPending ? '创建中...' : '创建'}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* 编辑对话框 */}
      <Dialog open={isEditOpen} onOpenChange={setIsEditOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>编辑列表</DialogTitle>
            <DialogDescription>修改邮件列表信息</DialogDescription>
          </DialogHeader>
          <form onSubmit={handleUpdate} className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="edit-name">
                列表名称 <span className="text-red-500">*</span>
              </Label>
              <Input
                id="edit-name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="例如：新闻订阅者"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="edit-description">描述</Label>
              <Input
                id="edit-description"
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                placeholder="列表的用途说明"
              />
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setIsEditOpen(false)
                  setEditingList(null)
                  resetForm()
                }}
              >
                取消
              </Button>
              <Button type="submit" disabled={updateMutation.isPending}>
                {updateMutation.isPending ? '更新中...' : '保存'}
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
