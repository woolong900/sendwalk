import { useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Search, Mail, Trash2, Upload, CheckCircle, List, RefreshCw, Loader } from 'lucide-react'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Progress } from '@/components/ui/progress'
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
import { api } from '@/lib/api'
import { formatDateTime } from '@/lib/utils'
import { useConfirm } from '@/hooks/use-confirm'

interface Subscriber {
  id: number
  email: string
  first_name: string
  last_name: string
  status: string
  list_status?: string  // 在特定列表中的状态
  list_unsubscribed_at?: string  // 在特定列表中的取消订阅时间
  subscribed_at: string
  created_at: string
}

interface MailingList {
  id: number
  name: string
  description: string
  subscribers_count: number
}

interface PaginatedResponse {
  data: Subscriber[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export default function SubscribersPage() {
  const { confirm, ConfirmDialog } = useConfirm()
  
  const { listId } = useParams<{ listId: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const [isImportOpen, setIsImportOpen] = useState(false)
  const [searchTerm, setSearchTerm] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [currentPage, setCurrentPage] = useState(1)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [uploadProgress, setUploadProgress] = useState(0)
  const [isUploading, setIsUploading] = useState(false)
  const [importResult, setImportResult] = useState<{
    imported: number
    skipped: number
    errors: string[]
    status?: string
  } | null>(null)
  const [formData, setFormData] = useState({
    email: '',
    first_name: '',
    last_name: '',
  })

  // 获取列表信息
  const { data: mailingList } = useQuery<MailingList>({
    queryKey: ['list', listId],
    queryFn: async () => {
      const response = await api.get(`/lists/${listId}`)
      return response.data.data
    },
    enabled: !!listId,
  })

  // 获取订阅者
  const { data: subscribersData, isLoading, isFetching, refetch } = useQuery<PaginatedResponse>({
    queryKey: ['subscribers', listId, currentPage, searchTerm, statusFilter],
    queryFn: async () => {
      const params = new URLSearchParams({
        page: currentPage.toString(),
        list_id: listId || '',
      })
      
      if (searchTerm) params.append('search', searchTerm)
      if (statusFilter !== 'all') params.append('status', statusFilter)
      
      const response = await api.get(`/subscribers?${params}`)
      return response.data
    },
    enabled: !!listId,
  })

  // 创建订阅者
  const createMutation = useMutation({
    mutationFn: async (data: typeof formData) => {
      return api.post('/subscribers', {
        ...data,
        list_ids: [parseInt(listId!)],
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subscribers'] })
      queryClient.invalidateQueries({ queryKey: ['list', listId] })
      toast.success('订阅者添加成功')
      setIsCreateOpen(false)
      setFormData({ email: '', first_name: '', last_name: '' })
    },
    // onError 已由全局拦截器处理
  })

  // 删除订阅者
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/subscribers/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subscribers'] })
      queryClient.invalidateQueries({ queryKey: ['list', listId] })
      toast.success('订阅者删除成功')
    },
    // onError 已由全局拦截器处理
  })

  // 批量导入
  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData()
      formData.append('file', file)
      formData.append('list_id', listId!)
      
      setIsUploading(true)
      setUploadProgress(0)
      setImportResult(null)
      
      const response = await api.post('/subscribers/bulk-import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      
      return response.data
    },
    onSuccess: (data) => {
      // 收到 import_id，开始轮询进度
      const { import_id } = data.data
      pollImportProgress(import_id)
    },
    onError: () => {
      setUploadProgress(0)
      setIsUploading(false)
      // toast.error 已由全局拦截器处理
    },
  })

