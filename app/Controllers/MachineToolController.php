<?php

namespace App\Controllers;

use App\Services\MachineToolService;
use DomainException;
use Throwable;

final class MachineToolController extends BaseController
{
    public function sync(int $machineId)
    {
        try {
            $toolIds = $this->request->getPost('tool_ids');
            if ($toolIds === null) {
                $toolIds = [];
            }
            if (! is_array($toolIds)) {
                throw new DomainException('Daftar Tool tidak valid.', 422);
            }

            (new MachineToolService())->syncMachine($machineId, $toolIds);

            return redirect()
                ->to(site_url('master-data/machines'))
                ->with('success', 'Assignment tools pada Machine berhasil disimpan.');
        } catch (Throwable $e) {
            if (! $e instanceof DomainException) {
                log_message('error', 'Machine tools assignment: {message}', ['message' => $e->getMessage()]);
            }

            return redirect()
                ->back()
                ->withInput()
                ->with('error', $e instanceof DomainException ? $e->getMessage() : 'Assignment tools gagal. Periksa log aplikasi.');
        }
    }
}
