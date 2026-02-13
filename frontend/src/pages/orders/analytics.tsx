import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BarChart3, AlertTriangle, TrendingUp, DollarSign, Package, Mail } from 'lucide-react'
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

const timeRanges = [
  { value: 'today', label: '今天' },
  { value: 'yesterday', label: '昨天' },
  { value: '3days', label: '最近3天' },
  { value: 'week', label: '最近一周' },
  { value: 'month', label: '最近一个月' },
]

export default function OrderAnalyticsPage() {
  const [selectedRange, setSelectedRange] = useState('today')

  const { data, isLoading } = useQuery<AnalyticsData>({
    queryKey: ['order-analytics', selectedRange],
    queryFn: async () => {
      const response = await api.get(`/orders/analytics?range=${selectedRange}`)
      return response.data
    },
  })

  const formatAmount = (amount: number | string | null | undefined) => {
    const num = Number(amount) || 0
    return `$${num.toFixed(2)}`
  }

  const getRangeLabel = (value: string) => {
    return timeRanges.find(r => r.value === value)?.label || value
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl md:text-2xl font-bold">数据分析</h1>
          <p className="text-muted-foreground mt-1">
            订单数据多维度分析
          </p>
        </div>
      </div>

      {/* 时间范围选择器 */}
      <div className="flex flex-wrap gap-2">
        {timeRanges.map((range) => (
          <Button
            key={range.value}
            variant={selectedRange === range.value ? 'default' : 'outline'}
            size="sm"
            onClick={() => setSelectedRange(range.value)}
          >
            {range.label}
          </Button>
        ))}
      </div>

      {isLoading ? (
        <div className="text-center py-8 text-muted-foreground">加载中...</div>
      ) : data ? (
        <>
          {/* 汇总统计卡片 */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                  <Mail className="w-4 h-4" />
                  {getRangeLabel(selectedRange)}发信量
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
                  {getRangeLabel(selectedRange)}订单数
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
                  {getRangeLabel(selectedRange)}成交金额
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
                  未出单域名数
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="text-3xl font-bold text-orange-600">
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
                  按发件域名统计
                </CardTitle>
                <CardDescription>
                  按发信量倒序排列
                </CardDescription>
              </CardHeader>
              <CardContent>
                {data.by_sending_domain.length === 0 ? (
                  <div className="text-center py-8 text-muted-foreground">
                    该时间段内无数据
                  </div>
                ) : (
                  <div className="overflow-x-auto">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>发件域名</TableHead>
                          <TableHead className="text-right">发信量</TableHead>
                          <TableHead className="text-right">出单量</TableHead>
                          <TableHead className="text-right">成交金额</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {data.by_sending_domain.map((item, index) => (
                          <TableRow key={index}>
                            <TableCell className="font-medium">
                              <div className="truncate max-w-[200px]" title={item.domain}>
                                {item.domain}
                              </div>
                            </TableCell>
                            <TableCell className="text-right">
                              <Badge variant="outline" className="text-blue-600">{item.send_count.toLocaleString()}</Badge>
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

            {/* 按落地页域名统计 */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <TrendingUp className="w-5 h-5" />
                  按落地页域名统计
                </CardTitle>
                <CardDescription>
                  按出单量倒序排列
                </CardDescription>
              </CardHeader>
              <CardContent>
                {data.by_landing_domain.length === 0 ? (
                  <div className="text-center py-8 text-muted-foreground">
                    该时间段内无数据
                  </div>
                ) : (
                  <div className="overflow-x-auto">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>落地页域名</TableHead>
                          <TableHead className="text-right">出单量</TableHead>
                          <TableHead className="text-right">成交金额</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {data.by_landing_domain.map((item, index) => (
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
                <CardTitle className="flex items-center gap-2 text-orange-600">
                  <AlertTriangle className="w-5 h-5" />
                  未出单域名
                </CardTitle>
                <CardDescription>
                  以下域名在 DOMAIN 标签中，但在选定时间范围内没有订单
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="flex flex-wrap gap-2">
                  {data.domains_without_orders.map((domain, index) => (
                    <Badge key={index} variant="outline" className="text-orange-600 border-orange-300">
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
