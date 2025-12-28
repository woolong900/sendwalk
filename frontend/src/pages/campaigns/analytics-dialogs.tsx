import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FileText, Mail, Search, Eye, EyeOff, AlertOctagon } from 'lucide-react'
import {
  Dialog,
  DialogContent,
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
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { api } from '@/lib/api'
import { formatDateTime } from '@/lib/utils'

interface SendLogsDialogProps {
  campaignId: number | null
  campaignName: string
  open: boolean
  onClose: () => void
}

interface EmailOpensDialogProps {
  campaignId: number | null
  campaignName: string
  open: boolean
  onClose: () => void
}

interface AbuseReportsDialogProps {
  campaignId: number | null
  campaignName: string
  open: boolean
  onClose: () => void
}

export function SendLogsDialog({ campaignId, campaignName, open, onClose }: SendLogsDialogProps) {
  const [searchTerm, setSearchTerm] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [currentPage, setCurrentPage] = useState(1)
  const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set())

  const { data, isLoading } = useQuery({
    queryKey: ['campaign-send-logs', campaignId, searchTerm, statusFilter, currentPage],
    queryFn: async () => {
      if (!campaignId) return null
      const response = await api.get(`/campaigns/${campaignId}/send-logs`, {
        params: {
          search: searchTerm || undefined,
          status: statusFilter !== 'all' ? statusFilter : undefined,
          page: currentPage,
          per_page: 50,
        },
      })
      return response.data
    },
    enabled: open && !!campaignId,
    placeholderData: (previousData) => previousData, // 保持之前的数据，避免闪烁
  })

  const toggleExpanded = (logId: number) => {
    setExpandedRows(prev => {
      const newSet = new Set(prev)
      if (newSet.has(logId)) {
        newSet.delete(logId)
      } else {
        newSet.add(logId)
      }
      return newSet
    })
  }

  const truncateText = (text: string | null, maxLength: number = 50) => {
    if (!text) return '-'
    if (text.length <= maxLength) return text
    return text.substring(0, maxLength) + '...'
  }

  const handleClose = () => {
    setCurrentPage(1)
    setSearchTerm('')
    setStatusFilter('all')
    setExpandedRows(new Set())
    onClose()
  }

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && handleClose()}>
      <DialogContent className="max-w-6xl max-h-[90vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <FileText className="w-5 h-5" />
            发送日志 - {campaignName}
          </DialogTitle>
        </DialogHeader>

        <div className="flex gap-2 mb-4">
          <div className="relative flex-1">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="搜索邮箱地址..."
              value={searchTerm}
              onChange={(e) => {
                setSearchTerm(e.target.value)
                setCurrentPage(1)
              }}
              className="pl-8"
            />
          </div>
          <Select
            value={statusFilter}
            onValueChange={(value) => {
              setStatusFilter(value)
              setCurrentPage(1)
            }}
          >
            <SelectTrigger className="w-[150px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">全部状态</SelectItem>
              <SelectItem value="sent">已发送</SelectItem>
              <SelectItem value="failed">失败</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="flex-1 overflow-auto border rounded-lg">
          <Table className="min-w-[1100px]">
            <colgroup>
              <col className="w-[220px]" />
              <col className="w-[180px]" />
              <col className="w-[120px]" />
              <col className="w-[160px]" />
              <col className="w-[80px]" />
              <col className="w-[340px]" />
            </colgroup>
            <TableHeader>
              <TableRow>
                <TableHead>邮箱地址</TableHead>
                <TableHead>发件人</TableHead>
                <TableHead>SMTP服务器</TableHead>
                <TableHead>发送时间</TableHead>
                <TableHead>状态</TableHead>
                <TableHead>结果</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                    加载中...
                  </TableCell>
                </TableRow>
              ) : !data?.data || data.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                    暂无发送记录
                  </TableCell>
                </TableRow>
              ) : (
                data.data.map((log: any) => {
                  const isExpanded = expandedRows.has(log.id)
                  const hasLongError = log.error_message && log.error_message.length > 50
                  
                  return (
                    <TableRow key={log.id}>
                      <TableCell className="font-mono text-sm whitespace-nowrap" title={log.email}>
                        <div className="truncate">{log.email}</div>
                      </TableCell>
                      <TableCell className="font-mono text-sm whitespace-nowrap" title={log.from_email}>
                        <div className="truncate">{log.from_email || '-'}</div>
                      </TableCell>
                      <TableCell className="whitespace-nowrap">{log.smtp_server?.name || '-'}</TableCell>
                      <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                        {formatDateTime(log.created_at)}
                      </TableCell>
                      <TableCell className="text-sm whitespace-nowrap">{log.status}</TableCell>
                      <TableCell className="whitespace-nowrap">
                        {log.status === 'sent' ? (
                          <span className="text-sm text-green-600 font-medium">OK</span>
                        ) : log.error_message ? (
                          <div className="flex items-start gap-2">
                            <div className="flex-1 text-sm text-destructive">
                              {isExpanded ? log.error_message : truncateText(log.error_message)}
                            </div>
                            {hasLongError && (
                              <Button
                                size="sm"
                                variant="ghost"
                                className="h-6 px-2"
                                onClick={() => toggleExpanded(log.id)}
                              >
                                {isExpanded ? <EyeOff className="w-3 h-3" /> : <Eye className="w-3 h-3" />}
                              </Button>
                            )}
                          </div>
                        ) : (
                          <span className="text-sm text-muted-foreground">-</span>
                        )}
                      </TableCell>
                    </TableRow>
                  )
                })
              )}
            </TableBody>
          </Table>
        </div>

        {data && data.last_page > 1 && (
          <div className="flex items-center justify-between pt-4 border-t">
            <div className="text-sm text-muted-foreground">
              共 {data.total} 条记录，第 {data.current_page} / {data.last_page} 页
            </div>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(1)}
                disabled={currentPage === 1}
              >
                首页
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                disabled={currentPage === 1}
              >
                上一页
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.min(data.last_page, p + 1))}
                disabled={currentPage === data.last_page}
              >
                下一页
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(data.last_page)}
                disabled={currentPage === data.last_page}
              >
                尾页
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

