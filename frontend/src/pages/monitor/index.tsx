import { useState, useEffect, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { XCircle, Loader, RefreshCw, Pause, Play, Terminal, Trash2 } from 'lucide-react'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from 'sonner'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { api } from '@/lib/api'
import { useConfirm } from '@/hooks/use-confirm'

interface SendLog {
  id: number
  campaign_name: string
  smtp_server_name: string
  email: string
  status: 'sent' | 'failed'
  error_message?: string
  started_at: string
  completed_at: string
  created_at: string
}

interface Stats {
  total: number
  sent: number
  failed: number
  success_rate: number
}

interface SmtpServer {
  id: number
  name: string
  type: string
}

export default function SendMonitorPage() {
  const { confirm, ConfirmDialog } = useConfirm()
  
  const [selectedServer, setSelectedServer] = useState<string>('all')
  const [selectedStatus, setSelectedStatus] = useState<string>('all')
  const [selectedTimeRange, setSelectedTimeRange] = useState<string>('all')
  const [autoRefresh, setAutoRefresh] = useState(true)
  const [autoScroll, setAutoScroll] = useState(true)
  const [nextPageToLoad, setNextPageToLoad] = useState(2) // 下一页要加载的页码
  const [pageSize] = useState(50) // 每页50条
  const [maxDisplayLogs] = useState(100) // 最多显示100条
  const [isLoadingMore, setIsLoadingMore] = useState(false)
  const [hasMore, setHasMore] = useState(true)
  const [allLogs, setAllLogs] = useState<SendLog[]>([]) // 当前窗口的日志（最多100条）
  const [lastLogId, setLastLogId] = useState<number | null>(null)
  const logContainerRef = useRef<HTMLDivElement>(null)
  const isLoadingMoreRef = useRef(false)
  const initialLoadDone = useRef(false)
  const lastScrollTopRef = useRef(0) // 记录上次滚动位置
  const scrollCheckTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const queryClient = useQueryClient()

  // 获取 SMTP 服务器列表
  const { data: smtpServers } = useQuery<SmtpServer[]>({
    queryKey: ['smtp-servers-list'],
    queryFn: async () => {
      const response = await api.get('/smtp-servers')
      return response.data.data
    },
  })

  // 清空日志
  const clearLogsMutation = useMutation({
    mutationFn: async () => {
      const params = {
        ...(selectedServer !== 'all' ? { smtp_server_id: selectedServer } : {}),
        ...(selectedStatus !== 'all' ? { status: selectedStatus } : {}),
        time_range: selectedTimeRange
      }
      return api.post('/monitor/clear-logs', params)
    },
    onSuccess: () => {
      // 重置状态
      initialLoadDone.current = false
      setAllLogs([])
      setNextPageToLoad(2)
      setHasMore(true)
      setLastLogId(null)
      setAutoScroll(true)
      
      queryClient.invalidateQueries({ queryKey: ['send-logs-paginated'] })
      queryClient.invalidateQueries({ queryKey: ['send-logs-latest'] })
      queryClient.invalidateQueries({ queryKey: ['send-stats'] })
      toast.success('日志已清空')
    },
    // onError 已由全局拦截器处理
  })

  // 获取分页日志（只用于首次加载）
  const { data: paginatedData, isLoading: logsLoading, error: logsError } = useQuery({
    queryKey: ['send-logs-paginated', selectedServer, selectedStatus, selectedTimeRange, pageSize],
    queryFn: async () => {
      const params = {
        page: 1, // 始终加载第一页
        per_page: pageSize,
        ...(selectedServer !== 'all' ? { smtp_server_id: selectedServer } : {}),
        ...(selectedStatus !== 'all' ? { status: selectedStatus } : {}),
        time_range: selectedTimeRange
      }
      const response = await api.get('/monitor/logs/paginated', { params })
      return response.data
    },
    staleTime: 0, // 总是重新获取
    refetchOnWindowFocus: false, // 窗口聚焦时不重新获取
    refetchOnReconnect: false, // 重新连接时不重新获取
  })
  
  // 处理首次加载数据
  useEffect(() => {
    if (paginatedData && paginatedData.data) {
      if (!initialLoadDone.current) {
        initialLoadDone.current = true
        
        // 第一页，直接设置（反转顺序，因为后端返回的是降序）
        const reversedData = [...paginatedData.data].reverse()
        setAllLogs(reversedData)
        setHasMore(paginatedData.meta.current_page < paginatedData.meta.last_page)
        setNextPageToLoad(2) // 下次加载第2页
        
        if (reversedData.length > 0) {
          // 记录窗口边界
          setLastLogId(reversedData[reversedData.length - 1].id) // 最新的日志
          
          // 首次加载后滚动到底部
          requestAnimationFrame(() => {
            if (logContainerRef.current) {
              logContainerRef.current.scrollTop = logContainerRef.current.scrollHeight
            }
          })
        }
      }
    }
  }, [paginatedData, selectedServer, selectedStatus])
  
  // 获取最新日志（用于实时刷新）
  const { data: latestLogs } = useQuery<SendLog[]>({
    queryKey: ['send-logs-latest', selectedServer, selectedStatus, selectedTimeRange, lastLogId],
    queryFn: async () => {
      if (!lastLogId) return []
      const params = {
        after_id: lastLogId,
        ...(selectedServer !== 'all' ? { smtp_server_id: selectedServer } : {}),
        ...(selectedStatus !== 'all' ? { status: selectedStatus } : {}),
        time_range: selectedTimeRange
      }
      const response = await api.get('/monitor/logs', { params })
      return response.data.data
    },
    refetchInterval: autoRefresh ? 2000 : false, // 每2秒刷新新日志
    enabled: lastLogId !== null && autoRefresh,
  })
  
  // 当有新日志时，添加到列表
  useEffect(() => {
    if (latestLogs && latestLogs.length > 0) {
      setAllLogs(prev => {
        // 过滤掉已存在的日志（去重）
        const existingIds = new Set(prev.map(log => log.id))
        const uniqueNewLogs = latestLogs.filter(log => !existingIds.has(log.id))
        
        // 如果没有新日志，不更新
        if (uniqueNewLogs.length === 0) {
          return prev
        }
        
        const newLogs = [...prev, ...uniqueNewLogs]
        
        // 只有在自动滚动模式（用户在底部）时才触发滑动窗口
        // 如果用户正在查看历史记录（不在底部），不删除任何数据
        if (autoScroll && newLogs.length > maxDisplayLogs) {
          const trimmedLogs = newLogs.slice(-maxDisplayLogs)
          return trimmedLogs
        }
        return newLogs
      })
      
      // 只更新 lastLogId 如果有新日志
      const newMaxId = Math.max(...latestLogs.map(log => log.id))
      if (!lastLogId || newMaxId > lastLogId) {
        setLastLogId(newMaxId)
      }
      
      // 只有在自动滚动模式下才滚动到底部
      if (autoScroll && logContainerRef.current) {
        // 使用 requestAnimationFrame 优化滚动性能
        requestAnimationFrame(() => {
          if (logContainerRef.current) {
            logContainerRef.current.scrollTop = logContainerRef.current.scrollHeight
          }
        })
      }
    }
  }, [latestLogs, autoScroll, maxDisplayLogs, lastLogId])

  // 获取统计数据
  const { data: stats, isLoading: statsLoading, error: statsError } = useQuery<Stats>({
    queryKey: ['send-stats', selectedServer, selectedStatus, selectedTimeRange],
    queryFn: async () => {
      const params = {
        ...(selectedServer !== 'all' ? { smtp_server_id: selectedServer } : {}),
        ...(selectedStatus !== 'all' ? { status: selectedStatus } : {}),
        time_range: selectedTimeRange
      }
      const response = await api.get('/monitor/stats', { params })
      return response.data.data
    },
    refetchInterval: autoRefresh ? 2000 : false,
  })

  // 显示的日志
  const displayLogs = allLogs
  const totalLogs = paginatedData?.meta.total || allLogs.length

  // 加载更多历史日志
  const loadMoreLogs = async () => {
    if (isLoadingMoreRef.current || !hasMore) {
      return
    }
    
    isLoadingMoreRef.current = true
    setIsLoadingMore(true)
    
    try {
      const params = {
        page: nextPageToLoad,
        per_page: pageSize,
        ...(selectedServer !== 'all' ? { smtp_server_id: selectedServer } : {}),
        ...(selectedStatus !== 'all' ? { status: selectedStatus } : {}),
        time_range: selectedTimeRange
      }
      const response = await api.get('/monitor/logs/paginated', { params })
      const data = response.data
      
      if (data.data.length > 0) {
        // 保存当前滚动位置
        const container = logContainerRef.current
        const oldScrollHeight = container?.scrollHeight || 0
        const oldScrollTop = container?.scrollTop || 0
        
        // 将新加载的历史日志添加到前面（反转顺序，因为后端返回的是降序）
        const reversedData = [...data.data].reverse()
        setAllLogs(prev => {
          // 去重：过滤掉已存在的日志
          const existingIds = new Set(prev.map(log => log.id))
          const uniqueNewLogs = reversedData.filter(log => !existingIds.has(log.id))
          
          const newLogs = [...uniqueNewLogs, ...prev]
          
          // 当用户在查看历史记录时（不在底部），不应用滑动窗口限制
          // 允许用户加载任意多的历史数据
          return newLogs
        })
        
        setNextPageToLoad(prev => prev + 1) // 准备加载下一页
        const stillHasMore = data.meta.current_page < data.meta.last_page
        setHasMore(stillHasMore)
        
        // 恢复滚动位置（保持用户看到的内容不变）
        requestAnimationFrame(() => {
          if (container) {
            const newScrollHeight = container.scrollHeight
            const addedHeight = newScrollHeight - oldScrollHeight
            container.scrollTop = oldScrollTop + addedHeight
          }
        })
        
        // 重置加载标志（在恢复滚动位置之后）
        setTimeout(() => {
          isLoadingMoreRef.current = false
          setIsLoadingMore(false)
        }, 100)
      } else {
        isLoadingMoreRef.current = false
        setIsLoadingMore(false)
      }
    } catch (error) {
      console.error('加载更多日志失败:', error)
      isLoadingMoreRef.current = false
      setIsLoadingMore(false)
    }
  }
  
  // 检测用户滚动
  const handleScroll = () => {
    if (!logContainerRef.current || !initialLoadDone.current) return
    
    const { scrollTop, scrollHeight, clientHeight } = logContainerRef.current
    
    // 更新滚动位置记录
    lastScrollTopRef.current = scrollTop
    
    // 检测是否在底部（控制自动滚动）
    const isAtBottom = scrollHeight - scrollTop - clientHeight < 50
    if (!isAtBottom && autoScroll) {
      setAutoScroll(false)
    } else if (isAtBottom && !autoScroll) {
      setAutoScroll(true)
    }
    
    // 在顶部区域（< 100px）时，触发加载更多
    if (scrollTop < 100 && hasMore && !isLoadingMoreRef.current) {
      // 清除之前的定时器
      if (scrollCheckTimeoutRef.current) {
        clearTimeout(scrollCheckTimeoutRef.current)
      }
      
      // 使用防抖，避免频繁触发
      scrollCheckTimeoutRef.current = setTimeout(() => {
        if (!isLoadingMoreRef.current && hasMore && logContainerRef.current) {
          // 再次检查滚动位置
          if (logContainerRef.current.scrollTop < 100) {
            loadMoreLogs()
          }
        }
      }, 100)
    }
  }
  
  // 当筛选条件改变时，重置状态
  useEffect(() => {
    initialLoadDone.current = false
    setNextPageToLoad(2)
    setAllLogs([])
    setHasMore(true)
    setLastLogId(null)
    setAutoScroll(true)
  }, [selectedServer, selectedStatus, selectedTimeRange])
  
  // 手动刷新（只刷新统计数据和最新日志）
  const handleRefresh = () => {
    queryClient.invalidateQueries({ queryKey: ['send-stats'] })
    if (lastLogId) {
      queryClient.invalidateQueries({ queryKey: ['send-logs-latest'] })
    }
  }

  if (logsError || statsError) {
    return (
      <div className="flex flex-col items-center justify-center h-64">
        <XCircle className="w-12 h-12 text-red-500 mb-4" />
        <p className="text-lg font-medium mb-2">加载失败</p>
        <p className="text-muted-foreground mb-4">
          {(logsError as Error)?.message || (statsError as Error)?.message || '请检查网络连接'}
        </p>
        <Button onClick={handleRefresh}>重试</Button>
      </div>
    )
  }

  const getStatusText = (status: string) => {
    const labels = {
      sent: '成功',
      failed: '失败',
    }
    return labels[status as keyof typeof labels] || status
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">发送监控</h1>
          <p className="text-muted-foreground mt-2">
            实时监控邮件发送状态
            {totalLogs > 0 && (
              <span className="ml-2 text-sm text-muted-foreground">
                (显示 {displayLogs.length} 条，共 {totalLogs} 条 · 滑动窗口最多显示 {maxDisplayLogs} 条)
              </span>
            )}
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant={autoRefresh ? 'default' : 'outline'}
            size="sm"
            onClick={() => setAutoRefresh(!autoRefresh)}
          >
            <RefreshCw className={`w-4 h-4 mr-2 ${autoRefresh ? 'animate-spin' : ''}`} />
            {autoRefresh ? '自动刷新' : '已暂停'}
          </Button>
          <Button variant="outline" size="sm" onClick={handleRefresh}>
            <RefreshCw className="w-4 h-4 mr-2" />
            手动刷新
          </Button>
          {totalLogs > 0 && (
            <Button
              variant="outline"
              size="sm"
              onClick={async () => {
                const confirmed = await confirm({
                  title: '清空发送日志',
                  description: '确定要清空所有日志吗？此操作不可恢复。',
                  confirmText: '清空',
                  cancelText: '取消',
                  variant: 'destructive',
                })
                if (confirmed) {
                  clearLogsMutation.mutate()
                }
              }}
              disabled={clearLogsMutation.isPending}
            >
              <Trash2 className="w-4 h-4 mr-2" />
              清空日志
            </Button>
          )}
        </div>
      </div>

      {/* 过滤器 */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex gap-4">
            <div className="flex-1">
              <label className="text-sm font-medium text-muted-foreground mb-2 block">
                按发送服务器筛选
              </label>
              <Select value={selectedServer} onValueChange={setSelectedServer}>
                <SelectTrigger>
                  <SelectValue placeholder="选择发送服务器" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">所有服务器</SelectItem>
                  {smtpServers?.map((server) => (
                    <SelectItem key={server.id} value={server.id.toString()}>
                      {server.name} ({server.type.toUpperCase()})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex-1">
              <label className="text-sm font-medium text-muted-foreground mb-2 block">
                按发送状态筛选
              </label>
              <Select value={selectedStatus} onValueChange={setSelectedStatus}>
                <SelectTrigger>
                  <SelectValue placeholder="选择发送状态" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">全部状态</SelectItem>
                  <SelectItem value="sent">成功</SelectItem>
                  <SelectItem value="failed">失败</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex-1">
              <label className="text-sm font-medium text-muted-foreground mb-2 block">
                按时间范围筛选
              </label>
              <Select value={selectedTimeRange} onValueChange={setSelectedTimeRange}>
                <SelectTrigger>
                  <SelectValue placeholder="选择时间范围" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">全部时间</SelectItem>
                  <SelectItem value="1m">最近 1 分钟</SelectItem>
                  <SelectItem value="10m">最近 10 分钟</SelectItem>
                  <SelectItem value="30m">最近 30 分钟</SelectItem>
                  <SelectItem value="1h">最近 1 小时</SelectItem>
                  <SelectItem value="1d">最近 1 天</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* 统计卡片 */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {statsLoading || !stats ? (
          // 加载中显示骨架屏
          <>
            {[...Array(4)].map((_, i) => (
              <Card key={i}>
                <CardHeader className="pb-3">
                  <Skeleton className="h-4 w-16" />
                </CardHeader>
                <CardContent>
                  <Skeleton className="h-8 w-20" />
                </CardContent>
              </Card>
            ))}
          </>
        ) : (
          <>
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  总数
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold">{stats.total}</div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-medium text-green-500">
                  成功
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold text-green-600">{stats.sent}</div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-medium text-red-500">
                  失败
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold text-red-600">{stats.failed}</div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-medium text-blue-500">
                  成功率
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold text-blue-600">{stats.success_rate}%</div>
              </CardContent>
            </Card>
          </>
        )}
      </div>

      {/* 发送日志 */}
      <Card className="overflow-hidden">
        <CardHeader className="bg-slate-900 text-white">
          <div className="flex items-center justify-between">
            <CardTitle className="flex items-center gap-2">
              <Terminal className="w-5 h-5" />
              发送日志
            </CardTitle>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setAutoScroll(!autoScroll)}
                className="text-white hover:bg-slate-800"
              >
                {autoScroll ? (
                  <>
                    <Play className="w-3 h-3 mr-1" />
                    自动滚动
                  </>
                ) : (
                  <>
                    <Pause className="w-3 h-3 mr-1" />
                    已暂停
                  </>
                )}
              </Button>
              {!autoScroll && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => {
                    setAutoScroll(true)
                    if (logContainerRef.current) {
                      logContainerRef.current.scrollTop = logContainerRef.current.scrollHeight
                    }
                  }}
                  className="text-white hover:bg-slate-800"
                >
                  跳至底部
                </Button>
              )}
            </div>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          <div
                ref={logContainerRef}
                onScroll={handleScroll}
                className="h-[800px] overflow-y-auto bg-slate-950 text-slate-100 font-mono text-sm"
                style={{
                  scrollBehavior: autoScroll ? 'smooth' : 'auto',
                }}
              >
            {logsLoading && displayLogs.length === 0 ? (
              // 加载中显示骨架屏样式的日志行
              <div className="p-4 space-y-1">
                {[...Array(10)].map((_, i) => (
                  <div key={i} className="py-1.5 px-2 rounded">
                    <div className="flex items-start gap-3">
                      <Skeleton className="h-3 w-32 bg-slate-800" />
                      <Skeleton className="h-3 w-4 bg-slate-800" />
                      <div className="flex-1 space-y-1">
                        <Skeleton className="h-3 w-full bg-slate-800" />
                        <Skeleton className="h-3 w-3/4 bg-slate-800" />
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            ) : displayLogs && displayLogs.length > 0 ? (
              <div className="p-4 space-y-1">
                {/* 加载更多提示 */}
                {isLoadingMore && (
                  <div className="text-center text-slate-500 text-xs py-3 border-b border-slate-800">
                    <Loader className="w-4 h-4 inline-block animate-spin mr-2" />
                    加载更多历史记录...
                  </div>
                )}
                {!isLoadingMore && hasMore && nextPageToLoad > 2 && (
                  <div className="text-center text-slate-500 text-xs py-2 border-b border-slate-800">
                    ▲ 继续向上滚动加载更多历史记录
                  </div>
                )}
                {!hasMore && nextPageToLoad > 2 && (
                  <div className="text-center text-slate-500 text-xs py-2 border-b border-slate-800">
                    ▲ 已到达最早的日志
                  </div>
                )}
                
                {displayLogs.map((log) => {
                  const date = new Date(log.created_at)
                  const year = date.getFullYear()
                  const month = String(date.getMonth() + 1).padStart(2, '0')
                  const day = String(date.getDate()).padStart(2, '0')
                  const hours = String(date.getHours()).padStart(2, '0')
                  const minutes = String(date.getMinutes()).padStart(2, '0')
                  const seconds = String(date.getSeconds()).padStart(2, '0')
                  const time = `[${year}-${month}-${day} ${hours}:${minutes}:${seconds}]`
                  
                  const statusSymbol = log.status === 'sent' ? '✓' : '✗'
                  const statusColor = log.status === 'sent' ? 'text-green-400' : 'text-red-400'

                  return (
                    <div
                      key={log.id}
                      className="py-1.5 px-2 rounded hover:bg-slate-800/30 transition-colors"
                    >
                      <div className="flex items-start gap-3">
                        <span className="text-slate-500 text-xs whitespace-nowrap">
                          {time}
                        </span>
                        <span className={`${statusColor} font-bold`}>
                          {statusSymbol}
                        </span>
                        <div className="flex-1 min-w-0">
                          <span className="text-slate-300">
                            [{log.campaign_name}]
                          </span>
                          <span className="text-slate-400 mx-2">→</span>
                          <span className="text-cyan-400">{log.email}</span>
                          {log.smtp_server_name && (
                            <>
                              <span className="text-slate-500 mx-2">via</span>
                              <span className="text-purple-400">{log.smtp_server_name}</span>
                            </>
                          )}
                          <span className={`ml-3 ${statusColor}`}>
                            {getStatusText(log.status).toUpperCase()}
                          </span>
                          {log.error_message && (
                            <div className="text-red-400 text-xs mt-1 pl-6">
                              ERROR: {log.error_message}
                            </div>
                          )}
                        </div>
                      </div>
                    </div>
                  )
                })}
              </div>
            ) : (
              <div className="flex items-center justify-center h-full text-slate-500">
                <div className="text-center">
                  <Terminal className="w-12 h-12 mx-auto mb-3 opacity-50" />
                  <p>暂无发送记录</p>
                  <p className="text-xs mt-2 opacity-75">日志将在此实时显示</p>
                </div>
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* 确认对话框 */}
      <ConfirmDialog />
    </div>
  )
}

