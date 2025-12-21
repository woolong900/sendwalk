import { Outlet } from 'react-router-dom'

export default function AuthLayout() {
  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-gray-900 dark:to-gray-800">
      <div className="w-full max-w-md px-4">
        <div className="text-center mb-8">
          <h1 className="text-4xl font-bold text-primary mb-2">SendWalk</h1>
          <p className="text-muted-foreground">邮件营销管理平台</p>
        </div>
        <Outlet />
      </div>
    </div>
  )
}

