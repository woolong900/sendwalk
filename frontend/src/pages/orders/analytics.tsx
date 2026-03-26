import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { BarChart3, AlertTriangle, TrendingUp, DollarSign, Package, Mail, ArrowUpDown, ArrowUp, ArrowDown, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
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
import { cn } from '@/lib/utils'

interface AnalyticsData {
  range: string
  start_date: string
  end_date: string
  summary: {
    total_orders: number
    total_amount: number
    total_send_count: number
  }
  by_sending_domain: {
    domain: string
    send_count: number
    order_count: number
    total_amount: number
  }[]
  by_landing_domain: {
    domain: string
    order_count: number
    total_amount: number
  }[]
  domains_without_orders: string[]
}

type SortDirection = 'asc' | 'desc'

interface SortConfig {
  key: string
  direction: SortDirection
}

const timeRangeKeys = [
  { value: 'today', labelKey: 'analytics.today' },
  { value: 'yesterday', labelKey: 'analytics.yesterday' },
  { value: '3days', labelKey: 'analytics.last3Days' },
  { value: 'week', labelKey: 'analytics.lastWeek' },
  { value: 'month', labelKey: 'analytics.lastMonth' },
]

// 可排序的表头组件
function SortableHeader({ 
  children, 
  sortKey, 
  currentSort, 
  onSort,
  className 
}: { 
  children: React.ReactNode
  sortKey: string
  currentSort: SortConfig
  onSort: (key: string) => void
  className?: string
}) {
  const isActive = currentSort.key === sortKey
  
  return (
    <TableHead 
      className={cn("cursor-pointer select-none hover:bg-muted/50 transition-colors", className)}
      onClick={() => onSort(sortKey)}
    >
      <div className="flex items-center justify-end gap-1">
        <span>{children}</span>
        {isActive ? (
          currentSort.direction === 'desc' ? (
            <ArrowDown className="w-4 h-4" />
          ) : (
            <ArrowUp className="w-4 h-4" />
          )
        ) : (
          <ArrowUpDown className="w-4 h-4 text-muted-foreground" />
        )}
      </div>
    </TableHead>
  )
}

export default function OrderAnalyticsPage() {
  const { t } = useTranslation()
  const [selectedRange, setSelectedRange] = useState('today')
  
  // 发件域名表格排序状态
  const [sendingSort, setSendingSort] = useState<SortConfig>({ key: 'send_count', direction: 'desc' })
  
  // 落地页域名表格排序状态
  const [landingSort, setLandingSort] = useState<SortConfig>({ key: 'order_count', direction: 'desc' })

  const [forceRefresh, setForceRefresh] = useState(false)
  
  const { data, isLoading, isFetching } = useQuery<AnalyticsData>({
    queryKey: ['order-analytics', selectedRange, forceRefresh],
    queryFn: async () => {
      const params = new URLSearchParams({ range: selectedRange })
      if (forceRefresh) {
        params.append('refresh', 'true')
        setForceRefresh(false) // 重置刷新标志
      }
      const response = await api.get(`/orders/analytics?${params}`)
      return response.data
    },
  })

  const formatAmount = (amount: number | string | null | undefined) => {
    const num = Number(amount) || 0
    return `$${num.toFixed(2)}`
  }

  const getRangeLabel = (value: string) => {
    const range = timeRangeKeys.find(r => r.value === value)
    return range ? t(range.labelKey) : value
  }

  // 处理发件域名表格排序
  const handleSendingSort = (key: string) => {
    setSendingSort(prev => ({
      key,
      direction: prev.key === key && prev.direction === 'desc' ? 'asc' : 'desc'
    }))
  }

  // 处理落地页域名表格排序
  const handleLandingSort = (key: string) => {
    setLandingSort(prev => ({
      key,
      direction: prev.key === key && prev.direction === 'desc' ? 'asc' : 'desc'
    }))
  }

  // 排序后的发件域名数据
  const sortedSendingDomains = useMemo(() => {
    if (!data?.by_sending_domain) return []
    
    return [...data.by_sending_domain].sort((a, b) => {
      const aValue = a[sendingSort.key as keyof typeof a]
      const bValue = b[sendingSort.key as keyof typeof b]
      
      if (typeof aValue === 'number' && typeof bValue === 'number') {
        return sendingSort.direction === 'desc' ? bValue - aValue : aValue - bValue
      }
      
      const aStr = String(aValue || '')
      const bStr = String(bValue || '')
      return sendingSort.direction === 'desc' 
        ? bStr.localeCompare(aStr) 
        : aStr.localeCompare(bStr)
    })
  }, [data?.by_sending_domain, sendingSort])

  // 排序后的落地页域名数据
  const sortedLandingDomains = useMemo(() => {
    if (!data?.by_landing_domain) return []
    
    return [...data.by_landing_domain].sort((a, b) => {
      const aValue = a[landingSort.key as keyof typeof a]
      const bValue = b[landingSort.key as keyof typeof b]
      
      if (typeof aValue === 'number' && typeof bValue === 'number') {
        return landingSort.direction === 'desc' ? bValue - aValue : aValue - bValue
      }
      
      const aStr = String(aValue || '')
      const bStr = String(bValue || '')
      return landingSort.direction === 'desc' 
        ? bStr.localeCompare(aStr) 
        : aStr.localeCompare(bStr)
    })
  }, [data?.by_landing_domain, landingSort])

  // 统计发了信但没出单的域名数量
  const domainsWithSendNoOrders = useMemo(() => {
    if (!data?.by_sending_domain) return 0
    return data.by_sending_domain.filter(d => d.order_count === 0).length
  }, [data?.by_sending_domain])

  // 刷新数据（强制清除服务端缓存）
  const handleRefresh = () => {
    setForceRefresh(true)
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl md:text-2xl font-bold">{t('analytics.title')}</h1>
          <p className="text-muted-foreground mt-1">
            {t('analytics.subtitle')}
          </p>
        </div>
        <Button 
          variant="outline" 
          size="sm"
          onClick={handleRefresh}
          disabled={isFetching}
        >
          <RefreshCw className={`w-4 h-4 mr-2 ${isFetching ? 'animate-spin' : ''}`} />
          {t('analytics.refreshData')}
        </Button>
      </div>

      {/* 时间范围选择器 */}
      <div className="flex flex-wrap gap-2">
        {timeRangeKeys.map((range) => (
          <Button
            key={range.value}
            variant={selectedRange === range.value ? 'default' : 'outline'}
            size="sm"
            onClick={() => setSelectedRange(range.value)}
          >
            {t(range.labelKey)}
          </Button>
        ))}
      </div>

      {isLoading ? (
        <div className="text-center py-8 text-muted-foreground">{t('common.loading')}</div>
      ) : data ? (
        <>
          {/* 汇总统计卡片 */}
          <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                  <Mail className="w-4 h-4" />
                  {getRangeLabel(selectedRange)}{t('analytics.sendCount')}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold text-blue-600">{data.summary.total_send_count.toLocaleString()}</div>
                <p className="text-xs text-muted-foreground mt-1">
                  {data.start_date} ~ {data.end_date}
                </p>
              </CardContent>
            </Card>
            
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                  <Package className="w-4 h-4" />
                  {getRangeLabel(selectedRange)}{t('analytics.orderCount')}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold">{data.summary.total_orders.toLocaleString()}</div>
              </CardContent>
            </Card>
            
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                  <DollarSign className="w-4 h-4" />
                  {getRangeLabel(selectedRange)}{t('analytics.transactionAmount')}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold">{formatAmount(data.summary.total_amount)}</div>
              </CardContent>
            </Card>
            
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                  <AlertTriangle className="w-4 h-4" />
                  {t('analytics.domainsNoOrders')}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold text-orange-600">
                  {domainsWithSendNoOrders}
                </div>
              </CardContent>
            </Card>
            
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                  <AlertTriangle className="w-4 h-4" />
                  {t('analytics.landingDomainsNoOrders')}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold text-red-600">
                  {data.domains_without_orders.length}
                </div>
              </CardContent>
            </Card>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {/* 按发件域名统计 */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <BarChart3 className="w-5 h-5" />
                  {t('analytics.bySendingDomain')}
                </CardTitle>
                <CardDescription>
                  {t('analytics.bySendingDomainDesc')}
                </CardDescription>
              </CardHeader>
              <CardContent>
                {sortedSendingDomains.length === 0 ? (
                  <div className="text-center py-8 text-muted-foreground">
                    {t('analytics.noDataInRange')}
                  </div>
                ) : (
                  <div className="overflow-x-auto max-h-[500px] overflow-y-auto">
                    <Table>
                      <TableHeader className="sticky top-0 bg-background">
                        <TableRow>
                          <TableHead>{t('analytics.sendingDomain')}</TableHead>
                          <SortableHeader 
                            sortKey="send_count" 
                            currentSort={sendingSort} 
                            onSort={handleSendingSort}
                          >
                            {t('analytics.sendCount')}
                          </SortableHeader>
                          <SortableHeader 
                            sortKey="order_count" 
                            currentSort={sendingSort} 
                            onSort={handleSendingSort}
                          >
                            {t('analytics.orderCount')}
                          </SortableHeader>
                          <SortableHeader 
                            sortKey="total_amount" 
                            currentSort={sendingSort} 
                            onSort={handleSendingSort}
                          >
                            {t('analytics.transactionAmount')}
                          </SortableHeader>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {sortedSendingDomains.map((item, index) => (
                          <TableRow 
                            key={index}
                            className={item.order_count === 0 ? 'bg-red-50' : ''}
                          >
                            <TableCell className="font-medium">
                              <div className="truncate max-w-[200px]" title={item.domain}>
                                {item.domain}
                              </div>
                            </TableCell>
                            <TableCell className="text-right">
                              <Badge variant="outline" className="text-blue-600">{item.send_count.toLocaleString()}</Badge>
                            </TableCell>
                            <TableCell className="text-right">
                              {item.order_count === 0 ? (
                                <Badge variant="destructive">0</Badge>
                              ) : (
                                <Badge variant="secondary">{item.order_count}</Badge>
                              )}
                            </TableCell>
                            <TableCell className="text-right font-medium text-green-600">
                              {formatAmount(item.total_amount)}
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* 按落地页域名统计 */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <TrendingUp className="w-5 h-5" />
                  {t('analytics.byLandingDomain')}
                </CardTitle>
                <CardDescription>
                  {t('analytics.byLandingDomainDesc')}
                </CardDescription>
              </CardHeader>
              <CardContent>
                {sortedLandingDomains.length === 0 ? (
                  <div className="text-center py-8 text-muted-foreground">
                    {t('analytics.noDataInRange')}
                  </div>
                ) : (
                  <div className="overflow-x-auto max-h-[500px] overflow-y-auto">
                    <Table>
                      <TableHeader className="sticky top-0 bg-background">
                        <TableRow>
                          <TableHead>{t('analytics.landingDomain')}</TableHead>
                          <SortableHeader 
                            sortKey="order_count" 
                            currentSort={landingSort} 
                            onSort={handleLandingSort}
                          >
                            {t('analytics.orderCount')}
                          </SortableHeader>
                          <SortableHeader 
                            sortKey="total_amount" 
                            currentSort={landingSort} 
                            onSort={handleLandingSort}
                          >
                            {t('analytics.transactionAmount')}
                          </SortableHeader>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {sortedLandingDomains.map((item, index) => (
                          <TableRow key={index}>
                            <TableCell className="font-medium">
                              <div className="truncate max-w-[200px]" title={item.domain}>
                                {item.domain}
                              </div>
                            </TableCell>
                            <TableCell className="text-right">
                              <Badge variant="secondary">{item.order_count}</Badge>
                            </TableCell>
                            <TableCell className="text-right font-medium text-green-600">
                              {formatAmount(item.total_amount)}
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>

          {/* 未出单域名列表 */}
          {data.domains_without_orders.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-red-600">
                  <AlertTriangle className="w-5 h-5" />
                  {t('analytics.domainsWithoutOrdersTitle')}
                </CardTitle>
                <CardDescription>
                  {t('analytics.domainsWithoutOrdersDesc')}
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="flex flex-wrap gap-2">
                  {data.domains_without_orders.map((domain, index) => (
                    <Badge key={index} variant="outline" className="text-red-600 border-red-300">
                      {domain}
                    </Badge>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}
        </>
      ) : null}
    </div>
  )
}
