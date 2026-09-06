import { loadStripe, type Stripe } from '@stripe/stripe-js'

const publishableKey = import.meta.env.VITE_STRIPE_PUBLISHABLE_KEY as string | undefined

// loadStripe() must only ever be called once per publishable key (Stripe's
// own recommendation) — module-scope singleton, not re-created per render
// or per checkout page mount. `null` (key not configured — matching this
// project's existing "STRIPE_SECRET blank in this dev environment" gap on
// the backend) means the checkout page must render a clear "not configured"
// state rather than attempting to load Stripe.js at all.
export const stripePromise: Promise<Stripe | null> | null = publishableKey ? loadStripe(publishableKey) : null
