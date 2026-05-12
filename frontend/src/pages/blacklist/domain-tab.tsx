import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, Search } from 'lucide-react'
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

interface DomainBlacklistEntry {
  id: number
  domain: string
  reason: string | null
  created_at: string
}

interface PaginatedResponse {
  data: DomainBlacklistEntry[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export default function DomainBlacklistTab() {
  const { t } = useTranslation()
  const { confirm, ConfirmDialog } = useConfirm()
  const queryClient = useQueryClient()

  const [isAddOpen, setIsAddOpen] = useState(false)
  const [isBatchAddOpen, setIsBatchAddOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [selectedIds, setSelectedIds] = useState<number[]>([])

  const [addFormData, setAddFormData] = useState({
    domain: '',
    reason: '',
  })

  const [batchFormData, setBatchFormData] = useState({
    domains: '',
    reason: '',
  })

  const { data: listData, isLoading } = useQuery<PaginatedResponse>({
    queryKey: ['domain-blacklist', currentPage, searchQuery],
    queryFn: async () => {
      const params = new URLSearchParams({
        page: currentPage.toString(),
        per_page: '15',
      })
      if (searchQuery) params.append('search', searchQuery)
      const response = await api.get(`/domain-blacklist?${params}`)
      return response.data
    },
  })

  const addMutation = useMutation({
    mutationFn: async (data: typeof addFormData) => {
      return api.post('/domain-blacklist', data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['domain-blacklist'] })
      toast.success(t('domainBlacklist.addSuccess'))
      setIsAddOpen(false)
      setAddFormData({ domain: '', reason: '' })
    },
    onError: (error: any) => {
      const message = error?.response?.data?.message || t('common.error')
      toast.error(message)
    },
  })

  const batchAddMutation = useMutation({
    mutationFn: async (data: { domains: string[]; reason: string }) => {
      return api.post('/domain-blacklist/batch', data)
    },
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['domain-blacklist'] })
      const { added, already_exists, invalid } = response.data
      const messages = []
      if (added > 0) messages.push(`${t('common.new')} ${added}`)
      if (already_exists > 0) messages.push(`${t('common.alreadyExists')} ${already_exists}`)
      if (invalid > 0) messages.push(`${t('common.invalid')} ${invalid}`)
      toast.success(messages.join(', ') || t('domainBlacklist.addSuccess'))
      setIsBatchAddOpen(false)
      setBatchFormData({ domains: '', reason: '' })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/domain-blacklist/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['domain-blacklist'] })
      toast.success(t('domainBlacklist.removeSuccess'))
    },
  })

  const batchDeleteMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      return api.post('/domain-blacklist/batch-delete', { ids })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['domain-blacklist'] })
      setSelectedIds([])
      toast.success(t('domainBlacklist.batchDeleteSuccess'))
    },
  })

  const handleAdd = (e: React.FormEvent) => {
    e.preventDefault()
    addMutation.mutate(addFormData)
  }

  const handleBatchAdd = (e: React.FormEvent) => {
    e.preventDefault()
    const domains = batchFormData.domains
      .split(/[\n,]/)
      .map((d) => d.trim())
      .filter((d) => d.length > 0)

    if (domains.length === 0) {
      toast.error(t('domainBlacklist.emptyDomains'))
      return
    }

    batchAddMutation.mutate({
      domains,
      reason: batchFormData.reason,
    })
  }

  const handleDelete = async (id: number) => {
    const confirmed = await confirm({
      title: t('domainBlacklist.removeConfirm'),
      description: t('domainBlacklist.removeConfirmDesc'),
    })
    if (confirmed) deleteMutation.mutate(id)
  }

  const handleBatchDelete = async () => {
    if (selectedIds.length === 0) return

    const confirmed = await confirm({
      title: t('domainBlacklist.batchDeleteConfirm'),
      description: t('domainBlacklist.batchDeleteConfirmDesc', { count: selectedIds.length }),
    })
    if (confirmed) batchDeleteMutation.mutate(selectedIds)
  }

  const handleSelectAll = (checked: boolean) => {
    if (checked && listData?.data) {
      setSelectedIds(listData.data.map((item) => item.id))
    } else {
      setSelectedIds([])
    }
  }

  const handleSelectOne = (id: number, checked: boolean) => {
    if (checked) {
      setSelectedIds([...selectedIds, id])
    } else {
      setSelectedIds(selectedIds.filter((s) => s !== id))
    }
  }

  const list = listData?.data || []
  const totalPages = listData?.last_page || 1
  const total = listData?.total || 0

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-end gap-2">
        <Dialog open={isBatchAddOpen} onOpenChange={setIsBatchAddOpen}>
          <DialogTrigger asChild>
            <Button>
              <Plus className="w-4 h-4 mr-2" />
              {t('domainBlacklist.batchAdd')}
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>{t('domainBlacklist.batchAdd')}</DialogTitle>
              <DialogDescription>
                {t('domainBlacklist.batchAddDesc')}
              </DialogDescription>
            </DialogHeader>
            <form onSubmit={handleBatchAdd} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="batch-domains">{t('domainBlacklist.domains')} *</Label>
                <textarea
                  id="batch-domains"
                  value={batchFormData.domains}
                  onChange={(e) => setBatchFormData({ ...batchFormData, domains: e.target.value })}
                  placeholder="gmail.com&#10;yahoo.com&#10;hotmail.com"
                  rows={6}
                  className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                  required
                />
                <p className="text-xs text-muted-foreground">
                  {t('domainBlacklist.batchAddTip')}
                </p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="batch-reason">
                  {t('common.reason')} ({t('common.optional')})
                </Label>
                <Input
                  id="batch-reason"
                  value={batchFormData.reason}
                  onChange={(e) => setBatchFormData({ ...batchFormData, reason: e.target.value })}
                  placeholder={t('blacklist.reasonPlaceholder')}
                />
              </div>
              <div className="flex justify-end gap-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setIsBatchAddOpen(false)}
                >
                  {t('common.cancel')}
                </Button>
                <Button type="submit" disabled={batchAddMutation.isPending}>
                  {batchAddMutation.isPending ? t('common.adding') : t('common.add')}
                </Button>
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
              <DialogTitle>{t('domainBlacklist.addToBlacklist')}</DialogTitle>
              <DialogDescription>
                {t('domainBlacklist.addToBlacklistDesc')}
              </DialogDescription>
            </DialogHeader>
            <form onSubmit={handleAdd} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="domain">{t('domainBlacklist.domain')} *</Label>
                <Input
                  id="domain"
                  value={addFormData.domain}
                  onChange={(e) => setAddFormData({ ...addFormData, domain: e.target.value })}
                  placeholder="example.com"
                  required
                />
                <p className="text-xs text-muted-foreground">
                  {t('domainBlacklist.domainTip')}
                </p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="domain-reason">
                  {t('common.reason')} ({t('common.optional')})
                </Label>
                <Input
                  id="domain-reason"
                  value={addFormData.reason}
                  onChange={(e) => setAddFormData({ ...addFormData, reason: e.target.value })}
                  placeholder={t('blacklist.reasonPlaceholder')}
                />
              </div>
              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={() => setIsAddOpen(false)}>
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

      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>{t('domainBlacklist.listTitle')}</CardTitle>
              <CardDescription>{t('domainBlacklist.totalDomains', { count: total })}</CardDescription>
            </div>
            <div className="flex items-center gap-2">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-4 h-4" />
                <Input
                  placeholder={t('domainBlacklist.searchPlaceholder')}
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="pl-9 w-64"
                />
              </div>
              {selectedIds.length > 0 && (
                <Button variant="destructive" size="sm" onClick={handleBatchDelete}>
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
          ) : list.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              {searchQuery ? t('blacklist.noMatchFound') : t('domainBlacklist.noDomains')}
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
                          checked={selectedIds.length === list.length && list.length > 0}
                          onChange={(e) => handleSelectAll(e.target.checked)}
                          className="rounded border-gray-300"
                        />
                      </TableHead>
                      <TableHead>{t('domainBlacklist.domain')}</TableHead>
                      <TableHead>{t('common.reason')}</TableHead>
                      <TableHead>{t('common.addedAt')}</TableHead>
                      <TableHead className="text-right">{t('common.actions')}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {list.map((entry) => (
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
                          <div className="truncate">{entry.domain}</div>
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
                          <Button variant="ghost" size="sm" onClick={() => handleDelete(entry.id)}>
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

      <ConfirmDialog />
    </div>
  )
}