export function EmailOpensDialog({ campaignId, campaignName, open, onClose }: EmailOpensDialogProps) {
  const [searchTerm, setSearchTerm] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [expandedEmail, setExpandedEmail] = useState<string | null>(null)
  const [emailDetails, setEmailDetails] = useState<any[]>([])
  const [loadingDetails, setLoadingDetails] = useState(false)

  const { data: opensData, isLoading: isLoadingOpens } = useQuery({
    queryKey: ['campaign-email-opens', campaignId, searchTerm, currentPage],
    queryFn: async () => {
      if (!campaignId) return null
      const response = await api.get(`/campaigns/${campaignId}/email-opens`, {
        params: {
          search: searchTerm || undefined,
          page: currentPage,
          per_page: 50,
        },
      })
      return response.data
    },
    enabled: open && !!campaignId,
    placeholderData: (previousData) => previousData, // 保持之前的数据，避免闪烁
  })

  const fetchEmailDetails = async (email: string) => {
    if (expandedEmail === email) {
      setExpandedEmail(null)
      setEmailDetails([])
      return
    }

    setLoadingDetails(true)
    try {
      const response = await api.get(`/campaigns/${campaignId}/email-open-details`, {
        params: { email },
      })
      setEmailDetails(response.data.data)
      setExpandedEmail(email)
    } catch (error) {
      console.error('Failed to fetch email details:', error)
    } finally {
      setLoadingDetails(false)
    }
  }

  const { data: statsData } = useQuery({
    queryKey: ['campaign-open-stats', campaignId],
    queryFn: async () => {
      if (!campaignId) return null
      const response = await api.get(`/campaigns/${campaignId}/open-stats`)
      return response.data.data
    },
    enabled: open && !!campaignId,
  })

  const handleClose = () => {
    setCurrentPage(1)
    setSearchTerm('')
    setExpandedEmail(null)
    setEmailDetails([])
    onClose()
  }

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && handleClose()}>
      <DialogContent className="max-w-6xl max-h-[90vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Mail className="w-5 h-5" />
            打开记录 - {campaignName}
          </DialogTitle>
        </DialogHeader>

        {statsData && (
          <div className="grid grid-cols-4 gap-4 p-4 bg-muted/50 rounded-lg mb-4">
            <div className="text-center">
              <div className="text-2xl font-bold">{statsData.total_opens}</div>
              <div className="text-xs text-muted-foreground">总打开次数</div>
            </div>
            <div className="text-center">
              <div className="text-2xl font-bold">{statsData.unique_opens}</div>
              <div className="text-xs text-muted-foreground">独立打开人数</div>
            </div>
            <div className="text-center">
              <div className="text-2xl font-bold">{statsData.avg_opens_per_person}</div>
              <div className="text-xs text-muted-foreground">人均打开次数</div>
            </div>
            <div className="text-center">
              <div className="text-2xl font-bold">{statsData.open_rate}%</div>
              <div className="text-xs text-muted-foreground">打开率</div>
            </div>
          </div>
        )}

        <div className="flex gap-2 mb-4">
          <div className="relative flex-1">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="搜索邮箱地址..."
              value={searchTerm}
              onChange={(e) => {
                setSearchTerm(e.target.value)
                setCurrentPage(1)
              }}
              className="pl-8"
            />
          </div>
        </div>

        <div className="flex-1 overflow-auto border rounded-lg">
          <Table className="min-w-[1010px]">
            <colgroup>
              <col className="w-[250px]" />
              <col className="w-[100px]" />
              <col className="w-[150px]" />
              <col className="w-[350px]" />
              <col className="w-[160px]" />
            </colgroup>
            <TableHeader>
              <TableRow>
                <TableHead>邮箱地址</TableHead>
                <TableHead>打开次数</TableHead>
                <TableHead>IP地址</TableHead>
                <TableHead>User Agent</TableHead>
                <TableHead>打开时间</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoadingOpens ? (
                <TableRow>
                  <TableCell colSpan={5} className="text-center py-8 text-muted-foreground">
                    加载中...
                  </TableCell>
                </TableRow>
              ) : !opensData?.data || opensData.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5} className="text-center py-8 text-muted-foreground">
                    暂无打开记录
                  </TableCell>
                </TableRow>
              ) : (
                <>
                  {opensData.data.map((open: any) => {
                    return (
                      <>
                        <TableRow key={open.email} className="hover:bg-muted/30">
                          <TableCell className="font-mono text-sm whitespace-nowrap" title={open.email}>
                            <div className="truncate">{open.email}</div>
                          </TableCell>
                          <TableCell className="whitespace-nowrap">
                            {open.open_count > 1 ? (
                              <button
                                onClick={() => fetchEmailDetails(open.email)}
                                className="text-sm font-medium text-primary hover:underline cursor-pointer flex items-center gap-1"
                              >
                                {open.open_count} 次
                                {loadingDetails && expandedEmail === open.email ? (
                                  <span className="text-xs text-muted-foreground ml-1">...</span>
                                ) : expandedEmail === open.email ? (
                                  <EyeOff className="w-3 h-3" />
                                ) : (
                                  <Eye className="w-3 h-3" />
                                )}
                              </button>
                            ) : (
                              <span className="text-sm text-muted-foreground">{open.open_count} 次</span>
                            )}
                          </TableCell>
                          <TableCell className="font-mono text-xs whitespace-nowrap">
                            {open.first_ip_address || '-'}
                          </TableCell>
                          <TableCell className="text-xs text-muted-foreground whitespace-nowrap" title={open.first_user_agent}>
                            <div className="truncate">{open.first_user_agent || '-'}</div>
                          </TableCell>
                          <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                            {formatDateTime(open.first_opened_at)}
                          </TableCell>
                        </TableRow>
                        {expandedEmail === open.email && emailDetails.length > 0 && (
                          <>
                            {emailDetails.map((detail: any, idx: number) => (
                              <TableRow key={detail.id} className="bg-muted/10">
                                <TableCell className="pl-8 text-xs text-muted-foreground whitespace-nowrap">
                                  └ 第 {idx + 2} 次打开
                                </TableCell>
                                <TableCell></TableCell>
                                <TableCell className="font-mono text-xs whitespace-nowrap">
                                  {detail.ip_address || '-'}
                                </TableCell>
                                <TableCell className="text-xs text-muted-foreground truncate" title={detail.user_agent}>
                                  {detail.user_agent || '-'}
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                                  {formatDateTime(detail.opened_at)}
                                </TableCell>
                              </TableRow>
                            ))}
                          </>
                        )}
                      </>
                    )
                  })}
                </>
              )}
            </TableBody>
          </Table>
        </div>

        {opensData && opensData.last_page > 1 && (
          <div className="flex items-center justify-between pt-4 border-t">
            <div className="text-sm text-muted-foreground">
              共 {opensData.total} 个邮箱，第 {opensData.current_page} / {opensData.last_page} 页
            </div>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(1)}
                disabled={currentPage === 1}
              >
                首页
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                disabled={currentPage === 1}
              >
                上一页
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.min(opensData.last_page, p + 1))}
                disabled={currentPage === opensData.last_page}
              >
                下一页
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(opensData.last_page)}
                disabled={currentPage === opensData.last_page}
              >
                尾页
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

