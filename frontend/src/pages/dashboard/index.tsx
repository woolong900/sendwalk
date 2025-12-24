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
  sending_rate: number
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
    { key: '1min' as const, label: '1分钟', icon: Clock },
    { key: '10min' as const, label: '10分钟', icon: Clock },
    { key: '30min' as const, label: '半小时', icon: Clock },
    { key: '1hour' as const, label: '1小时', icon: Clock },
    { key: '1day' as const, label: '1天', icon: Clock },
  ]

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl md:text-2xl font-bold">仪表盘</h1>
        <p className="text-muted-foreground mt-2">
          欢迎回来，查看您的邮件营销数据
          <Badge variant="outline" className="ml-2 text-xs">
            <Activity className="w-3 h-3 mr-1" />
            实时更新
          </Badge>
        </p>
      </div>

      {/* 实时状态 */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* 发送队列 */}
        <Card className={stats?.queue_length && stats.queue_length > 0 ? 'border-cyan-200' : ''}>
          <CardHeader>
            <CardTitle className="text-sm font-medium">发送队列</CardTitle>
            <CardDescription>等待发送的邮件</CardDescription>
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
                      处理中
                    </Badge>
                    <Button
                      size="sm"
                      variant="destructive"
                      onClick={async () => {
                        const confirmed = await confirm({
                          title: '清空发送队列',
                          description: '确定要清空所有队列吗？这将删除所有等待发送的任务。',
                          confirmText: '清空',
                          cancelText: '取消',
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
                      {clearQueueMutation.isPending ? '清空中...' : '清空'}
                    </Button>
                  </>
                ) : (
                  <Badge variant="secondary" className="bg-gray-100 text-gray-600">
                    空闲
                  </Badge>
                )}
              </div>
            </div>
          </CardContent>
        </Card>

        {/* 调度器状态 */}
        <Card className={stats?.scheduler_running ? 'border-purple-200' : ''}>
          <CardHeader>
            <CardTitle className="text-sm font-medium">调度器</CardTitle>
            <CardDescription>定时任务调度器</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              <div className="flex items-end justify-between">
                <div className="text-3xl font-bold text-purple-600">
                  {stats?.scheduler_running ? '运行中' : '已停止'}
                </div>
                <Clock className="w-5 h-5 text-purple-600" />
              </div>
              <div className="flex items-center gap-2">
                {stats?.scheduler_running ? (
                  <>
                    <Badge variant="secondary" className="bg-purple-100 text-purple-700 flex-1">
                      <PlayCircle className="w-3 h-3 mr-1" />
                      运行中
                    </Badge>
                    <Button
                      size="sm"
                      variant="destructive"
                      onClick={() => stopSchedulerMutation.mutate()}
                      disabled={stopSchedulerMutation.isPending}
                      className="h-7 px-2"
                    >
                      <PowerOff className="w-3.5 h-3.5 mr-1" />
                      {stopSchedulerMutation.isPending ? '停止中...' : '停止'}
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
                    {startSchedulerMutation.isPending ? '启动中...' : '启动'}
                  </Button>
                )}
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Worker 数量 */}
        <Card className={stats?.worker_count && stats.worker_count > 0 ? 'border-blue-200' : ''}>
          <CardHeader>
            <CardTitle className="text-sm font-medium">Worker 数量</CardTitle>
            <CardDescription>自动管理的进程</CardDescription>
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
                  自动运行中
                </Badge>
              ) : (
                <Badge variant="secondary" className="bg-gray-100 text-gray-600">
                  空闲
                </Badge>
              )}
            </div>
          </CardContent>
        </Card>

        {/* SMTP服务器 */}
        <Card>
          <CardHeader>
            <CardTitle className="text-sm font-medium">SMTP服务器</CardTitle>
            <CardDescription>服务器状态</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">活跃</span>
                <Badge variant="default" className="bg-green-600">
                  {stats?.smtp_server_stats?.active || 0}
                </Badge>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">总数</span>
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
          <CardTitle>活动状态</CardTitle>
          <CardDescription>当前活动分布</CardDescription>
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
                <div className="text-xs text-muted-foreground">发送中</div>
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
                <div className="text-xs text-muted-foreground">已定时</div>
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
                <div className="text-xs text-muted-foreground">已完成</div>
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
                <div className="text-xs text-muted-foreground">草稿</div>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* 发送统计 */}
      <Card>
        <CardHeader>
          <CardTitle>实时发送统计</CardTitle>
          <CardDescription>不同时间段的发送情况</CardDescription>
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
                    <span className="text-sm font-medium">最近{range.label}</span>
                    <range.icon className="w-4 h-4 text-muted-foreground" />
                  </div>

                  {/* 总数 */}
                  <div className="mb-3">
                    <div className="text-2xl font-bold">{formatNumber(data?.total || 0)}</div>
                    <p className="text-xs text-muted-foreground">总发送</p>
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
                      <span className="text-muted-foreground">成功率</span>
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
