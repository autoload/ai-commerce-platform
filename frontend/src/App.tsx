import { BrowserRouter, Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './admin/auth/AuthContext'
import { LoginPage } from './admin/auth/LoginPage'
import { ProtectedRoute } from './admin/auth/ProtectedRoute'
import { DashboardPage } from './admin/dashboard/DashboardPage'
import { AdminLayout } from './admin/layout/AdminLayout'
import { ADMIN_NAV_ITEMS } from './admin/layout/navigation'
import { ComingSoonPage } from './admin/pages/ComingSoonPage'
import { MerchantLandingPage } from './merchant/MerchantLandingPage'
import { MerchantAuthProvider } from './merchant/auth/MerchantAuthContext'
import { MerchantLoginPage } from './merchant/auth/MerchantLoginPage'
import { MerchantProtectedRoute } from './merchant/auth/MerchantProtectedRoute'
import { MerchantRegisterPage } from './merchant/auth/MerchantRegisterPage'
import { MerchantLayout } from './merchant/layout/MerchantLayout'
import { OrderDetailPage } from './merchant/orders/OrderDetailPage'
import { OrderListPage } from './merchant/orders/OrderListPage'
import { ProductCreatePage } from './merchant/products/ProductCreatePage'
import { ProductDetailPage } from './merchant/products/ProductDetailPage'
import { ProductListPage } from './merchant/products/ProductListPage'
import { StoreCreatePage } from './merchant/stores/StoreCreatePage'
import { StoreDetailPage } from './merchant/stores/StoreDetailPage'
import { StoreListPage } from './merchant/stores/StoreListPage'

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

          {/* Merchant is a structurally separate identity domain from
              Platform Admin — its own provider, its own routes, no shared
              auth state. See merchant/auth/MerchantAuthContext.tsx. */}
          <Route
            element={
              <MerchantAuthProvider>
                <Outlet />
              </MerchantAuthProvider>
            }
          >
            <Route path="/merchant/login" element={<MerchantLoginPage />} />
            <Route path="/merchant/register" element={<MerchantRegisterPage />} />
            <Route
              path="/merchant"
              element={
                <MerchantProtectedRoute>
                  <MerchantLandingPage />
                </MerchantProtectedRoute>
              }
            />

            <Route
              path="/merchant/stores"
              element={
                <MerchantProtectedRoute>
                  <MerchantLayout />
                </MerchantProtectedRoute>
              }
            >
              <Route index element={<StoreListPage />} />
              <Route path="new" element={<StoreCreatePage />} />
              <Route path=":storeId" element={<StoreDetailPage />} />

              <Route path=":storeId/products" element={<ProductListPage />} />
              <Route path=":storeId/products/new" element={<ProductCreatePage />} />
              <Route path=":storeId/products/:productId" element={<ProductDetailPage />} />

              <Route path=":storeId/orders" element={<OrderListPage />} />
              <Route path=":storeId/orders/:orderId" element={<OrderDetailPage />} />
            </Route>
          </Route>

          <Route path="*" element={<Navigate to="/admin/dashboard" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
