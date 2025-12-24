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
          <Route index element={<DashboardPage />} />
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
          <Route path="monitor" element={<SendMonitorPage />} />
          <Route path="smtp-servers" element={<SmtpServersPage />} />
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

