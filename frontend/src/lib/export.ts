import { api } from '@/lib/api'

/**
 * 导出指定列表的订阅者为 CSV 文件
 * 支持透传搜索和状态筛选条件
 */
export async function exportListSubscribers(
  listId: number,
  listName: string,
  filters: { search?: string; status?: string } = {}
): Promise<void> {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.status && filters.status !== 'all') params.status = filters.status

  const response = await api.get(`/lists/${listId}/export`, {
    params,
    responseType: 'blob',
  })

  const url = window.URL.createObjectURL(response.data)
  const link = document.createElement('a')
  const safeName = (listName || `list-${listId}`).replace(/[^\w\u4e00-\u9fa5.-]+/g, '_')
  const now = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  const timestamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`

  link.href = url
  link.download = `${safeName}-subscribers-${timestamp}.csv`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  window.URL.revokeObjectURL(url)
}
