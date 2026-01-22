import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function formatDate(date: string | Date): string {
  const d = new Date(date)
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${year}/${month}/${day}`
}

export function formatDateTime(date: string | Date): string {
  const d = new Date(date)
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  const hour = String(d.getHours()).padStart(2, '0')
  const minute = String(d.getMinutes()).padStart(2, '0')
  return `${year}/${month}/${day} ${hour}:${minute}`
}

export function formatNumber(num: number): string {
  return new Intl.NumberFormat('zh-CN').format(num)
}

export function formatPercentage(value: number, total: number): string {
  if (total === 0) return '0%'
  return `${((value / total) * 100).toFixed(2)}%`
}

/**
 * 邮箱脱敏处理
 * example@gmail.com → exa***@gmail.com
 * ab@gmail.com → a***@gmail.com
 * a@gmail.com → ***@gmail.com
 */
export function maskEmail(email: string): string {
  if (!email || !email.includes('@')) return email
  
  const [localPart, domain] = email.split('@')
  
  if (localPart.length <= 1) {
    return `***@${domain}`
  } else if (localPart.length <= 3) {
    return `${localPart[0]}***@${domain}`
  } else {
    return `${localPart.slice(0, 3)}***@${domain}`
  }
}
