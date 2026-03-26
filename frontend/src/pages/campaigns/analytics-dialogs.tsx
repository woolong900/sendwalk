import { useState } from 'react'
import { useTranslation } from 'react-i18next'
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
import { formatDateTime, maskEmail } from '@/lib/utils'

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
  const { t } = useTranslation()
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
    placeholderData: (previousData) => previousData,
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
            {t('dialogs.sendLogs')} - {campaignName}
          </DialogTitle>
        </DialogHeader>

        <div className="flex gap-2 mb-4">
          <div className="relative flex-1">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder={t('dialogs.searchEmailPlaceholder')}
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
              <SelectItem value="all">{t('dialogs.allStatus')}</SelectItem>
              <SelectItem value="sent">{t('dialogs.sent')}</SelectItem>
              <SelectItem value="failed">{t('dialogs.failed')}</SelectItem>
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
                <TableHead>{t('common.emailAddress')}</TableHead>
                <TableHead>{t('dialogs.sender')}</TableHead>
                <TableHead>{t('dialogs.smtpServer')}</TableHead>
                <TableHead>{t('dialogs.sendTime')}</TableHead>
                <TableHead>{t('common.status')}</TableHead>
                <TableHead>{t('dialogs.result')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                    {t('common.loading')}
                  </TableCell>
                </TableRow>
              ) : !data?.data || data.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                    {t('dialogs.noSendLogs')}
                  </TableCell>
                </TableRow>
              ) : (
                data.data.map((log: any) => {
                  const isExpanded = expandedRows.has(log.id)
                  const hasLongError = log.error_message && log.error_message.length > 50
                  
                  return (
                    <TableRow key={log.id}>
                      <TableCell className="font-mono text-sm whitespace-nowrap" title={maskEmail(log.email)}>
                        <div className="truncate">{maskEmail(log.email)}</div>
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
              {t('common.page')} {data.current_page} {t('common.pageOf')} {data.last_page} {t('common.pages')}
            </div>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(1)}
                disabled={currentPage === 1}
              >
                {t('common.firstPage')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                disabled={currentPage === 1}
              >
                {t('common.prevPage')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.min(data.last_page, p + 1))}
                disabled={currentPage === data.last_page}
              >
                {t('common.nextPage')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(data.last_page)}
                disabled={currentPage === data.last_page}
              >
                {t('common.lastPage')}
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

export function EmailOpensDialog({ campaignId, campaignName, open, onClose }: EmailOpensDialogProps) {
  const { t } = useTranslation()
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
    placeholderData: (previousData) => previousData,
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
            {t('dialogs.emailOpens')} - {campaignName}
          </DialogTitle>
        </DialogHeader>

        {statsData && (
          <div className="grid grid-cols-4 gap-4 p-4 bg-muted/50 rounded-lg mb-4">
            <div className="text-center">
              <div className="text-2xl font-bold">{statsData.total_opens}</div>
              <div className="text-xs text-muted-foreground">{t('dialogs.totalOpens')}</div>
            </div>
            <div className="text-center">
              <div className="text-2xl font-bold">{statsData.unique_opens}</div>
              <div className="text-xs text-muted-foreground">{t('dialogs.uniqueOpens')}</div>
            </div>
            <div className="text-center">
              <div className="text-2xl font-bold">{statsData.avg_opens_per_person}</div>
              <div className="text-xs text-muted-foreground">{t('dialogs.avgOpensPerPerson')}</div>
            </div>
            <div className="text-center">
              <div className="text-2xl font-bold">{statsData.open_rate}%</div>
              <div className="text-xs text-muted-foreground">{t('campaigns.openRate')}</div>
            </div>
          </div>
        )}

        <div className="flex gap-2 mb-4">
          <div className="relative flex-1">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder={t('dialogs.searchEmailPlaceholder')}
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
          <Table className="min-w-[1110px]">
            <colgroup>
              <col className="w-[250px]" />
              <col className="w-[100px]" />
              <col className="w-[130px]" />
              <col className="w-[100px]" />
              <col className="w-[300px]" />
              <col className="w-[160px]" />
            </colgroup>
            <TableHeader>
              <TableRow>
                <TableHead>{t('common.emailAddress')}</TableHead>
                <TableHead>{t('dialogs.openCount')}</TableHead>
                <TableHead>{t('dialogs.ipAddress')}</TableHead>
                <TableHead>{t('dialogs.countryRegion')}</TableHead>
                <TableHead>{t('dialogs.userAgent')}</TableHead>
                <TableHead>{t('dialogs.openTime')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoadingOpens ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                    {t('common.loading')}
                  </TableCell>
                </TableRow>
              ) : !opensData?.data || opensData.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                    {t('dialogs.noEmailOpens')}
                  </TableCell>
                </TableRow>
              ) : (
                <>
                  {opensData.data.map((open: any) => {
                    return (
                      <>
                        <TableRow key={open.email} className="hover:bg-muted/30">
                          <TableCell className="font-mono text-sm whitespace-nowrap" title={maskEmail(open.email)}>
                            <div className="truncate">{maskEmail(open.email)}</div>
                          </TableCell>
                          <TableCell className="whitespace-nowrap">
                            {open.open_count > 1 ? (
                              <button
                                onClick={() => fetchEmailDetails(open.email)}
                                className="text-sm font-medium text-primary hover:underline cursor-pointer flex items-center gap-1"
                              >
                                {open.open_count} {t('dialogs.timesUnit')}
                                {loadingDetails && expandedEmail === open.email ? (
                                  <span className="text-xs text-muted-foreground ml-1">...</span>
                                ) : expandedEmail === open.email ? (
                                  <EyeOff className="w-3 h-3" />
                                ) : (
                                  <Eye className="w-3 h-3" />
                                )}
                              </button>
                            ) : (
                              <span className="text-sm text-muted-foreground">{open.open_count} {t('dialogs.timesUnit')}</span>
                            )}
                          </TableCell>
                          <TableCell className="font-mono text-xs whitespace-nowrap">
                            {open.first_ip_address || '-'}
                          </TableCell>
                          <TableCell className="text-xs whitespace-nowrap">
                            {open.first_country_name ? (
                              <span className="inline-flex items-center gap-1">
                                <span className="text-muted-foreground">{open.first_country_code}</span>
                                <span>{open.first_country_name}</span>
                              </span>
                            ) : (
                              <span className="text-muted-foreground">-</span>
                            )}
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
                                  └ {t('dialogs.nthOpen', { n: idx + 2 })}
                                </TableCell>
                                <TableCell></TableCell>
                                <TableCell className="font-mono text-xs whitespace-nowrap">
                                  {detail.ip_address || '-'}
                                </TableCell>
                                <TableCell className="text-xs whitespace-nowrap">
                                  {detail.country_name ? (
                                    <span className="inline-flex items-center gap-1">
                                      <span className="text-muted-foreground">{detail.country_code}</span>
                                      <span>{detail.country_name}</span>
                                    </span>
                                  ) : (
                                    <span className="text-muted-foreground">-</span>
                                  )}
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
              {t('dialogs.totalEmails', { total: opensData.total })}, {t('dialogs.pageInfo', { current: opensData.current_page, total: opensData.last_page })}
            </div>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(1)}
                disabled={currentPage === 1}
              >
                {t('common.firstPage')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                disabled={currentPage === 1}
              >
                {t('common.prevPage')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.min(opensData.last_page, p + 1))}
                disabled={currentPage === opensData.last_page}
              >
                {t('common.nextPage')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(opensData.last_page)}
                disabled={currentPage === opensData.last_page}
              >
                {t('common.lastPage')}
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

export function AbuseReportsDialog({ campaignId, campaignName, open, onClose }: AbuseReportsDialogProps) {
  const { t } = useTranslation()
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
            {t('dialogs.abuseReports')} - {campaignName}
          </DialogTitle>
        </DialogHeader>

        <div className="flex gap-2 mb-4">
          <div className="relative flex-1">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder={t('dialogs.searchEmailPlaceholder')}
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
            <div className="p-8 text-center text-muted-foreground">{t('common.loading')}</div>
          ) : !data || data.data.length === 0 ? (
            <div className="p-8 text-center text-muted-foreground">
              {searchTerm ? t('dialogs.noMatchingAbuseReports') : t('dialogs.noAbuseReports')}
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
                  <TableHead className="whitespace-nowrap">{t('common.emailAddress')}</TableHead>
                  <TableHead className="whitespace-nowrap">{t('dialogs.complaintReason')}</TableHead>
                  <TableHead className="whitespace-nowrap">{t('dialogs.ipAddress')}</TableHead>
                  <TableHead className="whitespace-nowrap">{t('dialogs.reportTime')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.data.map((report: any, index: number) => (
                  <TableRow key={index}>
                    <TableCell className="whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <Mail className="w-4 h-4 text-muted-foreground flex-shrink-0" />
                        <div className="truncate">{maskEmail(report.email)}</div>
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
              {t('dialogs.showingRange', { from: data.from || 0, to: data.to || 0, total: data.total })}
            </div>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(1)}
                disabled={currentPage === 1}
              >
                {t('common.firstPage')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                disabled={currentPage === 1}
              >
                {t('common.prevPage')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(p => Math.min(data.last_page, p + 1))}
                disabled={currentPage === data.last_page}
              >
                {t('common.nextPage')}
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => setCurrentPage(data.last_page)}
                disabled={currentPage === data.last_page}
              >
                {t('common.lastPage')}
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

