import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Search, Users } from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
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
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { api } from '@/lib/api'

interface ManagedUser {
  id: number
  name: string
  email: string
  role: 'admin' | 'user'
  send_quota: number | null
  sent_quota_used: number
  remaining_quota: number | null
  status: 'active' | 'banned'
  created_at: string
}

interface UsersResponse {
  data: ManagedUser[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

interface UserFormData {
  send_quota: string
  sent_quota_used: string
  status: 'active' | 'banned'
}

export default function UsersPage() {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('all')
  const [editingUser, setEditingUser] = useState<ManagedUser | null>(null)
  const [formData, setFormData] = useState<UserFormData>({
    send_quota: '',
    sent_quota_used: '0',
    status: 'active',
  })

  const { data, isLoading } = useQuery<UsersResponse>({
    queryKey: ['users', page, search, status],
    queryFn: async () => {
      const response = await api.get('/users', {
        params: {
          page,
          search: search || undefined,
          status: status === 'all' ? undefined : status,
        },
      })
      return response.data
    },
  })

  const updateMutation = useMutation({
    mutationFn: async ({ id, values }: { id: number; values: UserFormData }) => {
      return api.put(`/users/${id}`, {
        send_quota: values.send_quota === '' ? null : Number(values.send_quota),
        sent_quota_used: Number(values.sent_quota_used || 0),
        status: values.status,
      })
    },
    onSuccess: () => {
      toast.success('用户设置已更新')
      queryClient.invalidateQueries({ queryKey: ['users'] })
      setEditingUser(null)
    },
  })

  const users = data?.data ?? []
  const meta = data?.meta

  const openEditDialog = (user: ManagedUser) => {
    setEditingUser(user)
    setFormData({
      send_quota: user.send_quota === null ? '' : String(user.send_quota),
      sent_quota_used: String(user.sent_quota_used),
      status: user.status,
    })
  }

  const submitUpdate = () => {
    if (!editingUser) return
    updateMutation.mutate({ id: editingUser.id, values: formData })
  }

  const formatQuota = (value: number | null) => {
    return value === null ? '无限制' : value.toLocaleString()
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">用户管理</h1>
        <p className="text-muted-foreground mt-2">管理用户发送额度、已发送额度和账号状态</p>
      </div>

      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <Users className="w-5 h-5" />
            <CardTitle>用户列表</CardTitle>
          </div>
          <CardDescription>封禁用户后会立即删除其登录令牌，并阻止继续发送</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-col gap-3 md:flex-row md:items-center">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={search}
                onChange={(event) => {
                  setSearch(event.target.value)
                  setPage(1)
                }}
                placeholder="搜索用户名或邮箱..."
                className="pl-9"
              />
            </div>
            <Select
              value={status}
              onValueChange={(value) => {
                setStatus(value)
                setPage(1)
              }}
            >
              <SelectTrigger className="w-full md:w-40">
                <SelectValue placeholder="账号状态" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">全部状态</SelectItem>
                <SelectItem value="active">已激活</SelectItem>
                <SelectItem value="banned">已封禁</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>用户</TableHead>
                <TableHead>角色</TableHead>
                <TableHead>状态</TableHead>
                <TableHead>总额度</TableHead>
                <TableHead>已发送</TableHead>
                <TableHead>剩余额度</TableHead>
                <TableHead className="text-right">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-muted-foreground">
                    加载中...
                  </TableCell>
                </TableRow>
              ) : users.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-muted-foreground">
                    暂无用户
                  </TableCell>
                </TableRow>
              ) : (
                users.map((user) => (
                  <TableRow key={user.id}>
                    <TableCell>
                      <div className="font-medium">{user.name}</div>
                      <div className="text-sm text-muted-foreground">{user.email}</div>
                    </TableCell>
                    <TableCell>{user.role === 'admin' ? '管理员' : '普通用户'}</TableCell>
                    <TableCell>
                      <Badge variant={user.status === 'active' ? 'default' : 'destructive'}>
                        {user.status === 'active' ? '已激活' : '已封禁'}
                      </Badge>
                    </TableCell>
                    <TableCell>{formatQuota(user.send_quota)}</TableCell>
                    <TableCell>{user.sent_quota_used.toLocaleString()}</TableCell>
                    <TableCell>{formatQuota(user.remaining_quota)}</TableCell>
                    <TableCell className="text-right">
                      <Button variant="outline" size="sm" onClick={() => openEditDialog(user)}>
                        编辑
                      </Button>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>

          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-muted-foreground">共 {meta.total} 个用户</p>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page <= 1}
                  onClick={() => setPage((current) => current - 1)}
                >
                  上一页
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page >= meta.last_page}
                  onClick={() => setPage((current) => current + 1)}
                >
                  下一页
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={!!editingUser} onOpenChange={(open) => !open && setEditingUser(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>编辑用户设置</DialogTitle>
            <DialogDescription>{editingUser?.email}</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="send_quota">总发送额度</Label>
              <Input
                id="send_quota"
                type="number"
                min="0"
                value={formData.send_quota}
                onChange={(event) => setFormData({ ...formData, send_quota: event.target.value })}
                placeholder="留空表示无限制"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="sent_quota_used">当前已发送额度</Label>
              <Input
                id="sent_quota_used"
                type="number"
                min="0"
                value={formData.sent_quota_used}
                onChange={(event) =>
                  setFormData({ ...formData, sent_quota_used: event.target.value })
                }
              />
            </div>
            <div className="space-y-2">
              <Label>账号状态</Label>
              <Select
                value={formData.status}
                onValueChange={(value: 'active' | 'banned') =>
                  setFormData({ ...formData, status: value })
                }
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="active">激活</SelectItem>
                  <SelectItem value="banned">封禁</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setEditingUser(null)}>
                取消
              </Button>
              <Button onClick={submitUpdate} disabled={updateMutation.isPending}>
                {updateMutation.isPending ? '保存中...' : '保存'}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
