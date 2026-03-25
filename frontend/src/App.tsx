import { Routes, Route, Navigate } from 'react-router-dom'
import { Toaster } from 'sonner'
import { useAuthStore } from '@/stores/auth-store'

// Layouts
import AuthLayout from '@/layouts/auth-layout'
import DashboardLayout from '@/layouts/dashboard-layout'

// Auth Pages
import LoginPage from '@/pages/auth/login'
import RegisterPage from '@/pages/auth/register'

// Dashboard Pages
import DashboardPage from '@/pages/dashboard'
import ListsPage from '@/pages/lists'
import BlacklistPage from '@/pages/blacklist'
import SubscribersPage from '@/pages/subscribers'
import CampaignsPage from '@/pages/campaigns'
import CampaignEditorPage from '@/pages/campaigns/editor'
import TagsPage from '@/pages/tags'
import TemplatesPage from '@/pages/templates'
import TemplateEditorPage from '@/pages/templates/editor'
import SmtpServersPage from '@/pages/settings'
import SendMonitorPage from '@/pages/monitor'
import OrdersPage from '@/pages/orders'
import OrderAnalyticsPage from '@/pages/orders/analytics'

// Public Pages
import UnsubscribePage from '@/pages/unsubscribe'
import ReportAbusePage from '@/pages/public/report-abuse'
import BlockAddressPage from '@/pages/public/block-address'

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated } = useAuthStore()

  if (!isAuthenticated) {
    return <Navigate to="/auth/login" replace />
  }

  return <>{children}</>
}

// 管理员路由保护
function AdminRoute({ children }: { children: React.ReactNode }) {
  const { user } = useAuthStore()

  if (user?.role !== 'admin') {
    return <Navigate to="/campaigns" replace />
  }

  return <>{children}</>
}

// 根路径重定向（管理员去仪表盘，普通用户去活动列表）
function RootRedirect() {
  const { user } = useAuthStore()
  
  if (user?.role === 'admin') {
    return <DashboardPage />
  }
  
  return <Navigate to="/campaigns" replace />
}

function App() {
  return (
    <>
      <Routes>
        {/* Public Routes */}
        <Route path="/unsubscribe" element={<UnsubscribePage />} />
        <Route path="/abuse/report/:campaignId/:subscriberId" element={<ReportAbusePage />} />
        <Route path="/abuse/block" element={<BlockAddressPage />} />

        {/* Auth Routes */}
        <Route path="/auth" element={<AuthLayout />}>
          <Route path="login" element={<LoginPage />} />
          <Route path="register" element={<RegisterPage />} />
        </Route>

        {/* Dashboard Routes */}
        <Route
          path="/"
          element={
            <ProtectedRoute>
              <DashboardLayout />
            </ProtectedRoute>
          }
        >
          {/* 根路径：管理员显示仪表盘，普通用户跳转到活动列表 */}
          <Route index element={<RootRedirect />} />
          
          {/* 普通用户可访问的页面 */}
          <Route path="lists" element={<ListsPage />} />
          <Route path="blacklist" element={<BlacklistPage />} />
          <Route path="lists/:listId/subscribers" element={<SubscribersPage />} />
          <Route path="campaigns" element={<CampaignsPage />} />
          <Route path="campaigns/create" element={<CampaignEditorPage />} />
          <Route path="campaigns/:id/edit" element={<CampaignEditorPage />} />
          <Route path="tags" element={<TagsPage />} />
          <Route path="templates" element={<TemplatesPage />} />
          <Route path="templates/create" element={<TemplateEditorPage />} />
          <Route path="templates/:id/edit" element={<TemplateEditorPage />} />
          <Route path="smtp-servers" element={<SmtpServersPage />} />
          
          {/* 仅管理员可访问的页面 */}
          <Route path="monitor" element={<AdminRoute><SendMonitorPage /></AdminRoute>} />
          <Route path="orders" element={<AdminRoute><OrdersPage /></AdminRoute>} />
          <Route path="orders/analytics" element={<AdminRoute><OrderAnalyticsPage /></AdminRoute>} />
        </Route>

        {/* Fallback */}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>

      <Toaster 
        position="top-right" 
        richColors 
        expand={false}
        closeButton
        duration={4000}
      />
    </>
  )
}

export default App

