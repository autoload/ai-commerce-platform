import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { FullPageSpinner } from '../../components/Spinner'
import { useCustomerAuth } from './CustomerAuthContext'

export function CustomerProtectedRoute({ children }: { children: ReactNode }) {
  const { status, storeId } = useCustomerAuth()
  const location = useLocation()

  if (status === 'loading') {
    return <FullPageSpinner />
  }

  if (status === 'unauthenticated') {
    return <Navigate to={`/store/${storeId}/login`} replace state={{ from: location }} />
  }

  return <>{children}</>
}
