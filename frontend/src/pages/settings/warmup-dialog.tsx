import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Flame, RefreshCw } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
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
import { api } from '@/lib/api'
import { useConfirm } from '@/hooks/use-confirm'

interface WarmupScheduleStep {
  day: number
  limit: number | null
}

interface WarmupStatus {
  smtp_server_id: number
  domain: string
  enabled: boolean
  started_at: string | null
  current_day: number | null
  today_limit: number | null
  today_sent: number
  today_remaining: number | null
  schedule: WarmupScheduleStep[]
  is_default_schedule: boolean
  completed: boolean
}

interface WarmupDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  serverId: number
  serverName: string
}

export default function WarmupDialog({ open, onOpenChange, serverId, serverName }: WarmupDialogProps) {
  const { t } = useTranslation()
  const { confirm, ConfirmDialog } = useConfirm()
  const queryClient = useQueryClient()

  const { data, isLoading, refetch } = useQuery<{ data: WarmupStatus[] }>({
    queryKey: ['warmups', serverId],
    queryFn: async () => {
      const response = await api.get(`/smtp-servers/${serverId}/warmups`)
      return response.data
    },
    enabled: open,
    refetchInterval: open ? 10000 : false, // 打开时每 10s 刷新一次实时计数
  })

  useEffect(() => {
    if (open) {
      queryClient.invalidateQueries({ queryKey: ['warmups', serverId] })
    }
  }, [open, serverId, queryClient])

  const updateMutation = useMutation({
    mutationFn: async (params: {
      domain: string
      enabled: boolean
      reset_started_at?: boolean
    }) => {
      const { domain, ...body } = params
      return api.put(`/smtp-servers/${serverId}/warmups/${domain}`, body)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warmups', serverId] })
      toast.success(t('warmup.updateSuccess'))
    },
    onError: (e: any) => {
      toast.error(e?.response?.data?.message || t('common.error'))
    },
  })

  const handleToggle = async (status: WarmupStatus, nextEnabled: boolean) => {
    if (nextEnabled && !status.started_at) {
      const confirmed = await confirm({
        title: t('warmup.startConfirmTitle'),
        description: t('warmup.startConfirmDesc', { domain: status.domain }),
      })
      if (!confirmed) return
    }
    updateMutation.mutate({ domain: status.domain, enabled: nextEnabled })
  }

  const handleReset = async (status: WarmupStatus) => {
    const confirmed = await confirm({
      title: t('warmup.resetConfirmTitle'),
      description: t('warmup.resetConfirmDesc', { domain: status.domain }),
    })
    if (confirmed) {
      updateMutation.mutate({ domain: status.domain, enabled: true, reset_started_at: true })
    }
  }

  const list = data?.data || []

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-4xl max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Flame className="w-5 h-5 text-orange-500" />
            {t('warmup.title')} — {serverName}
          </DialogTitle>
          <DialogDescription>
            {t('warmup.description')}
          </DialogDescription>
        </DialogHeader>

        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            {t('warmup.totalDomains', { count: list.length })}
          </p>
          <Button
            variant="outline"
            size="sm"
            onClick={() => refetch()}
            disabled={isLoading}
          >
            <RefreshCw className={`w-4 h-4 mr-1 ${isLoading ? 'animate-spin' : ''}`} />
            {t('warmup.refresh')}
          </Button>
        </div>

        {isLoading ? (
          <div className="text-center py-8 text-muted-foreground">{t('common.loading')}</div>
        ) : list.length === 0 ? (
          <div className="text-center py-8 text-muted-foreground">
            {t('warmup.noDomains')}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t('warmup.domain')}</TableHead>
                  <TableHead>{t('warmup.status')}</TableHead>
                  <TableHead>{t('warmup.currentDay')}</TableHead>
                  <TableHead>{t('warmup.todayProgress')}</TableHead>
                  <TableHead>{t('warmup.startedAt')}</TableHead>
                  <TableHead className="text-right">{t('common.actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {list.map((status) => (
                  <TableRow key={status.domain}>
                    <TableCell className="font-mono text-sm">{status.domain}</TableCell>
                    <TableCell>
                      {status.completed ? (
                        <Badge className="bg-green-600">{t('warmup.statusCompleted')}</Badge>
                      ) : status.enabled ? (
                        <Badge className="bg-orange-500">{t('warmup.statusWarming')}</Badge>
                      ) : (
                        <Badge variant="secondary">{t('warmup.statusDisabled')}</Badge>
                      )}
                    </TableCell>
                    <TableCell>
                      {status.enabled && status.current_day ? (
                        <span className="text-sm">
                          {t('warmup.dayLabel', { day: status.current_day })}
                        </span>
                      ) : (
                        <span className="text-muted-foreground text-sm">-</span>
                      )}
                    </TableCell>
                    <TableCell>
                      {status.enabled ? (
                        status.today_limit === null ? (
                          <span className="text-sm text-green-700">
                            {t('warmup.unlimited')} ({status.today_sent.toLocaleString()})
                          </span>
                        ) : (
                          <div className="flex flex-col gap-1 min-w-[140px]">
                            <div className="flex justify-between text-xs">
                              <span className="font-medium">
                                {status.today_sent.toLocaleString()} / {status.today_limit.toLocaleString()}
                              </span>
                              <span className="text-muted-foreground">
                                {Math.min(100, Math.round((status.today_sent / status.today_limit) * 100))}%
                              </span>
                            </div>
                            <div className="w-full bg-gray-200 rounded-full h-1.5">
                              <div
                                className={`h-1.5 rounded-full ${
                                  status.today_sent >= status.today_limit
                                    ? 'bg-red-500'
                                    : status.today_sent / status.today_limit > 0.8
                                    ? 'bg-yellow-500'
                                    : 'bg-orange-500'
                                }`}
                                style={{
                                  width: `${Math.min(100, (status.today_sent / status.today_limit) * 100)}%`,
                                }}
                              />
                            </div>
                          </div>
                        )
                      ) : (
                        <span className="text-muted-foreground text-sm">-</span>
                      )}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {status.started_at ? new Date(status.started_at).toLocaleDateString() : '-'}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-2">
                        {status.enabled && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => handleReset(status)}
                            disabled={updateMutation.isPending}
                            title={t('warmup.reset')}
                          >
                            <RefreshCw className="w-3 h-3" />
                          </Button>
                        )}
                        <Button
                          size="sm"
                          variant={status.enabled ? 'destructive' : 'default'}
                          onClick={() => handleToggle(status, !status.enabled)}
                          disabled={updateMutation.isPending}
                        >
                          {status.enabled ? t('warmup.disable') : t('warmup.enable')}
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}

        {/* 阶梯说明 */}
        {list.length > 0 && list[0].schedule && list[0].schedule.length > 0 && (
          <div className="border-t pt-4">
            <h4 className="text-sm font-semibold mb-2">{t('warmup.scheduleTitle')}</h4>
            <div className="flex flex-wrap gap-1.5 text-xs">
              {list[0].schedule.map((step) => (
                <Badge key={step.day} variant="outline">
                  {t('warmup.dayLabel', { day: step.day })}: {step.limit === null ? t('warmup.unlimited') : step.limit.toLocaleString()}
                </Badge>
              ))}
              <Badge variant="outline" className="bg-green-50">
                {t('warmup.dayAfter', { day: list[0].schedule.length })}: {t('warmup.unlimited')}
              </Badge>
            </div>
          </div>
        )}

        <ConfirmDialog />
      </DialogContent>
    </Dialog>
  )
}