  // 轮询导入进度
  const pollImportProgress = async (importId: string) => {
    const pollInterval = setInterval(async () => {
      try {
        const response = await api.get(`/subscribers/import-progress/${importId}`)
        const progress = response.data.data
        
        setUploadProgress(progress.progress || 0)
        setImportResult({
          imported: progress.imported || 0,
          skipped: progress.skipped || 0,
          errors: [],
          status: progress.status,
        })
        
        // 检查是否完成
        if (progress.status === 'completed') {
          clearInterval(pollInterval)
          setIsUploading(false)
          
          queryClient.invalidateQueries({ queryKey: ['subscribers'] })
          queryClient.invalidateQueries({ queryKey: ['list', listId] })
          
          toast.success(`成功导入 ${progress.imported} 个订阅者，跳过 ${progress.skipped} 个`)
          
          setTimeout(() => {
            setIsImportOpen(false)
            setSelectedFile(null)
            setUploadProgress(0)
            setImportResult(null)
          }, 3000)
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

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    createMutation.mutate(formData)
  }

  const handleDelete = async (subscriber: Subscriber) => {
    const confirmed = await confirm({
      title: '删除订阅者',
      description: `确定要删除订阅者"${subscriber.email}"吗？`,
      confirmText: '删除',
      cancelText: '取消',
      variant: 'destructive',
    })
    if (confirmed) {
      deleteMutation.mutate(subscriber.id)
    }
  }

  const handleImport = () => {
    if (selectedFile) {
      importMutation.mutate(selectedFile)
    } else {
      toast.error('请先选择文件')
    }
  }

  const getStatusBadge = (status: string) => {
    const variants: { [key: string]: 'default' | 'secondary' | 'destructive' } = {
      active: 'default',
      unsubscribed: 'secondary',
      bounced: 'destructive',
      complained: 'destructive',
      blacklisted: 'destructive',
    }
    
    const labels: { [key: string]: string } = {
      active: '活跃',
      unsubscribed: '已退订',
      complained: '投诉',
      blacklisted: '黑名单',
    }
    
    return <Badge variant={variants[status] || 'secondary'}>{labels[status] || status}</Badge>
  }

  if (!listId) {
    return (
      <Card>
        <CardContent className="py-12 text-center">
          <p className="text-muted-foreground">无效的列表ID</p>
          <Button onClick={() => navigate('/lists')} className="mt-4">
            返回列表
          </Button>
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="space-y-6">
      {/* 页头 */}
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-2 text-sm text-muted-foreground mb-2">
            <Link to="/lists" className="hover:text-primary flex items-center gap-1">
              <List className="w-4 h-4" />
              邮件列表
            </Link>
            <span>/</span>
            <span className="text-foreground font-medium">{mailingList?.name || '订阅者'}</span>
          </div>
          <h1 className="text-3xl font-bold tracking-tight">订阅者管理</h1>
          <p className="text-muted-foreground mt-2">管理和组织您的订阅者</p>
        </div>
        <div className="flex gap-2">
          <Button 
            variant="outline" 
            size="sm"
            onClick={() => refetch()}
            title="刷新列表"
          >
            <RefreshCw className="w-4 h-4" />
          </Button>
          <Button variant="outline" onClick={() => setIsImportOpen(true)}>
            <Upload className="w-4 h-4 mr-2" />
            批量导入
          </Button>
          <Button onClick={() => setIsCreateOpen(true)}>
            <Plus className="w-4 h-4 mr-2" />
            添加订阅者
          </Button>
        </div>
      </div>

      {/* 统计卡片 */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardTitle className="text-sm font-medium">订阅者总数</CardTitle>
          <Mail className="h-4 w-4 text-muted-foreground" />
        </CardHeader>
        <CardContent>
          <div className="text-2xl font-bold">{subscribersData?.meta.total || 0}</div>
          <p className="text-xs text-muted-foreground mt-1">
            当前列表的订阅者数量
          </p>
        </CardContent>
      </Card>

      {/* 搜索和筛选 */}
      <div className="flex items-center gap-4">
        <div className="relative flex-1 max-w-sm">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="搜索邮箱、姓名..."
            value={searchTerm}
            onChange={(e) => {
              setSearchTerm(e.target.value)
              setCurrentPage(1)
            }}
            className="pl-9 pr-9"
          />
          {isFetching && (
            <Loader className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground animate-spin" />
          )}
        </div>
        <Select
          value={statusFilter}
          onValueChange={(value) => {
            setStatusFilter(value)
            setCurrentPage(1)
          }}
        >
          <SelectTrigger className="w-[150px]">
            <SelectValue placeholder="状态筛选" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">全部状态</SelectItem>
            <SelectItem value="active">活跃</SelectItem>
            <SelectItem value="unsubscribed">已退订</SelectItem>
            <SelectItem value="complained">投诉</SelectItem>
            <SelectItem value="blacklisted">黑名单</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {/* 订阅者表格 */}
      {isLoading || !subscribersData ? (
        // 加载中显示骨架屏
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-[50px]">ID</TableHead>
                <TableHead>邮箱</TableHead>
                <TableHead>姓名</TableHead>
                <TableHead className="text-center w-[100px]">状态</TableHead>
                <TableHead className="w-[180px]">订阅时间</TableHead>
                <TableHead className="text-right w-[100px]">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {[...Array(5)].map((_, i) => (
                <TableRow key={i}>
                  <TableCell><Skeleton className="h-4 w-12" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-48" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-24" /></TableCell>
                  <TableCell><Skeleton className="h-6 w-16 mx-auto" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-32" /></TableCell>
                  <TableCell><Skeleton className="h-8 w-16 ml-auto" /></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      ) : subscribersData.data.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Mail className="w-12 h-12 text-muted-foreground mb-4" />
            <p className="text-lg font-medium mb-2">还没有订阅者</p>
            <p className="text-muted-foreground mb-4">添加您的第一个订阅者</p>
            <div className="flex gap-2">
              <Button onClick={() => setIsImportOpen(true)} variant="outline">
                <Upload className="w-4 h-4 mr-2" />
                批量导入
              </Button>
              <Button onClick={() => setIsCreateOpen(true)}>
                <Plus className="w-4 h-4 mr-2" />
                添加订阅者
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : (
        <>
          <Card>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-[50px]">ID</TableHead>
                  <TableHead>邮箱</TableHead>
                  <TableHead>姓名</TableHead>
                  <TableHead className="text-center w-[100px]">状态</TableHead>
                  <TableHead className="w-[180px]">订阅时间</TableHead>
                  <TableHead className="text-right w-[100px]">操作</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {subscribersData?.data.map((subscriber) => (
                  <TableRow key={subscriber.id}>
                    <TableCell className="font-mono text-muted-foreground">
                      #{subscriber.id}
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        <Mail className="w-4 h-4 text-muted-foreground" />
                        {subscriber.email}
                      </div>
                    </TableCell>
                    <TableCell>
                      {subscriber.first_name || subscriber.last_name
                        ? `${subscriber.first_name || ''} ${subscriber.last_name || ''}`.trim()
                        : '-'}
                    </TableCell>
                    <TableCell className="text-center">
                      {getStatusBadge(subscriber.list_status || subscriber.status)}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {formatDateTime(subscriber.subscribed_at || subscriber.created_at)}
                    </TableCell>
                    <TableCell className="text-right">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => handleDelete(subscriber)}
                        disabled={deleteMutation.isPending}
                      >
                        <Trash2 className="w-4 h-4 text-red-500" />
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>

          {/* 分页 */}
          {subscribersData && subscribersData.meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setCurrentPage(currentPage - 1)}
                disabled={currentPage === 1}
              >
                上一页
              </Button>
              <span className="text-sm text-muted-foreground">
                第 {currentPage} / {subscribersData.meta.last_page} 页
              </span>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setCurrentPage(currentPage + 1)}
                disabled={currentPage === subscribersData.meta.last_page}
              >
                下一页
              </Button>
            </div>
          )}
        </>
      )}

      {/* 添加订阅者对话框 */}
      <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>添加订阅者</DialogTitle>
            <DialogDescription>
              添加新的订阅者到列表"{mailingList?.name}"
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="email">
                邮箱地址 <span className="text-red-500">*</span>
              </Label>
              <Input
                id="email"
                type="email"
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                placeholder="user@example.com"
                required
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="first_name">名</Label>
                <Input
                  id="first_name"
                  value={formData.first_name}
                  onChange={(e) => setFormData({ ...formData, first_name: e.target.value })}
                  placeholder="张"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="last_name">姓</Label>
                <Input
                  id="last_name"
                  value={formData.last_name}
                  onChange={(e) => setFormData({ ...formData, last_name: e.target.value })}
                  placeholder="三"
                />
              </div>
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
                {createMutation.isPending ? '添加中...' : '添加'}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* 批量导入对话框 */}
      <Dialog open={isImportOpen} onOpenChange={setIsImportOpen}>
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>批量导入订阅者</DialogTitle>
            <DialogDescription>
              导入订阅者到列表"{mailingList?.name}"
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-5">
            <div className="space-y-2">
              <Label>选择CSV文件</Label>
              <Input
                type="file"
                accept=".csv"
                onChange={(e) => setSelectedFile(e.target.files?.[0] || null)}
                disabled={isUploading}
              />
              <p className="text-xs text-muted-foreground">
                CSV格式：email,first_name,last_name
              </p>
            </div>

            {isUploading && (
              <div className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span>上传进度</span>
                  <span>{uploadProgress}%</span>
                </div>
                <Progress value={uploadProgress} />
              </div>
            )}

            {importResult && (
              <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                <div className="flex items-start gap-3">
                  <CheckCircle className="w-5 h-5 text-green-600 mt-0.5" />
                  <div className="flex-1">
                    <p className="font-medium text-green-900">
                      成功导入 {importResult.imported} 个订阅者
                      {importResult.skipped > 0 && `，跳过 ${importResult.skipped} 个`}
                    </p>
                  </div>
                </div>
              </div>
            )}

            <div className="flex justify-end gap-2 pt-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setIsImportOpen(false)
                  setSelectedFile(null)
                  setUploadProgress(0)
                  setImportResult(null)
                }}
                disabled={isUploading}
              >
                {importResult ? '关闭' : '取消'}
              </Button>
              <Button
                onClick={handleImport}
                disabled={!selectedFile || isUploading || !!importResult}
              >
                {isUploading ? '导入中...' : '开始导入'}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* 确认对话框 */}
      <ConfirmDialog />
    </div>
  )
}
