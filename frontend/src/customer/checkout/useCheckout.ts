import { useMutation } from '@tanstack/react-query'
import { checkout, type CheckoutAddressInput, type CheckoutItemInput } from '../../services/checkoutApi'
import { useCustomerAuth } from '../auth/CustomerAuthContext'

type CheckoutVariables = {
  idempotencyKey: string
  items: CheckoutItemInput[]
  shippingAddress: CheckoutAddressInput
}

// Deliberately not query-cached (a submission, not a fetched resource) —
// mirrors how the merchant order-status mutation is a plain useMutation
// with no corresponding useQuery.
export function useCheckout() {
  const { token } = useCustomerAuth()

  return useMutation({
    mutationFn: ({ idempotencyKey, items, shippingAddress }: CheckoutVariables) =>
      checkout(token as string, idempotencyKey, items, shippingAddress),
  })
}
