import { useState } from 'react'
import { Skeleton } from '@/components/ui/skeleton'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, Edit, Trash2, Users, Search, ListFilter, Clock, Zap, Settings2, X } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Card,
  CardContent,
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { api } from '@/lib/api'
import { formatDateTime } from '@/lib/utils'
import { useConfirm } from '@/hooks/use-confirm'

// 条件规则类型
type RuleType = 'in_list' | 'not_in_list' | 'has_opened' | 'has_delivered'

interface ConditionRule {
  type: RuleType
  list_id?: number
  value?: boolean
}

interface Conditions {
  logic: 'and' | 'or'
  rules: ConditionRule[]
}

interface MailingList {
  id: number
  name: string
  description: string
  type: 'manual' | 'auto'
  conditions: Conditions | null
  subscribers_count: number
  unsubscribed_count: number
  created_at: string
  updated_at: string
}

interface ListsResponse {
  data: MailingList[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  stats: {
    total_lists: number
    total_subscribers: number
    total_unsubscribed: number
  }
}

// 条件规则选项
const ruleTypeOptions: { value: RuleType; label: string; needsList: boolean; needsValue: boolean }[] = [
  { value: 'in_list', label: '存在于列表', needsList: true, needsValue: false },
  { value: 'not_in_list', label: '不存在于列表', needsList: true, needsValue: false },
  { value: 'has_opened', label: '是否打开过邮件', needsList: false, needsValue: true },
  { value: 'has_delivered', label: '是否送达过邮件', needsList: false, needsValue: true },
]

// 默认空条件
const getDefaultConditions = (): Conditions => ({
  logic: 'and',
  rules: [{ type: 'in_list', list_id: undefined }],
})

export default function ListsPage() {
  const { confirm, ConfirmDialog } = useConfirm()
  
  const navigate = useNavigate()
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const [isEditOpen, setIsEditOpen] = useState(false)
  const [editingList, setEditingList] = useState<MailingList | null>(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  
  // 表单数据
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    type: 'manual' as 'manual' | 'auto',
    conditions: getDefaultConditions(),
  })

  const queryClient = useQueryClient()

  // 获取列表
  const { data: listsResponse, isLoading } = useQuery<ListsResponse>({
    queryKey: ['lists', currentPage],
    queryFn: async () => {
      const response = await api.get(`/lists?page=${currentPage}`)
      return response.data
    },
  })
  
  // 获取所有列表（用于条件选择）
  const { data: allListsResponse } = useQuery<{ data: MailingList[] }>({
    queryKey: ['lists-all'],
    queryFn: async () => {
      const response = await api.get('/lists?all=true')
      return response.data
    },
  })
  
  const lists = listsResponse?.data || []
  const meta = listsResponse?.meta
  const stats = listsResponse?.stats
  const allLists = allListsResponse?.data || []

