import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './admin/auth/AuthContext'
import { LoginPage } from './admin/auth/LoginPage'
import { ProtectedRoute } from './admin/auth/ProtectedRoute'
import { DashboardPage } from './admin/dashboard/DashboardPage'
import { AdminLayout } from './admin/layout/AdminLayout'
import { ADMIN_NAV_ITEMS } from './admin/layout/navigation'
import { ComingSoonPage } from './admin/pages/ComingSoonPage'

// Every nav item besides Dashboard is a placeholder until its feature
// block is implemented — see docs/development/project-status.md.
const COMING_SOON_ITEMS = ADMIN_NAV_ITEMS.filter((item) => item.path !== '/admin/dashboard')

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<Navigate to="/admin/dashboard" replace />} />
          <Route path="/admin/login" element={<LoginPage />} />

          <Route
            path="/admin"
            element={
              <ProtectedRoute>
                <AdminLayout />
              </ProtectedRoute>
            }
          >
            <Route index element={<Navigate to="dashboard" replace />} />
            <Route path="dashboard" element={<DashboardPage />} />
            {COMING_SOON_ITEMS.map((item) => (
              <Route
                key={item.path}
                path={item.path.replace('/admin/', '')}
                element={<ComingSoonPage label={item.label} />}
              />
            ))}
          </Route>

          <Route path="*" element={<Navigate to="/admin/dashboard" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
