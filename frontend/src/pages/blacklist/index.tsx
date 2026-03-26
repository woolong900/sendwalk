import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
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
import { maskEmail } from '@/lib/utils'
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
  const { t } = useTranslation()
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
  const [processingProgress, setProcessingProgress] = useState(0)
  const [importResult, setImportResult] = useState<{
    added: number
    already_exists: number
    invalid: number
    status?: string
  } | null>(null)
  
  const [selectedIds, setSelectedIds] = useState<number[]>([])

  const queryClient = useQueryClient()

  const { data: importProgress, refetch: refetchProgress } = useQuery<ImportProgress>({
    queryKey: ['blacklist-import-progress', importTaskId],
    queryFn: async () => {
      if (!importTaskId) throw new Error('No task ID')
      const response = await api.get(`/blacklist/import-progress/${importTaskId}`)
      return response.data
    },
    enabled: !!importTaskId && isProgressOpen,
    refetchInterval: (query) => {
      if (query.state.data?.status === 'processing') {
        return 2000
      }
      return false
    },
  })

  useEffect(() => {
    if (importProgress && importProgress.status === 'completed') {
      queryClient.invalidateQueries({ queryKey: ['blacklist'] })
      const { added, already_exists, invalid, subscribers_updated } = importProgress
      
      const messages = []
      if (added > 0) messages.push(`${t('common.new')} ${added}`)
      if (already_exists > 0) messages.push(`${t('common.alreadyExists')} ${already_exists}`)
      if (invalid > 0) messages.push(`${t('common.invalid')} ${invalid}`)
      
      const summary = messages.join(', ')
      const subscriberInfo = subscribers_updated > 0 
        ? `, ${t('blacklist.subscribersUpdated')} ${subscribers_updated}` 
        : ''
      
      toast.success(`${t('blacklist.importComplete')} ${summary}${subscriberInfo}`)
    } else if (importProgress && importProgress.status === 'failed') {
      toast.error(`${t('common.failed')}: ${importProgress.error || t('common.error')}`)
    }
  }, [importProgress, queryClient, t])

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

  const addMutation = useMutation({
    mutationFn: async (data: typeof addFormData) => {
      return api.post('/blacklist', data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['blacklist'] })
      toast.success(t('blacklist.addSuccess'))
      setIsAddOpen(false)
      setAddFormData({ email: '', reason: '' })
    },
  })

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
        setImportTaskId(data.import_id)
        pollImportProgress(data.import_id)
      }
    },
    onError: () => {
      setIsUploading(false)
      setProcessingProgress(0)
      toast.error(t('common.error'))
    },
  })

  const pollImportProgress = (importId: string) => {
    const pollInterval = setInterval(async () => {
      try {
        const response = await api.get(`/blacklist/import-progress/${importId}`)
        const progress = response.data.data
        
        if (progress.progress !== undefined) {
          setProcessingProgress(progress.progress)
        }
        
        if (progress.status === 'processing') {
          setImportResult({
            added: progress.added || 0,
            already_exists: progress.already_exists || 0,
            invalid: progress.invalid || 0,
            status: progress.status,
          })
        }
        
        if (progress.status === 'completed') {
          clearInterval(pollInterval)
          setIsUploading(false)
          setProcessingProgress(100)
          
          setImportResult({
            added: progress.added || 0,
            already_exists: progress.already_exists || 0,
            invalid: progress.invalid || 0,
            status: progress.status,
          })
          
          queryClient.invalidateQueries({ queryKey: ['blacklist'] })
        } else if (progress.status === 'failed') {
          clearInterval(pollInterval)
          setIsUploading(false)
          toast.error(progress.error || t('common.failed'))
        }
      } catch (error) {
        console.error('Poll import progress failed:', error)
        clearInterval(pollInterval)
        setIsUploading(false)
      }
    }, 3000)
  }

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/blacklist/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['blacklist'] })
      toast.success(t('blacklist.removeSuccess'))
    },
  })

  const batchDeleteMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      return api.post('/blacklist/batch-delete', { ids })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['blacklist'] })
      setSelectedIds([])
      toast.success(t('blacklist.batchDeleteSuccess'))
    },
  })

  const handleAdd = (e: React.FormEvent) => {
    e.preventDefault()
    addMutation.mutate(addFormData)
  }

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    
    const allowedExtensions = ['.txt', '.csv', '.xlsx', '.xls']
    const fileExtension = file.name.substring(file.name.lastIndexOf('.')).toLowerCase()
    
    if (!allowedExtensions.includes(fileExtension)) {
      toast.error(t('blacklist.supportedFormats'))
      return
    }
    
    setSelectedFile(file)
  }

  const handleBatchUpload = (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedFile) {
      toast.error(t('blacklist.selectFile'))
      return
    }
    batchUploadMutation.mutate(selectedFile)
  }

  const handleDelete = async (id: number) => {
    const confirmed = await confirm({
      title: t('blacklist.removeConfirm'),
      description: t('blacklist.removeConfirmDesc'),
    })
    
    if (confirmed) {
      deleteMutation.mutate(id)
    }
  }

  const handleBatchDelete = async () => {
    if (selectedIds.length === 0) {
      toast.error(t('blacklist.selectFile'))
      return
    }

    const confirmed = await confirm({
      title: t('blacklist.batchDeleteConfirm'),
      description: t('blacklist.batchDeleteConfirmDesc', { count: selectedIds.length }),
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
          <h1 className="text-xl md:text-2xl font-bold">{t('blacklist.title')}</h1>
          <p className="text-muted-foreground mt-1">
            {t('blacklist.subtitle')}
          </p>
        </div>
        <div className="flex gap-2">
          <Dialog open={isBatchUploadOpen} onOpenChange={setIsBatchUploadOpen}>
            <DialogTrigger asChild>
              <Button>
                <Upload className="w-4 h-4 mr-2" />
                {t('common.batchUpload')}
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>{t('blacklist.batchUpload')}</DialogTitle>
                <DialogDescription>
                  {t('blacklist.batchUploadDesc')}
                </DialogDescription>
              </DialogHeader>
              <form onSubmit={handleBatchUpload} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="file-upload">{t('blacklist.selectFile')} *</Label>
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
                    {t('blacklist.supportedFormats')}
                  </p>
                </div>

                {isUploading && (
                  <div className="space-y-3">
                    <div className="space-y-2">
                      <div className="flex justify-between text-sm">
                        <span>{t('blacklist.importProgress')}</span>
                        <span>{processingProgress}%</span>
                      </div>
                      <Progress value={processingProgress} />
                    </div>
                    
                    {importResult && (
                      <div className="flex flex-col gap-2 text-sm">
                        <div className="flex items-center gap-4">
                          <span>{t('common.new')}: <span className="font-medium text-green-600">{importResult.added}</span></span>
                          <span>{t('common.alreadyExists')}: <span className="font-medium text-orange-600">{importResult.already_exists}</span></span>
                          <span>{t('common.invalid')}: <span className="font-medium text-red-600">{importResult.invalid}</span></span>
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
                        {t('blacklist.importCompleteDesc', { added: importResult.added, exists: importResult.already_exists, invalid: importResult.invalid })}
                      </div>
                    </div>
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                      <AlertCircle className="w-4 h-4" />
                      <span>{t('common.close')}</span>
                    </div>
                  </div>
                )}

                <div className="space-y-2">
                  <Label htmlFor="batch-reason">{t('common.reason')} ({t('common.optional')})</Label>
                  <Input
                    id="batch-reason"
                    value={batchFormData.reason}
                    onChange={(e) => setBatchFormData({ ...batchFormData, reason: e.target.value })}
                    placeholder={t('blacklist.reasonPlaceholder')}
                    disabled={isUploading}
                  />
                </div>
                <div className="flex items-start gap-2 p-3 bg-blue-50 rounded-md">
                  <AlertCircle className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
                  <p className="text-sm text-blue-900">
                    {t('blacklist.subscriberStatusNote')}
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
                        {t('common.cancel')}
                      </Button>
                      <Button type="submit" disabled={!selectedFile}>
                        {t('common.startImport')}
                      </Button>
                    </>
                  )}
                  
                  {isUploading && (
                    <Button disabled>
                      <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                      {t('common.importing')}
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
                      {t('common.close')}
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
                {t('common.addSingle')}
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>{t('blacklist.addToBlacklist')}</DialogTitle>
                <DialogDescription>
                  {t('blacklist.addToBlacklistDesc')}
                </DialogDescription>
              </DialogHeader>
              <form onSubmit={handleAdd} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="email">{t('common.emailAddress')} *</Label>
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
                  <Label htmlFor="reason">{t('common.reason')} ({t('common.optional')})</Label>
                  <Input
                    id="reason"
                    value={addFormData.reason}
                    onChange={(e) => setAddFormData({ ...addFormData, reason: e.target.value })}
                    placeholder={t('blacklist.reasonPlaceholder')}
                  />
                </div>
                <div className="flex justify-end gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => setIsAddOpen(false)}
                  >
                    {t('common.cancel')}
                  </Button>
                  <Button type="submit" disabled={addMutation.isPending}>
                    {addMutation.isPending ? t('common.adding') : t('common.add')}
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
              <CardTitle>{t('blacklist.blacklistList')}</CardTitle>
              <CardDescription>{t('blacklist.totalEmails', { count: total })}</CardDescription>
            </div>
            <div className="flex items-center gap-2">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-4 h-4" />
                <Input
                  placeholder={t('blacklist.searchPlaceholder')}
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
                  {t('common.deleteSelected')} ({selectedIds.length})
                </Button>
              )}
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-8 text-muted-foreground">{t('common.loading')}</div>
          ) : blacklist.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              {searchQuery ? t('blacklist.noMatchFound') : t('blacklist.noBlacklist')}
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
                    <TableHead>{t('common.emailAddress')}</TableHead>
                    <TableHead>{t('common.reason')}</TableHead>
                    <TableHead>{t('common.addedAt')}</TableHead>
                    <TableHead className="text-right">{t('common.actions')}</TableHead>
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
                        <div className="truncate">{maskEmail(entry.email)}</div>
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
                    {t('common.page')} {currentPage} {t('common.pageOf')} {totalPages} {t('common.pages')}
                  </div>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(1)}
                      disabled={currentPage === 1}
                    >
                      {t('common.firstPage')}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(currentPage - 1)}
                      disabled={currentPage === 1}
                    >
                      {t('common.prevPage')}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(currentPage + 1)}
                      disabled={currentPage === totalPages}
                    >
                      {t('common.nextPage')}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(totalPages)}
                      disabled={currentPage === totalPages}
                    >
                      {t('common.lastPage')}
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <Dialog open={isProgressOpen} onOpenChange={(open) => {
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
                  {t('blacklist.importInProgress')}
                </>
              )}
              {importProgress?.status === 'completed' && (
                <>
                  <CheckCircle2 className="w-5 h-5 text-green-600" />
                  {t('blacklist.importComplete')}
                </>
              )}
              {importProgress?.status === 'failed' && (
                <>
                  <XCircle className="w-5 h-5 text-red-600" />
                  {t('common.failed')}
                </>
              )}
            </DialogTitle>
            <DialogDescription>
              {importTaskId && (
                <span className="text-xs font-mono">{t('common.id')}: {importTaskId}</span>
              )}
            </DialogDescription>
          </DialogHeader>

          {importProgress && (
            <div className="space-y-4">
              <div className="space-y-2">
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">{t('blacklist.overallProgress')}</span>
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
                  <span>{t('blacklist.batch')} {importProgress.completed_batches} / {importProgress.total_batches}</span>
                  {importProgress.total_emails && (
                    <span>{t('blacklist.totalEmails', { count: importProgress.total_emails.toLocaleString() })}</span>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="p-3 bg-green-50 rounded-lg">
                  <div className="text-xs text-muted-foreground mb-1">{t('common.new')}</div>
                  <div className="text-2xl font-bold text-green-600">
                    {importProgress.added.toLocaleString()}
                  </div>
                </div>
                <div className="p-3 bg-yellow-50 rounded-lg">
                  <div className="text-xs text-muted-foreground mb-1">{t('common.alreadyExists')}</div>
                  <div className="text-2xl font-bold text-yellow-600">
                    {importProgress.already_exists.toLocaleString()}
                  </div>
                </div>
                <div className="p-3 bg-red-50 rounded-lg">
                  <div className="text-xs text-muted-foreground mb-1">{t('common.invalid')}</div>
                  <div className="text-2xl font-bold text-red-600">
                    {importProgress.invalid.toLocaleString()}
                  </div>
                </div>
                <div className="p-3 bg-blue-50 rounded-lg">
                  <div className="text-xs text-muted-foreground mb-1">{t('blacklist.subscribersUpdated')}</div>
                  <div className="text-2xl font-bold text-blue-600">
                    {importProgress.subscribers_updated.toLocaleString()}
                  </div>
                </div>
              </div>

              {importProgress.status === 'failed' && importProgress.error && (
                <div className="p-3 bg-red-50 border border-red-200 rounded-lg">
                  <div className="flex items-start gap-2">
                    <XCircle className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" />
                    <div className="flex-1">
                      <div className="font-medium text-red-900 mb-1">{t('blacklist.errorMessage')}</div>
                      <div className="text-sm text-red-700">{importProgress.error}</div>
                    </div>
                  </div>
                </div>
              )}

              <div className="flex justify-end gap-2">
                {importProgress.status === 'processing' && (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => refetchProgress()}
                  >
                    <Loader2 className="w-4 h-4 mr-2" />
                    {t('blacklist.refreshProgress')}
                  </Button>
                )}
                {(importProgress.status === 'completed' || importProgress.status === 'failed') && (
                  <Button
                    onClick={() => {
                      setIsProgressOpen(false)
                      setImportTaskId(null)
                    }}
                  >
                    {t('common.close')}
                  </Button>
                )}
              </div>

              {importProgress.status === 'processing' && (
                <div className="flex items-start gap-2 p-3 bg-blue-50 rounded-md">
                  <AlertCircle className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
                  <p className="text-sm text-blue-900">
                    {t('blacklist.importBackgroundNote')}
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

