<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Store;

/**
 * Server-resolved customer tenant identity for the current request — never
 * built from client-supplied organization_id/store_id. A Customer's
 * organization/store are fixed columns on the row itself (unlike a merchant
 * User, who can belong to multiple organizations/stores via membership
 * tables), so — unlike TenantContext — no membership lookup is needed here;
 * this is a direct, structurally-guaranteed relationship. Bound into the
 * container by ResolveCustomerContext middleware.
 */
final class CustomerContext
{
    public function __construct(
        public readonly Customer $customer,
        public readonly Organization $organization,
        public readonly Store $store,
    ) {}
}
