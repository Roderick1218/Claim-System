<?php

namespace App\Policies;

use App\Models\Receipt;
use App\Models\User;

// 收据本身不单独设权限，一律跟着所属报销单走；单被删了收据也就不能再看
class ReceiptPolicy
{
    public function __construct(private ClaimPolicy $claims) {}

    public function view(User $user, Receipt $receipt): bool
    {
        $claim = $receipt->claim;

        return $claim !== null && ! $claim->trashed() && $this->claims->view($user, $claim);
    }

    public function retryOcr(User $user, Receipt $receipt): bool
    {
        $claim = $receipt->claim;

        return $claim !== null && ! $claim->trashed() && $this->claims->update($user, $claim);
    }

    public function delete(User $user, Receipt $receipt): bool
    {
        // 只有 draft / rejected 时才能删收据，提交之后审批人看到的凭证不能再被抽走
        return $this->retryOcr($user, $receipt) && $receipt->claim->isEditableByOwner();
    }
}
