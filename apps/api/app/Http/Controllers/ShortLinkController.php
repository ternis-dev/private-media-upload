<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Support\Api;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Storage\R2S3Driver;

final class ShortLinkController extends Controller
{
    public function __construct(private Store $store)
    {
    }

    public function show(Request $request, string $id): RedirectResponse
    {
        $row = Api::need($this->store, $this->store->getShare($id), $id, $request);
        $driver = Drivers::forTier($row['tier']);
        $isL1Real = $row['tier'] === 'L1' && $driver instanceof R2S3Driver && $driver->isReal();
        if ($isL1Real) {
            // Bytes never touch PHP here — consume the view at redirect time.
            $consume = $this->store->tryConsumeView($id);
            if (!$consume['ok']) {
                Api::audit($this->store, $id, $consume['reason'], $request);
                throw new ApiError(410, ['error' => 'view limit reached']);
            }
            if ($consume['spent']) {
                $this->store->revokeShare($id, time());
            }
        }
        Api::audit($this->store, $id, 'redirect-ok', $request);
        return redirect()->to($driver->signedGetUrl($row['storage_key'], $isL1Real ? 300 : 900), 302);
    }
}
