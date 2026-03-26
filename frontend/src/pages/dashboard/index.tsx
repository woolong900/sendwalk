import { useQuery, useMutation } from '@tanstack/react-query'
import { CheckCircle, XCircle, Clock, Layers, Activity, Zap, PlayCircle, Calendar, FileText, Power, PowerOff, Trash2 } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { fetcher, api } from '@/lib/api'
import { formatNumber } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'
import { Progress } from '@/components/ui/progress'
import { Button } from '@/components/ui/button'
import { toast } from 'sonner'
import { useConfirm } from '@/hooks/use-confirm'
import { useTranslation } from 'react-i18next'

interface SendStats {
  sent: number
  failed: number
  total: number
}

interface CampaignStatusStats {
  sending: number
  scheduled: number
  completed: number
  draft: number
}

interface SmtpServerStats {
  total: number
  active: number
  inactive: number
}

interface DashboardStats {
  total_subscribers: number
  total_campaigns: number
  total_sent: number
  avg_open_rate: number
  queue_length: number
  worker_count: number
  scheduler_running: boolean
  campaign_status_stats: CampaignStatusStats
  smtp_server_stats: SmtpServerStats
  send_stats: {
    '1min': SendStats
    '10min': SendStats
    '30min': SendStats
    '1hour': SendStats
    '1day': SendStats
  }
}

