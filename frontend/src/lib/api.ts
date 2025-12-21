import axios, { AxiosError, AxiosRequestConfig } from 'axios'
import { toast } from 'sonner'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'

export const api = axios.create({
  baseURL: API_URL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: true,
})

// Request interceptor
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('auth_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<{ message: string; errors?: Record<string, string[]> }>) => {
    const message = error.response?.data?.message || '请求失败，请稍后重试'

    // Handle validation errors
    if (error.response?.status === 422 && error.response.data.errors) {
      const errors = error.response.data.errors
      const firstError = Object.values(errors)[0]?.[0]
      toast.error(firstError || message)
    }
    // Handle authentication errors
    else if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      window.location.href = '/auth/login'
    }
    // Handle other errors
    else {
      toast.error(message)
    }

    return Promise.reject(error)
  }
)

export interface ApiResponse<T = any> {
  data: T
  message?: string
}

export interface PaginatedResponse<T = any> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export async function fetcher<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const response = await api.get<ApiResponse<T>>(url, config)
  return response.data.data
}

