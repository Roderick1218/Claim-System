<?php

namespace App\Policies;

use App\Models\Claim;
use App\Models\Permission;
use App\Models\User;

/*
 * 各状态下谁能做什么：
 *   draft / rejected：本人可以改、删、提交
 *   submitted      ：本人锁定；有 claims.approve 的人可以 approve / reject，有 claims.manage_all 的人还能帮忙修正
 *   approved       ：最终状态，谁都不能改或删，账目以此为准
 */
class ClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // 列表本身已经用 Claim::visibleTo() 限制了范围
    }

    // 规则要和 Claim::scopeVisibleTo() 保持一致，否则会出现列表看不到、直接开链接却能看的情况
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

    // approve / reject：只限 submitted 状态、自己看得到的单，不能审自己的单（职责分离）
    public function review(User $user, Claim $claim): bool
    {
        return $claim->isSubmitted()
            && $claim->user_id !== $user->id
            && $user->hasPermission(Permission::CLAIMS_APPROVE)
            && $this->view($user, $claim);
    }
}
