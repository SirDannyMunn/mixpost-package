<?php

namespace Inovector\Mixpost\Concerns\Model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Trait for models that belong to an organization.
 * 
 * Provides automatic organization scoping for queries and
 * helper methods for organization context resolution.
 */
trait BelongsToOrganization
{
    /**
     * Boot the trait.
     */
    public static function bootBelongsToOrganization(): void
    {
        // Note: We don't auto-apply global scope here to avoid breaking
        // existing functionality. Scoping should be explicit in controllers.
    }

    /**
     * Scope query to a specific organization.
     */
    public function scopeForOrganization(Builder $query, string|int|null $organizationId): Builder
    {
        if ($organizationId === null) {
            return $query;
        }

        return $query->where($this->getTable() . '.organization_id', $organizationId);
    }

    /**
     * Scope query to the organization from the current request.
     */
    public function scopeForCurrentOrganization(Builder $query): Builder
    {
        $organizationId = static::resolveOrganizationId();

        if ($organizationId === null) {
            return $query;
        }

        return $query->where($this->getTable() . '.organization_id', $organizationId);
    }

    /**
     * Resolve the current organization ID from the request.
     */
    public static function resolveOrganizationId(): ?string
    {
        $request = app(Request::class);

        // Try from request attributes (set by middleware)
        $organization = $request->attributes->get('organization');
        if ($organization && isset($organization->id)) {
            return $organization->id;
        }

        // Try from merged request data
        $organization = $request->get('organization');
        if ($organization && isset($organization->id)) {
            return $organization->id;
        }

        // Try from header
        $headerValue = $request->header('X-Organization-Id');
        if ($headerValue) {
            return $headerValue;
        }

        // Try from query parameter
        $queryValue = $request->query('organization_id');
        if ($queryValue) {
            return $queryValue;
        }

        return null;
    }

    /**
     * Get the organization ID for creating new records.
     */
    public static function getOrganizationIdForCreate(): ?string
    {
        return static::resolveOrganizationId();
    }

    /**
     * Get the current user ID for audit fields.
     */
    public static function getCurrentUserId(): ?string
    {
        $request = app(Request::class);
        $user = $request->user();

        return $user?->id;
    }
}
