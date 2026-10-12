<?php

namespace App\Policies;

use App\Models\Claim;
use App\Models\Permission;
use App\Models\User;

/**
 * draft / rejected : owner can edit, delete and submit
 * submitted        : locked for the owner; an approver can approve / reject; claims.manage_all can still correct it
 * approved         : final — nobody can edit or delete it
 */
class ClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // the list itself is always scoped by Claim::visibleTo()
    }

    public function view(User $user, Claim $claim): bool
    {
        if ($claim->user_id === $user->id || $user->hasPermission(Permission::CLAIMS_VIEW_ALL)) {
            return true;
        }

        return $user->department_id !== null
            && $user->hasPermission(Permission::CLAIMS_VIEW_DEPARTMENT)
            && $claim->user?->department_id === $user->department_id;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Claim $claim): bool
    {
        if ($claim->isApproved()) {
            return false;
        }

        if ($claim->user_id === $user->id && $claim->isEditableByOwner()) {
            return true;
        }

        return $user->hasPermission(Permission::CLAIMS_MANAGE_ALL) && $this->view($user, $claim);
    }

    public function delete(User $user, Claim $claim): bool
    {
        if ($claim->isApproved()) {
            return false;
        }

        if ($claim->user_id === $user->id) {
            return $claim->isEditableByOwner();
        }

        return $user->hasPermission(Permission::CLAIMS_MANAGE_ALL) && $this->view($user, $claim);
    }

    /** Approve or reject: a submitted claim the user can see, never their own. */
    public function review(User $user, Claim $claim): bool
    {
        return $claim->isSubmitted()
            && $claim->user_id !== $user->id
            && $user->hasPermission(Permission::CLAIMS_APPROVE)
            && $this->view($user, $claim);
    }
}
