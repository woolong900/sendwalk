import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CheckCircle, XCircle, UserX, Mail, Search } from 'lucide-react'
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
import { api } from '@/lib/api'
import { formatDateTime, maskEmail } from '@/lib/utils'

interface DeliveriesDialogProps {
  campaignId: number | null
  campaignName: string
  open: boolean
  onClose: () => void
}

interface BouncesDialogProps {
  campaignId: number | null
  campaignName: string
  open: boolean
  onClose: () => void
}

interface UnsubscribesDialogProps {
  campaignId: number | null
  campaignName: string
  open: boolean
  onClose: () => void
}

export function DeliveriesDialog({ campaignId, campaignName, open, onClose }: DeliveriesDialogProps) {
  const [searchTerm, setSearchTerm] = useState('')
  const [currentPage, setCurrentPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['campaign-deliveries', campaignId, searchTerm, currentPage],
    queryFn: async () => {
      if (!campaignId) return null
      const response = await api.get(`/campaigns/${campaignId}/deliveries`, {
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
            <CheckCircle className="w-5 h-5 text-green-500" />
            送达记录 - {campaignName}
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
              {searchTerm ? '未找到匹配的送达记录' : '暂无送达记录'}
            </div>
          ) : (
            <Table className="min-w-[900px]">
              <colgroup>
                <col className="w-[250px]" />
                <col className="w-[200px]" />
                <col className="w-[250px]" />
                <col className="w-[200px]" />
              </colgroup>
              <TableHeader>
                <TableRow>
                  <TableHead className="whitespace-nowrap">邮箱地址</TableHead>
                  <TableHead className="whitespace-nowrap">SMTP 服务器</TableHead>
                  <TableHead className="whitespace-nowrap">发件地址</TableHead>
                  <TableHead className="whitespace-nowrap">送达时间</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.data.map((delivery: any) => (
                  <TableRow key={delivery.id}>
                    <TableCell className="whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <Mail className="w-4 h-4 text-muted-foreground flex-shrink-0" />
                        <div className="truncate">{maskEmail(delivery.email)}</div>
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <div className="truncate">
                        {delivery.smtp_server?.name || delivery.smtp_server_name || '-'}
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <div className="truncate">
                        {delivery.from_email || '-'}
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {formatDateTime(delivery.completed_at)}
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
              第 {data.current_page} 页，共 {data.last_page} 页
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

export function BouncesDialog({ campaignId, campaignName, open, onClose }: BouncesDialogProps) {
  const [searchTerm, setSearchTerm] = useState('')
  const [currentPage, setCurrentPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['campaign-bounces', campaignId, searchTerm, currentPage],
    queryFn: async () => {
      if (!campaignId) return null
      const response = await api.get(`/campaigns/${campaignId}/bounces`, {
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
      <DialogContent className="max-w-6xl max-h-[90vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <XCircle className="w-5 h-5 text-orange-500" />
            弹回记录 - {campaignName}
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
              {searchTerm ? '未找到匹配的弹回记录' : '暂无弹回记录'}
            </div>
          ) : (
            <Table className="min-w-[1000px]">
              <colgroup>
                <col className="w-[250px]" />
                <col className="w-[100px]" />
                <col className="w-[150px]" />
                <col className="w-[300px]" />
                <col className="w-[200px]" />
              </colgroup>
              <TableHeader>
                <TableRow>
                  <TableHead className="whitespace-nowrap">邮箱地址</TableHead>
                  <TableHead className="whitespace-nowrap">弹回类型</TableHead>
                  <TableHead className="whitespace-nowrap">错误代码</TableHead>
                  <TableHead className="whitespace-nowrap">错误信息</TableHead>
                  <TableHead className="whitespace-nowrap">弹回时间</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.data.map((bounce: any) => (
                  <TableRow key={bounce.id}>
                    <TableCell className="whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <Mail className="w-4 h-4 text-muted-foreground flex-shrink-0" />
                        <div className="truncate">{maskEmail(bounce.email)}</div>
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <span className={`text-xs px-2 py-1 rounded ${
                        bounce.bounce_type === 'hard' 
                          ? 'bg-red-100 text-red-700' 
                          : 'bg-yellow-100 text-yellow-700'
                      }`}>
                        {bounce.bounce_type === 'hard' ? '硬弹回' : '软弹回'}
                      </span>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <code className="text-xs bg-muted px-2 py-1 rounded">
                        {bounce.error_code || '-'}
                      </code>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <div className="truncate text-sm">
                        {bounce.error_message || bounce.smtp_response || '-'}
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {formatDateTime(bounce.created_at)}
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
              第 {data.current_page} 页，共 {data.last_page} 页
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

export function UnsubscribesDialog({ campaignId, campaignName, open, onClose }: UnsubscribesDialogProps) {
  const [searchTerm, setSearchTerm] = useState('')
  const [currentPage, setCurrentPage] = useState(1)

  const { data, isLoading } = useQuery({
    queryKey: ['campaign-unsubscribes', campaignId, searchTerm, currentPage],
    queryFn: async () => {
      if (!campaignId) return null
      const response = await api.get(`/campaigns/${campaignId}/unsubscribes`, {
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
            <UserX className="w-5 h-5 text-blue-500" />
            取消订阅记录 - {campaignName}
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
              {searchTerm ? '未找到匹配的取消订阅记录' : '暂无取消订阅记录'}
            </div>
          ) : (
            <Table className="min-w-[800px]">
              <colgroup>
                <col className="w-[300px]" />
                <col className="w-[250px]" />
                <col className="w-[250px]" />
              </colgroup>
              <TableHeader>
                <TableRow>
                  <TableHead className="whitespace-nowrap">邮箱地址</TableHead>
                  <TableHead className="whitespace-nowrap">所属列表</TableHead>
                  <TableHead className="whitespace-nowrap">取消订阅时间</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.data.map((unsubscribe: any) => (
                  <TableRow key={unsubscribe.id}>
                    <TableCell className="whitespace-nowrap">
                      <div className="flex items-center gap-2">
                        <Mail className="w-4 h-4 text-muted-foreground flex-shrink-0" />
                        <div className="truncate">{maskEmail(unsubscribe.email)}</div>
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <div className="truncate">
                        {unsubscribe.list_name}
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                      {formatDateTime(unsubscribe.unsubscribed_at)}
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
              第 {data.current_page} 页，共 {data.last_page} 页
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

