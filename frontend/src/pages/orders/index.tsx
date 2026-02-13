import { useState } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Search, RefreshCw, ExternalLink, Calendar, TrendingUp } from 'lucide-react'
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

interface Order {
  id: number
  order_number: string
  product_names: string | null
  customer_email: string | null
  total_price: number
  payment_method: string | null
  paid_at: string | null
  utm_source: string | null
  transaction_no: string | null
  domain: string | null
  landing_page: string | null
  utm_medium: string | null
  created_at: string
}

interface PaginatedResponse {
  data: Order[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

interface OrderStats {
  total_orders: number
  total_amount: number
  by_utm_source: { utm_source: string | null; count: number; amount: number }[]
  by_domain: { domain: string | null; count: number; amount: number }[]
}

export default function OrdersPage() {
  const [searchQuery, setSearchQuery] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [isSyncOpen, setIsSyncOpen] = useState(false)
  const [syncAll, setSyncAll] = useState(false)
  const [syncDays, setSyncDays] = useState(2)

  // 获取订单列表
  const { data: ordersData, isLoading, refetch } = useQuery<PaginatedResponse>({
    queryKey: ['orders', currentPage, searchQuery, startDate, endDate],
    queryFn: async () => {
      const params = new URLSearchParams({
        page: currentPage.toString(),
        per_page: '20',
      })
      
      if (searchQuery) {
        params.append('search', searchQuery)
      }
      if (startDate) {
        params.append('start_date', startDate)
      }
      if (endDate) {
        params.append('end_date', endDate)
      }
      
      const response = await api.get(`/orders?${params}`)
      return response.data
    },
  })

  // 获取统计数据
  const { data: stats } = useQuery<OrderStats>({
    queryKey: ['orders-stats', startDate, endDate],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (startDate) {
        params.append('start_date', startDate)
      }
      if (endDate) {
        params.append('end_date', endDate)
      }
      const response = await api.get(`/orders/stats?${params}`)
      return response.data
    },
  })

  // 同步订单
  const syncMutation = useMutation({
    mutationFn: async () => {
      return api.post('/orders/sync', {
        all: syncAll,
        days: syncDays,
      })
    },
    onSuccess: (response) => {
      toast.success(response.data.message)
      setIsSyncOpen(false)
      // 延迟刷新列表
      setTimeout(() => refetch(), 3000)
    },
    onError: () => {
      toast.error('同步启动失败')
    },
  })

  const handleSync = () => {
    syncMutation.mutate()
  }

  const formatDate = (dateStr: string | null) => {
    if (!dateStr) return '-'
    const d = new Date(dateStr)
    return `${d.getFullYear()}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
  }

  const formatAmount = (amount: number | string | null | undefined) => {
    const num = Number(amount) || 0
    return `$${num.toFixed(2)}`
  }

  const orders = ordersData?.data || []
  const totalPages = ordersData?.last_page || 1
  const total = ordersData?.total || 0

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl md:text-2xl font-bold">订单管理</h1>
          <p className="text-muted-foreground mt-1">
            查看和管理同步的订单数据
          </p>
        </div>
        <div className="flex gap-2">
          <Dialog open={isSyncOpen} onOpenChange={setIsSyncOpen}>
            <DialogTrigger asChild>
              <Button>
                <RefreshCw className="w-4 h-4 mr-2" />
                同步订单
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>同步订单</DialogTitle>
                <DialogDescription>
                  从外部系统拉取订单数据
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4">
                <div className="flex items-center gap-4">
                  <label className="flex items-center gap-2">
                    <input
                      type="radio"
                      checked={!syncAll}
                      onChange={() => setSyncAll(false)}
                    />
                    <span>增量同步</span>
                  </label>
                  <label className="flex items-center gap-2">
                    <input
                      type="radio"
                      checked={syncAll}
                      onChange={() => setSyncAll(true)}
                    />
                    <span>全量同步</span>
                  </label>
                </div>
                
                {!syncAll && (
                  <div className="space-y-2">
                    <Label>同步最近几天的订单</Label>
                    <Input
                      type="number"
                      min={1}
                      max={30}
                      value={syncDays}
                      onChange={(e) => setSyncDays(parseInt(e.target.value) || 2)}
                    />
                  </div>
                )}
                
                <div className="flex justify-end gap-2">
                  <Button variant="outline" onClick={() => setIsSyncOpen(false)}>
                    取消
                  </Button>
                  <Button onClick={handleSync} disabled={syncMutation.isPending}>
                    {syncMutation.isPending ? '同步中...' : '开始同步'}
                  </Button>
                </div>
              </div>
            </DialogContent>
          </Dialog>
        </div>
      </div>

      {/* 统计卡片 */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">
              订单总数
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.total_orders?.toLocaleString() || 0}</div>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground">
              总金额
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{formatAmount(stats?.total_amount || 0)}</div>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-1">
              <TrendingUp className="w-4 h-4" />
              Top UTM来源
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-1">
              {stats?.by_utm_source?.slice(0, 3).map((item, index) => (
                <div key={index} className="flex items-center justify-between text-sm">
                  <span className="truncate">{item.utm_source || '(空)'}</span>
                  <span className="text-muted-foreground">{item.count}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
        
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-1">
              <ExternalLink className="w-4 h-4" />
              Top 域名
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-1">
              {stats?.by_domain?.slice(0, 3).map((item, index) => (
                <div key={index} className="flex items-center justify-between text-sm">
                  <span className="truncate">{item.domain || '(空)'}</span>
                  <span className="text-muted-foreground">{item.count}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* 订单列表 */}
      <Card>
        <CardHeader>
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
              <CardTitle>订单列表</CardTitle>
              <CardDescription>共 {total} 条订单</CardDescription>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <div className="flex items-center gap-2">
                <Calendar className="w-4 h-4 text-muted-foreground" />
                <Input
                  type="date"
                  value={startDate}
                  onChange={(e) => setStartDate(e.target.value)}
                  className="w-36"
                  placeholder="开始日期"
                />
                <span className="text-muted-foreground">-</span>
                <Input
                  type="date"
                  value={endDate}
                  onChange={(e) => setEndDate(e.target.value)}
                  className="w-36"
                  placeholder="结束日期"
                />
              </div>
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground w-4 h-4" />
                <Input
                  placeholder="搜索订单号/邮箱/商品..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="pl-9 w-64"
                />
              </div>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-8 text-muted-foreground">加载中...</div>
          ) : orders.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              {searchQuery || startDate || endDate ? '没有找到匹配的订单' : '暂无订单数据，点击"同步订单"拉取数据'}
            </div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <Table className="min-w-[1200px]">
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-[160px]">订单编号</TableHead>
                      <TableHead className="w-[200px]">商品名称</TableHead>
                      <TableHead className="w-[180px]">顾客邮箱</TableHead>
                      <TableHead className="w-[100px]">总价</TableHead>
                      <TableHead className="w-[100px]">支付方式</TableHead>
                      <TableHead className="w-[150px]">支付时间</TableHead>
                      <TableHead className="w-[100px]">UTM来源</TableHead>
                      <TableHead className="w-[150px]">域名</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {orders.map((order) => (
                      <TableRow key={order.id}>
                        <TableCell className="font-mono text-sm">
                          <div className="truncate" title={order.order_number}>
                            {order.order_number}
                          </div>
                        </TableCell>
                        <TableCell>
                          <div className="truncate max-w-[200px]" title={order.product_names || ''}>
                            {order.product_names || '-'}
                          </div>
                        </TableCell>
                        <TableCell>
                          <div className="truncate" title={order.customer_email || ''}>
                            {order.customer_email || '-'}
                          </div>
                        </TableCell>
                        <TableCell className="font-medium">
                          {formatAmount(order.total_price)}
                        </TableCell>
                        <TableCell>
                          {order.payment_method ? (
                            <Badge variant="secondary">{order.payment_method}</Badge>
                          ) : '-'}
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">
                          {formatDate(order.paid_at)}
                        </TableCell>
                        <TableCell>
                          {order.utm_source ? (
                            <Badge variant="outline">{order.utm_source}</Badge>
                          ) : '-'}
                        </TableCell>
                        <TableCell>
                          <div className="truncate max-w-[150px]" title={order.domain || ''}>
                            {order.domain || '-'}
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>

              {totalPages > 1 && (
                <div className="flex items-center justify-between mt-4">
                  <div className="text-sm text-muted-foreground">
                    第 {currentPage} 页，共 {totalPages} 页
                  </div>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(1)}
                      disabled={currentPage === 1}
                    >
                      首页
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(currentPage - 1)}
                      disabled={currentPage === 1}
                    >
                      上一页
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(currentPage + 1)}
                      disabled={currentPage === totalPages}
                    >
                      下一页
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(totalPages)}
                      disabled={currentPage === totalPages}
                    >
                      尾页
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