export function AbuseReportsDialog({ campaignId, campaignName, open, onClose }: AbuseReportsDialogProps) {
  const [searchTerm, setSearchTerm] = useState('')
  const [currentPage, setCurrentPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['campaign-abuse-reports', campaignId, searchTerm, currentPage],
    queryFn: async () => {
      if (!campaignId) return null
      const response = await api.get(`/campaigns/${campaignId}/abuse-reports`, {
        params: {
          search: searchTerm || undefined,
          page: currentPage,
          per_page: 50,
        },
      })
      return response.data
    },
    enabled: open && !!campaignId,
    placeholderData: (previousData) => previousData,
  })

  const handleClose = () => {
    setCurrentPage(1)
    setSearchTerm('')
    onClose()
  }

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && handleClose()}>
      <DialogContent className="max-w-5xl max-h-[90vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <AlertOctagon className="w-5 h-5 text-red-500" />
            投诉报告 - {campaignName}
          </DialogTitle>
        </DialogHeader>

        <div className="flex gap-2 mb-4">
          <div className="relative flex-1">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="搜索邮箱地址..."
              value={searchTerm}
              onChange={(e) => {
                setSearchTerm(e.target.value)
                setCurrentPage(1)
              }}
              className="pl-8"
            />
          </div>
        </div>

        <div className="flex-1 overflow-auto border rounded-lg">
          {isLoading ? (
            <div className="p-8 text-center text-muted-foreground">加载中...</div>
          ) : !data || data.data.length === 0 ? (
            <div className="p-8 text-center text-muted-foreground">
              {searchTerm ? '未找到匹配的投诉记录' : '暂无投诉记录'}
            </div>
          ) : (
            <Table className="min-w-[800px]">
              <colgroup>
                <col className="w-[250px]" />
                <col className="w-[200px]" />
                <col className="w-[200px]" />
                <col className="w-[150px]" />
              </colgroup>
              <TableHeader>
                <TableRow>
                  <TableHead className="whitespace-nowrap">邮箱地址</TableHead>
                  <TableHead className="whitespace-nowrap">投诉原因</TableHead>
                  <TableHead className="whitespace-nowrap">IP 地址</TableHead>
                  <TableHead className="whitespace-nowrap">投诉时间</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.data.map((report: any, index: number) => (
                  <TableRow key={index}>
                    <TableCell className="whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <Mail className="w-4 h-4 text-muted-foreground flex-shrink-0" />
                        <div className="truncate">{report.email}</div>
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <div className="truncate">
                        {report.reason || '-'}
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <code className="text-xs bg-muted px-2 py-1 rounded">
                        {report.ip_address || '-'}
                      </code>
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {formatDateTime(report.created_at)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </div>

        {data && data.last_page > 1 && (
          <div className="flex items-center justify-between pt-4">
            <div className="text-sm text-muted-foreground">
              第 {data.from || 0} - {data.to || 0} 条，共 {data.total} 条
            </div>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(1)}
                disabled={currentPage === 1}
              >
                首页
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                disabled={currentPage === 1}
              >
                上一页
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.min(data.last_page, p + 1))}
                disabled={currentPage === data.last_page}
              >
                下一页
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(data.last_page)}
                disabled={currentPage === data.last_page}
              >
                尾页
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

