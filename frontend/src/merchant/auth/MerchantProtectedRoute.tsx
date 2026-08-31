import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { FullPageSpinner } from '../../components/Spinner'
import { useMerchantAuth } from './MerchantAuthContext'

export function MerchantProtectedRoute({ children }: { children: ReactNode }) {
  const { status } = useMerchantAuth()
  const location = useLocation()

  if (status === 'loading') {
    return <FullPageSpinner />
  }

  if (status === 'unauthenticated') {
    return <Navigate to="/merchant/login" replace state={{ from: location }} />
  }

  return <>{children}</>
}
