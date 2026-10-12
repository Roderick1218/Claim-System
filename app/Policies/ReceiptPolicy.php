<?php

namespace App\Policies;

use App\Models\Receipt;
use App\Models\User;

/** Receipt access always follows access to its claim. */
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
        // Receipts can only be removed while the claim is a draft or was rejected (never once it was submitted).
        return $this->retryOcr($user, $receipt) && $receipt->claim->isEditableByOwner();
    }
}