export default function DashboardPage() {
  const { t } = useTranslation()
  const { confirm, ConfirmDialog } = useConfirm()
  
  const { data: stats, refetch } = useQuery<DashboardStats>({
    queryKey: ['dashboard-stats'],
    queryFn: () => fetcher('/dashboard/stats'),
    refetchInterval: 5000, // 每5秒自动刷新
  })
  
  // 清空队列
  const clearQueueMutation = useMutation({
    mutationFn: async () => {
      const { data } = await api.post('/dashboard/queue/clear')
      return data
    },
    onSuccess: (data) => {
      toast.success(data.message || '队列已清空')
      refetch()
    },
    onError: (error: any) => {
      console.error('清空队列失败:', error)
    },
  })
  
  // 启动调度器
  const startSchedulerMutation = useMutation({
    mutationFn: async () => {
      const { data } = await api.post('/dashboard/scheduler/start')
      return data
    },
    onSuccess: (data) => {
      toast.success(data.message || '调度器启动成功')
      refetch()
    },
    onError: (error: any) => {
      console.error('启动调度器失败:', error)
    },
  })
  
  // 停止调度器
  const stopSchedulerMutation = useMutation({
    mutationFn: async () => {
      const { data } = await api.post('/dashboard/scheduler/stop')
      return data
    },
    onSuccess: (data) => {
      toast.success(data.message || '调度器已停止')
      refetch()
    },
    onError: (error: any) => {
      console.error('停止调度器失败:', error)
    },
  })

  const timeRanges = [
    { key: '1min' as const, labelKey: 'dashboard.oneMin', icon: Clock },
    { key: '10min' as const, labelKey: 'dashboard.tenMin', icon: Clock },
    { key: '30min' as const, labelKey: 'dashboard.halfHour', icon: Clock },
    { key: '1hour' as const, labelKey: 'dashboard.oneHour', icon: Clock },
    { key: '1day' as const, labelKey: 'dashboard.oneDay', icon: Clock },
  ]

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl md:text-2xl font-bold">{t('dashboard.title')}</h1>
        <div className="flex items-center gap-2 mt-2">
          <p className="text-muted-foreground">
            {t('dashboard.welcome')}
          </p>
          <Badge variant="outline" className="text-xs">
            <Activity className="w-3 h-3 mr-1" />
            {t('dashboard.realTimeUpdate')}
          </Badge>
        </div>
      </div>

      {/* 实时状态 */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* 发送队列 */}
        <Card className={stats?.queue_length && stats.queue_length > 0 ? 'border-cyan-200' : ''}>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t('dashboard.sendQueue')}</CardTitle>
            <CardDescription>{t('dashboard.waitingEmails')}</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              <div className="flex items-end justify-between">
                <div className="text-3xl font-bold text-cyan-600">
                  {formatNumber(stats?.queue_length || 0)}
                </div>
                <Layers className="w-5 h-5 text-cyan-600" />
              </div>
              <div className="flex items-center gap-2">
                {stats?.queue_length && stats.queue_length > 0 ? (
                  <>
                    <Badge variant="secondary" className="bg-cyan-100 text-cyan-700 flex-1">
                      <Zap className="w-3 h-3 mr-1" />
                      {t('common.processing')}
                    </Badge>
                    <Button
                      size="sm"
                      variant="destructive"
                      onClick={async () => {
                        const confirmed = await confirm({
                          title: t('dashboard.clearQueue'),
                          description: t('dashboard.clearQueueConfirm'),
                          confirmText: t('common.clear'),
                          cancelText: t('common.cancel'),
                          variant: 'destructive',
                        })
                        if (confirmed) {
                          clearQueueMutation.mutate()
                        }
                      }}
                      disabled={clearQueueMutation.isPending}
                      className="h-7 px-2"
                    >
                      <Trash2 className="w-3.5 h-3.5 mr-1" />
                      {clearQueueMutation.isPending ? t('common.clearing') : t('common.clear')}
                    </Button>
                  </>
                ) : (
                  <Badge variant="secondary" className="bg-gray-100 text-gray-600">
                    {t('common.idle')}
                  </Badge>
                )}
              </div>
            </div>
          </CardContent>
        </Card>

        {/* 调度器状态 */}
        <Card className={stats?.scheduler_running ? 'border-purple-200' : ''}>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t('dashboard.scheduler')}</CardTitle>
            <CardDescription>{t('dashboard.schedulerDesc')}</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              <div className="flex items-end justify-between">
                <div className="text-3xl font-bold text-purple-600">
                  {stats?.scheduler_running ? t('common.running') : t('common.stopped')}
                </div>
                <Clock className="w-5 h-5 text-purple-600" />
              </div>
              <div className="flex items-center gap-2">
                {stats?.scheduler_running ? (
                  <>
                    <Badge variant="secondary" className="bg-purple-100 text-purple-700 flex-1">
                      <PlayCircle className="w-3 h-3 mr-1" />
                      {t('common.running')}
                    </Badge>
                    <Button
                      size="sm"
                      variant="destructive"
                      onClick={() => stopSchedulerMutation.mutate()}
                      disabled={stopSchedulerMutation.isPending}
                      className="h-7 px-2"
                    >
                      <PowerOff className="w-3.5 h-3.5 mr-1" />
                      {stopSchedulerMutation.isPending ? t('common.stopping') : t('common.stop')}
                    </Button>
                  </>
                ) : (
                  <Button
                    size="sm"
                    variant="default"
                    onClick={() => startSchedulerMutation.mutate()}
                    disabled={startSchedulerMutation.isPending}
                    className="w-full h-7 bg-purple-600 hover:bg-purple-700"
                  >
                    <Power className="w-3.5 h-3.5 mr-1" />
                    {startSchedulerMutation.isPending ? t('common.starting') : t('common.start')}
                  </Button>
                )}
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Worker 数量 */}
        <Card className={stats?.worker_count && stats.worker_count > 0 ? 'border-blue-200' : ''}>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t('dashboard.workerCount')}</CardTitle>
            <CardDescription>{t('dashboard.workerDesc')}</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-2">
              <div className="flex items-end justify-between">
                <div className="text-3xl font-bold text-blue-600">
                  {stats?.worker_count || 0}
                </div>
                <Activity className="w-5 h-5 text-blue-600" />
              </div>
              {stats?.worker_count && stats.worker_count > 0 ? (
                <Badge variant="secondary" className="bg-blue-100 text-blue-700">
                  <PlayCircle className="w-3 h-3 mr-1" />
                  {t('dashboard.autoRunning')}
                </Badge>
              ) : (
                <Badge variant="secondary" className="bg-gray-100 text-gray-600">
                  {t('common.idle')}
                </Badge>
              )}
            </div>
          </CardContent>
        </Card>

        {/* SMTP服务器 */}
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">{t('dashboard.smtpServer')}</CardTitle>
            <CardDescription>{t('dashboard.serverStatus')}</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">{t('common.active')}</span>
                <Badge variant="default" className="bg-green-600">
                  {stats?.smtp_server_stats?.active || 0}
                </Badge>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">{t('common.total')}</span>
                <Badge variant="secondary" className="bg-blue-600 text-white">
                  {stats?.smtp_server_stats?.total || 0}
                </Badge>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* 活动状态 */}
      <Card>
        <CardHeader>
          <CardTitle>{t('dashboard.campaignStatus')}</CardTitle>
          <CardDescription>{t('dashboard.campaignStatus')}</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div className="flex items-center gap-3 p-3 rounded-lg bg-green-50 border border-green-200">
              <div className="p-2 bg-green-100 rounded-lg">
                <PlayCircle className="w-4 h-4 text-green-600" />
              </div>
              <div>
                <div className="text-2xl font-bold text-green-600">
                  {stats?.campaign_status_stats?.sending || 0}
                </div>
                <div className="text-xs text-muted-foreground">{t('dashboard.sending')}</div>
              </div>
            </div>
            
            <div className="flex items-center gap-3 p-3 rounded-lg bg-blue-50 border border-blue-200">
              <div className="p-2 bg-blue-100 rounded-lg">
                <Calendar className="w-4 h-4 text-blue-600" />
              </div>
              <div>
                <div className="text-2xl font-bold text-blue-600">
                  {stats?.campaign_status_stats?.scheduled || 0}
                </div>
                <div className="text-xs text-muted-foreground">{t('dashboard.scheduled')}</div>
              </div>
            </div>
            
            <div className="flex items-center gap-3 p-3 rounded-lg bg-purple-50 border border-purple-200">
              <div className="p-2 bg-purple-100 rounded-lg">
                <CheckCircle className="w-4 h-4 text-purple-600" />
              </div>
              <div>
                <div className="text-2xl font-bold text-purple-600">
                  {stats?.campaign_status_stats?.completed || 0}
                </div>
                <div className="text-xs text-muted-foreground">{t('dashboard.completed')}</div>
              </div>
            </div>
            
            <div className="flex items-center gap-3 p-3 rounded-lg bg-gray-50 border border-gray-200">
              <div className="p-2 bg-gray-100 rounded-lg">
                <FileText className="w-4 h-4 text-gray-600" />
              </div>
              <div>
                <div className="text-2xl font-bold text-gray-600">
                  {stats?.campaign_status_stats?.draft || 0}
                </div>
                <div className="text-xs text-muted-foreground">{t('dashboard.draft')}</div>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* 发送统计 */}
      <Card>
        <CardHeader>
          <CardTitle>{t('dashboard.realTimeSendStats')}</CardTitle>
          <CardDescription>{t('dashboard.differentTimeRanges')}</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            {timeRanges.map((range) => {
              const data = stats?.send_stats[range.key]
              const successRate = data?.total ? (data.sent / data.total) * 100 : 0
              
              return (
                <div key={range.key} className="p-4 rounded-lg border bg-card hover:shadow-md transition-shadow">
                  {/* 标题 */}
                  <div className="flex items-center justify-between mb-3">
                    <span className="text-sm font-medium">{t('dashboard.last')} {t(range.labelKey)}</span>
                    <range.icon className="w-4 h-4 text-muted-foreground" />
                  </div>

                  {/* 总数 */}
                  <div className="mb-3">
                    <div className="text-2xl font-bold">{formatNumber(data?.total || 0)}</div>
                    <p className="text-xs text-muted-foreground">{t('dashboard.totalSent')}</p>
                  </div>

                  {/* 成功/失败 */}
                  <div className="flex items-center justify-between text-xs mb-2">
                    <div className="flex items-center gap-1 text-green-600">
                      <CheckCircle className="w-3 h-3" />
                      <span>{formatNumber(data?.sent || 0)}</span>
                    </div>
                    <div className="flex items-center gap-1 text-red-600">
                      <XCircle className="w-3 h-3" />
                      <span>{formatNumber(data?.failed || 0)}</span>
                    </div>
                  </div>

                  {/* 成功率进度条 */}
                  <div className="space-y-1">
                    <div className="flex justify-between text-xs">
                      <span className="text-muted-foreground">{t('dashboard.successRate')}</span>
                      <span className="font-medium">{successRate.toFixed(1)}%</span>
                    </div>
                    <Progress value={successRate} className="h-2" />
                  </div>
                </div>
              )
            })}
          </div>
        </CardContent>
      </Card>

      {/* 确认对话框 */}
      <ConfirmDialog />
    </div>
  )
}
