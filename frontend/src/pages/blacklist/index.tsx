import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, Upload, Search, AlertCircle, Loader2, CheckCircle2, XCircle } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Progress } from '@/components/ui/progress'
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

interface ImportProgress {
  total_batches: number
  completed_batches: number
  total_emails?: number
  added: number
  already_exists: number
  invalid: number
  subscribers_updated: number
  status: 'processing' | 'completed' | 'failed'
  progress_percentage: number
  started_at: string
  completed_at?: string
  error?: string
}

export default function BlacklistPage() {
  const { confirm, ConfirmDialog } = useConfirm()
  
  const [isAddOpen, setIsAddOpen] = useState(false)
  const [isBatchUploadOpen, setIsBatchUploadOpen] = useState(false)
  const [isProgressOpen, setIsProgressOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [importTaskId, setImportTaskId] = useState<string | null>(null)
  
  const [addFormData, setAddFormData] = useState({
    email: '',
    reason: '',
  })
  
  const [batchFormData, setBatchFormData] = useState({
    reason: '',
  })
  
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [isUploading, setIsUploading] = useState(false)
  const [processingProgress, setProcessingProgress] = useState(0) // 导入处理进度
  const [importResult, setImportResult] = useState<{
    added: number
    already_exists: number
    invalid: number
    status?: string
  } | null>(null)
  
  const [selectedIds, setSelectedIds] = useState<number[]>([])

  const queryClient = useQueryClient()

  // 查询导入进度（从服务器）
  const { data: importProgress, refetch: refetchProgress } = useQuery<ImportProgress>({
    queryKey: ['blacklist-import-progress', importTaskId],
    queryFn: async () => {
      if (!importTaskId) throw new Error('No task ID')
      const response = await api.get(`/blacklist/import-progress/${importTaskId}`)
      return response.data
    },
    enabled: !!importTaskId && isProgressOpen,
    refetchInterval: (query) => {
      // 如果任务还在处理中，每2秒刷新一次
      if (query.state.data?.status === 'processing') {
        return 2000
      }
      // 任务完成或失败，停止轮询
      return false
    },
  })

  // 监听导入进度完成
  useEffect(() => {
    if (importProgress && importProgress.status === 'completed') {
      queryClient.invalidateQueries({ queryKey: ['blacklist'] })
      const { added, already_exists, invalid, subscribers_updated } = importProgress
      
      const messages = []
      if (added > 0) messages.push(`新增 ${added} 个`)
      if (already_exists > 0) messages.push(`已存在 ${already_exists} 个`)
      if (invalid > 0) messages.push(`无效 ${invalid} 个`)
      
      const summary = messages.join('，')
      const subscriberInfo = subscribers_updated > 0 
        ? `，更新订阅者 ${subscribers_updated} 个` 
        : ''
      
      toast.success(`导入完成！${summary}${subscriberInfo}`)
    } else if (importProgress && importProgress.status === 'failed') {
      toast.error(`导入失败：${importProgress.error || '未知错误'}`)
    }
  }, [importProgress, queryClient])

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

  // 批量上传（文件上传）
  const batchUploadMutation = useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData()
      formData.append('file', file)
      if (batchFormData.reason) {
        formData.append('reason', batchFormData.reason)
      }
      
      setIsUploading(true)
      setProcessingProgress(0)
      setImportResult(null)
      
      return api.post('/blacklist/batch-upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
    },
    onSuccess: (response) => {
      const data = response.data.data
      
      if (data.import_id) {
        // 文件上传完成，开始后台处理
        setImportTaskId(data.import_id)
        pollImportProgress(data.import_id)
      }
    },
    onError: () => {
      setIsUploading(false)
      setProcessingProgress(0)
      toast.error('上传失败，请重试')
    },
  })

  // 轮询导入进度
  const pollImportProgress = (importId: string) => {
    const pollInterval = setInterval(async () => {
      try {
        const response = await api.get(`/blacklist/import-progress/${importId}`)
        const progress = response.data.data
        
        // 更新处理进度
        if (progress.progress !== undefined) {
          setProcessingProgress(progress.progress)
        }
        
        // 更新结果显示（始终更新，不管是否为0）
        if (progress.status === 'processing') {
          setImportResult({
            added: progress.added || 0,
            already_exists: progress.already_exists || 0,
            invalid: progress.invalid || 0,
            status: progress.status,
          })
        }
        
        // 检查是否完成
        if (progress.status === 'completed') {
          clearInterval(pollInterval)
          setIsUploading(false)
          setProcessingProgress(100)
          
          // 更新最终结果
          setImportResult({
            added: progress.added || 0,
            already_exists: progress.already_exists || 0,
            invalid: progress.invalid || 0,
            status: progress.status,
          })
          
          // 刷新列表
          queryClient.invalidateQueries({ queryKey: ['blacklist'] })
          
          // 不自动关闭，让用户查看结果后手动关闭
        } else if (progress.status === 'failed') {
          clearInterval(pollInterval)
          setIsUploading(false)
          toast.error(progress.error || '导入失败')
        }
      } catch (error) {
        console.error('轮询导入进度失败:', error)
        clearInterval(pollInterval)
        setIsUploading(false)
      }
    }, 1000) // 每秒轮询一次
  }

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

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    
    // 支持多种文件格式
    const allowedExtensions = ['.txt', '.csv', '.xlsx', '.xls']
    const fileExtension = file.name.substring(file.name.lastIndexOf('.')).toLowerCase()
    
    if (!allowedExtensions.includes(fileExtension)) {
      toast.error('请选择txt、csv或xlsx文件')
      return
    }
    
    setSelectedFile(file)
    // 不再读取文件内容到内存，直接保存文件对象
  }

  const handleBatchUpload = (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedFile) {
      toast.error('请选择文件')
      return
    }
    batchUploadMutation.mutate(selectedFile)
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
          <h1 className="text-xl md:text-2xl font-bold">黑名单</h1>
          <p className="text-muted-foreground mt-1">
            管理黑名单列表
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
                  <Input
                    id="file-upload"
                    type="file"
                    accept=".txt,.csv,.xlsx,.xls"
                    onChange={handleFileChange}
                    className="cursor-pointer"
                    disabled={isUploading}
                    required
                  />
                  {selectedFile && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground mt-2">
                      <Upload className="w-4 h-4" />
                      <span>{selectedFile.name}</span>
                      <span className="text-xs">
                        ({(selectedFile.size / 1024 / 1024).toFixed(2)} MB)
                      </span>
                    </div>
                  )}
                  <p className="text-xs text-muted-foreground">
                    支持txt、csv或xlsx文件，每行一个邮箱地址
                  </p>
                </div>

                {isUploading && (
                  <div className="space-y-3">
                    {/* 导入处理进度 */}
                    <div className="space-y-2">
                      <div className="flex justify-between text-sm">
                        <span>导入进度</span>
                        <span>{processingProgress}%</span>
                      </div>
                      <Progress value={processingProgress} />
                    </div>
                    
                    {/* 实时统计 */}
                    {importResult && (
                      <div className="flex flex-col gap-2 text-sm">
                        <div className="flex items-center gap-4">
                          <span>新增: <span className="font-medium text-green-600">{importResult.added}</span></span>
                          <span>已存在: <span className="font-medium text-orange-600">{importResult.already_exists}</span></span>
                          <span>无效: <span className="font-medium text-red-600">{importResult.invalid}</span></span>
                        </div>
                      </div>
                    )}
                  </div>
                )}

                {importResult && importResult.status === 'completed' && !isUploading && (
                  <div className="space-y-3">
                    <div className="flex items-center gap-2 p-3 bg-green-50 rounded-md">
                      <CheckCircle2 className="w-5 h-5 text-green-600" />
                      <div className="text-sm text-green-900">
                        导入完成！新增 {importResult.added} 个，已存在 {importResult.already_exists} 个，无效 {importResult.invalid} 个
                      </div>
                    </div>
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                      <AlertCircle className="w-4 h-4" />
                      <span>请点击"关闭"按钮查看导入结果</span>
                    </div>
                  </div>
                )}

                <div className="space-y-2">
                  <Label htmlFor="batch-reason">原因（可选）</Label>
                  <Input
                    id="batch-reason"
                    value={batchFormData.reason}
                    onChange={(e) => setBatchFormData({ ...batchFormData, reason: e.target.value })}
                    placeholder="例如：垃圾邮件投诉"
                    disabled={isUploading}
                  />
                </div>
                <div className="flex items-start gap-2 p-3 bg-blue-50 rounded-md">
                  <AlertCircle className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
                  <p className="text-sm text-blue-900">
                    上传后，系统会自动将所有列表中匹配的订阅者状态改为"黑名单"
                  </p>
                </div>
                <div className="flex justify-end gap-2">
                  {!isUploading && importResult?.status !== 'completed' && (
                    <>
                      <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                          setIsBatchUploadOpen(false)
                          setSelectedFile(null)
                          setBatchFormData({ reason: '' })
                          setImportResult(null)
                          setProcessingProgress(0)
                        }}
                      >
                        取消
                      </Button>
                      <Button type="submit" disabled={!selectedFile}>
                        开始导入
                      </Button>
                    </>
                  )}
                  
                  {isUploading && (
                    <Button disabled>
                      <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                      导入中...
                    </Button>
                  )}
                  
                  {!isUploading && importResult?.status === 'completed' && (
                    <Button
                      onClick={() => {
                        setIsBatchUploadOpen(false)
                        setSelectedFile(null)
                        setBatchFormData({ reason: '' })
                        setImportResult(null)
                        setProcessingProgress(0)
                      }}
                    >
                      关闭
                    </Button>
                  )}
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
              <div className="overflow-x-auto">
                <Table className="min-w-[820px]">
                  <colgroup>
                    <col className="w-[48px]" />
                    <col className="w-[300px]" />
                    <col className="w-[200px]" />
                    <col className="w-[150px]" />
                    <col className="w-[120px]" />
                  </colgroup>
                  <TableHeader>
                  <TableRow>
                    <TableHead>
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
                      <TableCell className="whitespace-nowrap">
                        <input
                          type="checkbox"
                          checked={selectedIds.includes(entry.id)}
                          onChange={(e) => handleSelectOne(entry.id, e.target.checked)}
                          className="rounded border-gray-300"
                        />
                      </TableCell>
                      <TableCell className="font-mono text-sm whitespace-nowrap">
                        <div className="truncate">{entry.email}</div>
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        {entry.reason ? (
                          <Badge variant="secondary">{entry.reason}</Badge>
                        ) : (
                          <span className="text-muted-foreground text-sm">-</span>
                        )}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                        {(() => {
                          const d = new Date(entry.created_at)
                          const year = d.getFullYear()
                          const month = String(d.getMonth() + 1).padStart(2, '0')
                          const day = String(d.getDate()).padStart(2, '0')
                          return `${year}/${month}/${day}`
                        })()}
                      </TableCell>
                      <TableCell className="text-right whitespace-nowrap">
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
              </div>

              {totalPages > 1 && (
                <div className="flex items-center justify-between mt-4">
                  <div className="text-sm text-muted-foreground">
                    第 {currentPage} 页，共 {totalPages} 页
                  </div>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(1)}
                      disabled={currentPage === 1}
                    >
                      首页
                    </Button>
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
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(totalPages)}
                      disabled={currentPage === totalPages}
                    >
                      尾页
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      {/* 导入进度Dialog */}
      <Dialog open={isProgressOpen} onOpenChange={(open) => {
        // 只有在任务完成或失败时才允许关闭
        if (!open && importProgress && 
            (importProgress.status === 'completed' || importProgress.status === 'failed')) {
          setIsProgressOpen(false)
          setImportTaskId(null)
        }
      }}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              {importProgress?.status === 'processing' && (
                <>
                  <Loader2 className="w-5 h-5 animate-spin text-blue-600" />
                  批量导入进行中...
                </>
              )}
              {importProgress?.status === 'completed' && (
                <>
                  <CheckCircle2 className="w-5 h-5 text-green-600" />
                  导入完成！
                </>
              )}
              {importProgress?.status === 'failed' && (
                <>
                  <XCircle className="w-5 h-5 text-red-600" />
                  导入失败
                </>
              )}
            </DialogTitle>
            <DialogDescription>
              {importTaskId && (
                <span className="text-xs font-mono">任务ID: {importTaskId}</span>
              )}
            </DialogDescription>
          </DialogHeader>

          {importProgress && (
            <div className="space-y-4">
              {/* 进度条 */}
              <div className="space-y-2">
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">整体进度</span>
                  <span className="font-medium">{importProgress.progress_percentage.toFixed(1)}%</span>
                </div>
                <div className="w-full bg-gray-200 rounded-full h-2.5">
                  <div
                    className={`h-2.5 rounded-full transition-all duration-300 ${
                      importProgress.status === 'completed' 
                        ? 'bg-green-600' 
                        : importProgress.status === 'failed'
                        ? 'bg-red-600'
                        : 'bg-blue-600'
                    }`}
                    style={{ width: `${importProgress.progress_percentage}%` }}
                  />
                </div>
                <div className="flex items-center justify-between text-xs text-muted-foreground">
                  <span>批次 {importProgress.completed_batches} / {importProgress.total_batches}</span>
                  {importProgress.total_emails && (
                    <span>共 {importProgress.total_emails.toLocaleString()} 个邮箱</span>
                  )}
                </div>
              </div>

              {/* 统计信息 */}
              <div className="grid grid-cols-2 gap-3">
                <div className="p-3 bg-green-50 rounded-lg">
                  <div className="text-xs text-muted-foreground mb-1">新增</div>
                  <div className="text-2xl font-bold text-green-600">
                    {importProgress.added.toLocaleString()}
                  </div>
                </div>
                <div className="p-3 bg-yellow-50 rounded-lg">
                  <div className="text-xs text-muted-foreground mb-1">已存在</div>
                  <div className="text-2xl font-bold text-yellow-600">
                    {importProgress.already_exists.toLocaleString()}
                  </div>
                </div>
                <div className="p-3 bg-red-50 rounded-lg">
                  <div className="text-xs text-muted-foreground mb-1">无效</div>
                  <div className="text-2xl font-bold text-red-600">
                    {importProgress.invalid.toLocaleString()}
                  </div>
                </div>
                <div className="p-3 bg-blue-50 rounded-lg">
                  <div className="text-xs text-muted-foreground mb-1">订阅者已更新</div>
                  <div className="text-2xl font-bold text-blue-600">
                    {importProgress.subscribers_updated.toLocaleString()}
                  </div>
                </div>
              </div>

              {/* 错误信息 */}
              {importProgress.status === 'failed' && importProgress.error && (
                <div className="p-3 bg-red-50 border border-red-200 rounded-lg">
                  <div className="flex items-start gap-2">
                    <XCircle className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" />
                    <div className="flex-1">
                      <div className="font-medium text-red-900 mb-1">错误信息</div>
                      <div className="text-sm text-red-700">{importProgress.error}</div>
                    </div>
                  </div>
                </div>
              )}

              {/* 操作按钮 */}
              <div className="flex justify-end gap-2">
                {importProgress.status === 'processing' && (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => refetchProgress()}
                  >
                    <Loader2 className="w-4 h-4 mr-2" />
                    刷新进度
                  </Button>
                )}
                {(importProgress.status === 'completed' || importProgress.status === 'failed') && (
                  <Button
                    onClick={() => {
                      setIsProgressOpen(false)
                      setImportTaskId(null)
                    }}
                  >
                    关闭
                  </Button>
                )}
              </div>

              {/* 提示信息 */}
              {importProgress.status === 'processing' && (
                <div className="flex items-start gap-2 p-3 bg-blue-50 rounded-md">
                  <AlertCircle className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
                  <p className="text-sm text-blue-900">
                    导入正在后台进行，您可以关闭此窗口，任务会继续执行。可以随时回来查看进度。
                  </p>
                </div>
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>

      <ConfirmDialog />
    </div>
  )
}