  // 创建列表
  const createMutation = useMutation({
    mutationFn: async (data: typeof formData) => {
      const payload: Record<string, unknown> = {
        name: data.name,
        description: data.description,
        type: data.type,
      }
      if (data.type === 'auto') {
        payload.conditions = data.conditions
      }
      return api.post('/lists', payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lists'] })
      queryClient.invalidateQueries({ queryKey: ['lists-all'] })
      toast.success('列表创建成功')
      setIsCreateOpen(false)
      setCurrentPage(1)
      resetForm()
    },
  })

  // 更新列表
  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: typeof formData }) => {
      const payload: Record<string, unknown> = {
        name: data.name,
        description: data.description,
        type: data.type,
      }
      if (data.type === 'auto') {
        payload.conditions = data.conditions
      }
      return api.put(`/lists/${id}`, payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lists'] })
      queryClient.invalidateQueries({ queryKey: ['lists-all'] })
      toast.success('列表更新成功')
      setIsEditOpen(false)
      setEditingList(null)
      resetForm()
    },
  })

  // 删除列表
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/lists/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lists'] })
      queryClient.invalidateQueries({ queryKey: ['lists-all'] })
      toast.success('列表删除成功')
      if (lists.length === 1 && currentPage > 1) {
        setCurrentPage(currentPage - 1)
      }
    },
  })

  const resetForm = () => {
    setFormData({
      name: '',
      description: '',
      type: 'manual',
      conditions: getDefaultConditions(),
    })
  }

  const handleCreate = () => {
    resetForm()
    setIsCreateOpen(true)
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    createMutation.mutate(formData)
  }

  const handleEdit = (list: MailingList) => {
    setEditingList(list)
    setFormData({
      name: list.name,
      description: list.description || '',
      type: list.type || 'manual',
      conditions: list.conditions || getDefaultConditions(),
    })
    setIsEditOpen(true)
  }

  const handleUpdate = (e: React.FormEvent) => {
    e.preventDefault()
    if (editingList) {
      updateMutation.mutate({ id: editingList.id, data: formData })
    }
  }

  const handleDelete = async (list: MailingList) => {
    const confirmed = await confirm({
      title: '删除邮件列表',
      description: `确定要删除列表"${list.name}"吗？\n\n注意：删除后订阅者不会被删除，只会解除与该列表的关联。`,
      confirmText: '删除',
      cancelText: '取消',
      variant: 'destructive',
    })
    if (confirmed) {
      deleteMutation.mutate(list.id)
    }
  }

  // 添加条件规则
  const addRule = () => {
    setFormData({
      ...formData,
      conditions: {
        ...formData.conditions,
        rules: [...formData.conditions.rules, { type: 'in_list', list_id: undefined }],
      },
    })
  }

  // 删除条件规则
  const removeRule = (index: number) => {
    const newRules = formData.conditions.rules.filter((_, i) => i !== index)
    setFormData({
      ...formData,
      conditions: {
        ...formData.conditions,
        rules: newRules.length > 0 ? newRules : [{ type: 'in_list', list_id: undefined }],
      },
    })
  }

  // 更新条件规则
  const updateRule = (index: number, updates: Partial<ConditionRule>) => {
    const newRules = [...formData.conditions.rules]
    newRules[index] = { ...newRules[index], ...updates }
    setFormData({
      ...formData,
      conditions: {
        ...formData.conditions,
        rules: newRules,
      },
    })
  }

  // 搜索过滤
  const filteredLists = lists.filter((list) =>
    list.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
    list.description?.toLowerCase().includes(searchTerm.toLowerCase())
  )

  // 条件编辑器 JSX
  const conditionsEditorJSX = (
    <div className="space-y-4 border rounded-lg p-4 bg-muted/30">
      <div className="flex items-center justify-between">
        <Label className="text-sm font-medium">条件配置</Label>
        <Select
          value={formData.conditions.logic}
          onValueChange={(value: 'and' | 'or') =>
            setFormData({
              ...formData,
              conditions: { ...formData.conditions, logic: value },
            })
          }
        >
          <SelectTrigger className="w-44">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="and">全部满足 (AND)</SelectItem>
            <SelectItem value="or">任一满足 (OR)</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div className="space-y-3">
        {formData.conditions.rules.map((rule, index) => {
          const ruleOption = ruleTypeOptions.find(r => r.value === rule.type)
          return (
            <div key={index} className="flex items-center gap-2">
              <Select
                value={rule.type}
                onValueChange={(value: RuleType) => {
                  const newRuleOption = ruleTypeOptions.find(r => r.value === value)
                  updateRule(index, {
                    type: value,
                    list_id: newRuleOption?.needsList ? rule.list_id : undefined,
                    value: newRuleOption?.needsValue ? true : undefined,
                  })
                }}
              >
                <SelectTrigger className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {ruleTypeOptions.map(option => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              {ruleOption?.needsList && (
                <Select
                  value={rule.list_id?.toString() || ''}
                  onValueChange={(value) => updateRule(index, { list_id: parseInt(value) })}
                >
                  <SelectTrigger className="flex-1">
                    <SelectValue placeholder="选择列表" />
                  </SelectTrigger>
                  <SelectContent>
                    {allLists
                      .filter(l => l.type !== 'auto') // 不能选择自动列表
                      .map(list => (
                        <SelectItem key={list.id} value={list.id.toString()}>
                          {list.name}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              )}

              {ruleOption?.needsValue && (
                <Select
                  value={rule.value === true ? 'true' : 'false'}
                  onValueChange={(value) => updateRule(index, { value: value === 'true' })}
                >
                  <SelectTrigger className="w-24">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="true">是</SelectItem>
                    <SelectItem value="false">否</SelectItem>
                  </SelectContent>
                </Select>
              )}

              {formData.conditions.rules.length > 1 && (
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  onClick={() => removeRule(index)}
                >
                  <X className="w-4 h-4" />
                </Button>
              )}
            </div>
          )
        })}
      </div>

      <Button type="button" variant="outline" size="sm" onClick={addRule}>
        <Plus className="w-4 h-4 mr-1" />
        添加条件
      </Button>
    </div>
  )

  return (
    <div className="space-y-6">
      {/* 页头 */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl md:text-2xl font-bold tracking-tight">邮件列表</h1>
          <p className="text-muted-foreground mt-2">管理您的订阅者列表</p>
        </div>
        <Button onClick={handleCreate}>
          <Plus className="w-4 h-4 mr-2" />
          创建列表
        </Button>
      </div>

      {/* 统计卡片 */}
      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">总列表数</CardTitle>
            <ListFilter className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.total_lists || 0}</div>
            <p className="text-xs text-muted-foreground mt-1">
              已创建的邮件列表
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">总订阅者</CardTitle>
            <Users className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.total_subscribers || 0}</div>
            <p className="text-xs text-muted-foreground mt-1">
              所有列表的订阅者总数
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">总取消订阅</CardTitle>
            <Badge variant="secondary" className="text-xs bg-orange-100 text-orange-700">
              {stats?.total_unsubscribed || 0}
            </Badge>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-orange-600">{stats?.total_unsubscribed || 0}</div>
            <p className="text-xs text-muted-foreground mt-1">
              从所有列表取消订阅的总数
            </p>
          </CardContent>
        </Card>
      </div>

      {/* 搜索栏 */}
      {lists.length > 0 && (
        <div className="flex items-center gap-4">
          <div className="relative flex-1 max-w-sm">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="搜索列表名称或描述..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="pl-9"
            />
          </div>
          {searchTerm && (
            <p className="text-sm text-muted-foreground">
              找到 {filteredLists.length} 个结果
            </p>
          )}
        </div>
      )}

      {/* 列表表格 */}
      {isLoading ? (
        <Card>
          <div className="overflow-x-auto">
            <Table className="min-w-[800px]">
              <colgroup>
                <col className="w-[50px]" />
                <col className="w-[200px]" />
                <col className="w-[80px]" />
                <col className="w-[120px]" />
                <col className="w-[180px]" />
                <col className="w-[150px]" />
              </colgroup>
              <TableHeader>
                <TableRow>
                  <TableHead>ID</TableHead>
                  <TableHead>标题</TableHead>
                  <TableHead>类型</TableHead>
                  <TableHead className="text-center">订阅者</TableHead>
                  <TableHead>创建时间</TableHead>
                  <TableHead className="text-right">操作</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {[...Array(5)].map((_, i) => (
                  <TableRow key={i}>
                    <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-12" /></TableCell>
                    <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-40" /></TableCell>
                    <TableCell className="whitespace-nowrap"><Skeleton className="h-5 w-14" /></TableCell>
                    <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-16 mx-auto" /></TableCell>
                    <TableCell className="whitespace-nowrap"><Skeleton className="h-4 w-32" /></TableCell>
                    <TableCell className="whitespace-nowrap"><Skeleton className="h-8 w-24 ml-auto" /></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </Card>
      ) : lists.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Users className="w-12 h-12 text-muted-foreground mb-4" />
            <p className="text-lg font-medium mb-2">还没有邮件列表</p>
            <p className="text-muted-foreground mb-4">创建您的第一个列表开始收集订阅者</p>
            <Button onClick={handleCreate}>
              <Plus className="w-4 h-4 mr-2" />
              创建列表
            </Button>
          </CardContent>
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <Table className="min-w-[800px]">
              <colgroup>
                <col className="w-[50px]" />
                <col className="w-[200px]" />
                <col className="w-[80px]" />
                <col className="w-[120px]" />
                <col className="w-[180px]" />
                <col className="w-[150px]" />
              </colgroup>
              <TableHeader>
                <TableRow>
                  <TableHead>ID</TableHead>
                  <TableHead>标题</TableHead>
                  <TableHead>类型</TableHead>
                  <TableHead className="text-center">订阅者</TableHead>
                  <TableHead>创建时间</TableHead>
                  <TableHead className="text-right">操作</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredLists.map((list) => (
                  <TableRow 
                    key={list.id}
                    className="cursor-pointer hover:bg-muted/50 transition-colors"
                    onClick={() => navigate(`/lists/${list.id}/subscribers`)}
                  >
                    <TableCell className="font-mono text-muted-foreground whitespace-nowrap">
                      #{list.id}
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <div className="font-medium text-primary truncate">
                        {list.name}
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      {list.type === 'auto' ? (
                        <Badge variant="secondary" className="bg-purple-100 text-purple-700">
                          <Zap className="w-3 h-3 mr-1" />
                          自动
                        </Badge>
                      ) : (
                        <Badge variant="secondary" className="bg-blue-100 text-blue-700">
                          <Users className="w-3 h-3 mr-1" />
                          手动
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-center whitespace-nowrap">
                      <div className="flex items-center justify-center gap-1">
                        <Users className="w-4 h-4 text-muted-foreground flex-shrink-0" />
                        <span className="font-semibold">{list.subscribers_count || 0}</span>
                        {list.type === 'auto' && (
                          <Settings2 className="w-3 h-3 text-muted-foreground" />
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                      <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Clock className="w-4 h-4 flex-shrink-0" />
                        {formatDateTime(list.created_at)}
                      </div>
                    </TableCell>
                    <TableCell className="text-right whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
                      <div className="flex items-center justify-end -space-x-px">
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => handleEdit(list)}
                          title="编辑"
                          className="px-1.5"
                        >
                          <Edit className="w-4 h-4" />
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => handleDelete(list)}
                          disabled={deleteMutation.isPending}
                          title="删除"
                          className="px-1.5"
                        >
                          <Trash2 className="w-4 h-4 text-red-500" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          
          {/* 分页 */}
          {meta && meta.last_page > 1 && !searchTerm && (
            <div className="flex items-center justify-between border-t px-6 py-4">
              <p className="text-sm text-muted-foreground">
                第 {meta.current_page} 页，共 {meta.last_page} 页
              </p>
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
                  disabled={currentPage === meta.last_page}
                >
                  下一页
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage(meta.last_page)}
                  disabled={currentPage === meta.last_page}
                >
                  尾页
                </Button>
              </div>
            </div>
          )}
        </Card>
      )}

      {/* 创建对话框 */}
      <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>创建新列表</DialogTitle>
            <DialogDescription>创建一个新的邮件订阅者列表</DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="name">
                列表名称 <span className="text-red-500">*</span>
              </Label>
              <Input
                id="name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="例如：新闻订阅者"
                required
              />
            </div>
            
            <div className="space-y-2">
              <Label htmlFor="description">描述</Label>
              <Input
                id="description"
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                placeholder="列表的用途说明"
              />
            </div>

            <div className="space-y-3">
              <Label>列表类型</Label>
              <RadioGroup
                value={formData.type}
                onValueChange={(value: 'manual' | 'auto') => setFormData({ ...formData, type: value })}
                className="flex gap-4"
              >
                <div className="flex items-center space-x-2">
                  <RadioGroupItem value="manual" id="manual" />
                  <Label htmlFor="manual" className="font-normal cursor-pointer">
                    <div className="flex items-center gap-1.5">
                      <Users className="w-4 h-4" />
                      手动列表
                    </div>
                    <p className="text-xs text-muted-foreground mt-0.5">手动添加或上传联系人</p>
                  </Label>
                </div>
                <div className="flex items-center space-x-2">
                  <RadioGroupItem value="auto" id="auto" />
                  <Label htmlFor="auto" className="font-normal cursor-pointer">
                    <div className="flex items-center gap-1.5">
                      <Zap className="w-4 h-4" />
                      自动列表
                    </div>
                    <p className="text-xs text-muted-foreground mt-0.5">根据条件自动引用联系人</p>
                  </Label>
                </div>
              </RadioGroup>
            </div>

            {formData.type === 'auto' && conditionsEditorJSX}

            <div className="flex justify-end gap-2 pt-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsCreateOpen(false)}
              >
                取消
              </Button>
              <Button type="submit" disabled={createMutation.isPending}>
                {createMutation.isPending ? '创建中...' : '创建'}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* 编辑对话框 */}
      <Dialog open={isEditOpen} onOpenChange={setIsEditOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>编辑列表</DialogTitle>
            <DialogDescription>修改邮件列表信息</DialogDescription>
          </DialogHeader>
          <form onSubmit={handleUpdate} className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="edit-name">
                列表名称 <span className="text-red-500">*</span>
              </Label>
              <Input
                id="edit-name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="例如：新闻订阅者"
                required
              />
            </div>
            
            <div className="space-y-2">
              <Label htmlFor="edit-description">描述</Label>
              <Input
                id="edit-description"
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                placeholder="列表的用途说明"
              />
            </div>

            <div className="space-y-3">
              <Label>列表类型</Label>
              <RadioGroup
                value={formData.type}
                onValueChange={(value: 'manual' | 'auto') => setFormData({ ...formData, type: value })}
                className="flex gap-4"
              >
                <div className="flex items-center space-x-2">
                  <RadioGroupItem value="manual" id="edit-manual" />
                  <Label htmlFor="edit-manual" className="font-normal cursor-pointer">
                    <div className="flex items-center gap-1.5">
                      <Users className="w-4 h-4" />
                      手动列表
                    </div>
                    <p className="text-xs text-muted-foreground mt-0.5">手动添加或上传联系人</p>
                  </Label>
                </div>
                <div className="flex items-center space-x-2">
                  <RadioGroupItem value="auto" id="edit-auto" />
                  <Label htmlFor="edit-auto" className="font-normal cursor-pointer">
                    <div className="flex items-center gap-1.5">
                      <Zap className="w-4 h-4" />
                      自动列表
                    </div>
                    <p className="text-xs text-muted-foreground mt-0.5">根据条件自动引用联系人</p>
                  </Label>
                </div>
              </RadioGroup>
            </div>

            {formData.type === 'auto' && conditionsEditorJSX}

            <div className="flex justify-end gap-2 pt-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setIsEditOpen(false)
                  setEditingList(null)
                  resetForm()
                }}
              >
                取消
              </Button>
              <Button type="submit" disabled={updateMutation.isPending}>
                {updateMutation.isPending ? '更新中...' : '保存'}
              </Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>

      {/* 确认对话框 */}
      <ConfirmDialog />
    </div>
  )
}
