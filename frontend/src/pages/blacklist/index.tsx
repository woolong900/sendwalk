import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, Upload, Search, AlertCircle } from 'lucide-react'
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

interface BlacklistEntry {
  id: number
  email: string
  reason: string | null
  created_at: string
}

interface PaginatedResponse {
  data: BlacklistEntry[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export default function BlacklistPage() {
  const { confirm, ConfirmDialog } = useConfirm()
  
  const [isAddOpen, setIsAddOpen] = useState(false)
  const [isBatchUploadOpen, setIsBatchUploadOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  
  const [addFormData, setAddFormData] = useState({
    email: '',
    reason: '',
  })
  
  const [batchFormData, setBatchFormData] = useState({
    emails: '',
    reason: '',
  })
  
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  
  const [selectedIds, setSelectedIds] = useState<number[]>([])

  const queryClient = useQueryClient()

  // 获取黑名单列表
  const { data: blacklistData, isLoading } = useQuery<PaginatedResponse>({
    queryKey: ['blacklist', currentPage, searchQuery],
    queryFn: async () => {
      const params = new URLSearchParams({
        page: currentPage.toString(),
        per_page: '15',
      })
      
      if (searchQuery) {
        params.append('search', searchQuery)
      }
      
      const response = await api.get(`/blacklist?${params}`)
      return response.data
    },
  })

  // 添加单个邮箱
  const addMutation = useMutation({
    mutationFn: async (data: typeof addFormData) => {
      return api.post('/blacklist', data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['blacklist'] })
      toast.success('已添加到黑名单')
      setIsAddOpen(false)
      setAddFormData({ email: '', reason: '' })
    },
  })

  // 批量上传
  const batchUploadMutation = useMutation({
    mutationFn: async (data: typeof batchFormData) => {
      return api.post('/blacklist/batch-upload', data)
    },
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['blacklist'] })
      const { added, skipped, subscribers_updated } = response.data
      toast.success(
        `批量上传完成：新增 ${added} 个，跳过 ${skipped} 个，更新订阅者 ${subscribers_updated} 个`
      )
      setIsBatchUploadOpen(false)
      setBatchFormData({ emails: '', reason: '' })
      setSelectedFile(null)
    },
  })

  // 删除单个
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/blacklist/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['blacklist'] })
      toast.success('已从黑名单移除')
    },
  })

  // 批量删除
  const batchDeleteMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      return api.post('/blacklist/batch-delete', { ids })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['blacklist'] })
      setSelectedIds([])
      toast.success('批量删除成功')
    },
  })

  const handleAdd = (e: React.FormEvent) => {
    e.preventDefault()
    addMutation.mutate(addFormData)
  }

  const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    
    if (!file.name.endsWith('.txt')) {
      toast.error('请选择txt文件')
      return
    }
    
    setSelectedFile(file)
    
    // 读取文件内容
    const reader = new FileReader()
    reader.onload = (event) => {
      const content = event.target?.result as string
      setBatchFormData({ ...batchFormData, emails: content })
    }
    reader.readAsText(file)
  }

  const handleBatchUpload = (e: React.FormEvent) => {
    e.preventDefault()
    if (!batchFormData.emails.trim()) {
      toast.error('请选择文件')
      return
    }
    batchUploadMutation.mutate(batchFormData)
  }

  const handleDelete = async (id: number) => {
    const confirmed = await confirm({
      title: '确认删除',
      description: '确定要从黑名单中移除此邮箱吗？',
    })
    
    if (confirmed) {
      deleteMutation.mutate(id)
    }
  }

  const handleBatchDelete = async () => {
    if (selectedIds.length === 0) {
      toast.error('请至少选择一个邮箱')
      return
    }

    const confirmed = await confirm({
      title: '确认批量删除',
      description: `确定要从黑名单中移除选中的 ${selectedIds.length} 个邮箱吗？`,
    })
    
    if (confirmed) {
      batchDeleteMutation.mutate(selectedIds)
    }
  }

  const handleSelectAll = (checked: boolean) => {
    if (checked && blacklistData?.data) {
      setSelectedIds(blacklistData.data.map(item => item.id))
    } else {
      setSelectedIds([])
    }
  }

  const handleSelectOne = (id: number, checked: boolean) => {
    if (checked) {
      setSelectedIds([...selectedIds, id])
    } else {
      setSelectedIds(selectedIds.filter(selectedId => selectedId !== id))
    }
  }

  const blacklist = blacklistData?.data || []
  const totalPages = blacklistData?.last_page || 1
  const total = blacklistData?.total || 0

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">黑名单管理</h1>
          <p className="text-muted-foreground mt-1">
            管理不允许接收邮件的邮箱地址
          </p>
        </div>
        <div className="flex gap-2">
          <Dialog open={isBatchUploadOpen} onOpenChange={setIsBatchUploadOpen}>
            <DialogTrigger asChild>
              <Button>
                <Upload className="w-4 h-4 mr-2" />
                批量上传
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>批量上传黑名单</DialogTitle>
                <DialogDescription>
                  上传txt文件，每行一个邮箱地址，或使用逗号、分号分隔
                </DialogDescription>
              </DialogHeader>
              <form onSubmit={handleBatchUpload} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="file-upload">选择文件 *</Label>
                  <div className="flex items-center gap-3">
                    <Input
                      id="file-upload"
                      type="file"
                      accept=".txt"
                      onChange={handleFileChange}
                      className="cursor-pointer"
                      required
                    />
                  </div>
                  {selectedFile && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground mt-2">
                      <Upload className="w-4 h-4" />
                      <span>{selectedFile.name}</span>
                      <span className="text-xs">
                        ({Math.round(selectedFile.size / 1024)} KB)
                      </span>
                    </div>
                  )}
                  {batchFormData.emails && (
                    <div className="text-sm text-muted-foreground mt-2">
                      已识别 {batchFormData.emails.split(/[\n,;]/).filter(e => e.trim()).length} 个邮箱地址
                    </div>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="batch-reason">原因（可选）</Label>
                  <Input
                    id="batch-reason"
                    value={batchFormData.reason}
                    onChange={(e) => setBatchFormData({ ...batchFormData, reason: e.target.value })}
                    placeholder="例如：垃圾邮件投诉"
                  />
                </div>
                <div className="flex items-start gap-2 p-3 bg-blue-50 rounded-md">
                  <AlertCircle className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
                  <p className="text-sm text-blue-900">
                    上传后，系统会自动将所有列表中匹配的订阅者状态改为"黑名单"
                  </p>
                </div>
                <div className="flex justify-end gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                      setIsBatchUploadOpen(false)
                      setSelectedFile(null)
                      setBatchFormData({ emails: '', reason: '' })
                    }}
                  >
                    取消
                  </Button>
                  <Button type="submit" disabled={batchUploadMutation.isPending}>
                    {batchUploadMutation.isPending ? '上传中...' : '上传'}
                  </Button>
                </div>
              </form>
            </DialogContent>
          </Dialog>

          <Dialog open={isAddOpen} onOpenChange={setIsAddOpen}>
            <DialogTrigger asChild>
              <Button variant="outline">
                <Plus className="w-4 h-4 mr-2" />
                添加单个
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>添加到黑名单</DialogTitle>
                <DialogDescription>
                  添加单个邮箱地址到黑名单
                </DialogDescription>
              </DialogHeader>
              <form onSubmit={handleAdd} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="email">邮箱地址 *</Label>
                  <Input
                    id="email"
                    type="email"
                    value={addFormData.email}
                    onChange={(e) => setAddFormData({ ...addFormData, email: e.target.value })}
                    placeholder="example@domain.com"
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="reason">原因（可选）</Label>
                  <Input
                    id="reason"
                    value={addFormData.reason}
                    onChange={(e) => setAddFormData({ ...addFormData, reason: e.target.value })}
                    placeholder="例如：垃圾邮件投诉"
                  />
                </div>
                <div className="flex justify-end gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => setIsAddOpen(false)}
                  >
                    取消
                  </Button>
                  <Button type="submit" disabled={addMutation.isPending}>
                    {addMutation.isPending ? '添加中...' : '添加'}
                  </Button>
                </div>
              </form>
            </DialogContent>
          </Dialog>
        </div>
      </div>

      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>黑名单列表</CardTitle>
              <CardDescription>共 {total} 个邮箱</CardDescription>
            </div>
            <div className="flex items-center gap-2">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-4 h-4" />
                <Input
                  placeholder="搜索邮箱..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="pl-9 w-64"
                />
              </div>
              {selectedIds.length > 0 && (
                <Button
                  variant="destructive"
                  size="sm"
                  onClick={handleBatchDelete}
                >
                  <Trash2 className="w-4 h-4 mr-2" />
                  删除选中 ({selectedIds.length})
                </Button>
              )}
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-8 text-muted-foreground">加载中...</div>
          ) : blacklist.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              {searchQuery ? '没有找到匹配的邮箱' : '暂无黑名单'}
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-12">
                      <input
                        type="checkbox"
                        checked={selectedIds.length === blacklist.length && blacklist.length > 0}
                        onChange={(e) => handleSelectAll(e.target.checked)}
                        className="rounded border-gray-300"
                      />
                    </TableHead>
                    <TableHead>邮箱地址</TableHead>
                    <TableHead>原因</TableHead>
                    <TableHead>添加时间</TableHead>
                    <TableHead className="text-right">操作</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {blacklist.map((entry) => (
                    <TableRow key={entry.id}>
                      <TableCell>
                        <input
                          type="checkbox"
                          checked={selectedIds.includes(entry.id)}
                          onChange={(e) => handleSelectOne(entry.id, e.target.checked)}
                          className="rounded border-gray-300"
                        />
                      </TableCell>
                      <TableCell className="font-mono text-sm">{entry.email}</TableCell>
                      <TableCell>
                        {entry.reason ? (
                          <Badge variant="secondary">{entry.reason}</Badge>
                        ) : (
                          <span className="text-muted-foreground text-sm">-</span>
                        )}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {new Date(entry.created_at).toLocaleDateString('zh-CN')}
                      </TableCell>
                      <TableCell className="text-right">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleDelete(entry.id)}
                        >
                          <Trash2 className="w-4 h-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              {totalPages > 1 && (
                <div className="flex items-center justify-between mt-4">
                  <div className="text-sm text-muted-foreground">
                    第 {currentPage} 页，共 {totalPages} 页
                  </div>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(currentPage - 1)}
                      disabled={currentPage === 1}
                    >
                      上一页
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(currentPage + 1)}
                      disabled={currentPage === totalPages}
                    >
                      下一页
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog />
    </div>
  )
}

