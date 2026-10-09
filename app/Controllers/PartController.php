<?php

namespace App\Controllers;

use App\Models\PartModel;
use App\Services\ProductionRuntimeGuard;
use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;
use RuntimeException;
use DomainException;
use Throwable;

class PartController extends BaseController
{
    public function store()
    {
        return $this->savePart();
    }

    public function update(int $id)
    {
        if ($id <= 0) {
            return redirect()->back()->with('error', 'Part tidak ditemukan.');
        }

        return $this->savePart($id);
    }

    private function savePart(?int $id = null)
    {
        $db=db_connect();$db->transBegin();
        try {
            (new \App\Services\TpmsRuntimeLockService($db))->lockAllDevices();
            (new \App\Services\PartConfigurationService($db))->save($this->request->getPost(),$id);
            if (!$db->transStatus()) throw new RuntimeException('Database gagal.');
            $db->transCommit();
            return redirect()->to(site_url('master-data/parts'))->with('success','Part dan kebutuhan Tool Type per proses berhasil disimpan.');
        } catch (Throwable $e) {
            $db->transRollback();
            if (!$e instanceof DomainException) log_message('error','Part: {message}',['message'=>$e->getMessage()]);
            return redirect()->back()->withInput()->with('error',$e instanceof DomainException?$e->getMessage():'Penyimpanan part gagal. Periksa log.');
        }
    }

    public function delete(int $id)
    {
        $db = db_connect();
        $model = new PartModel($db);
        $transactionStarted = false;

        try {
            if ($id <= 0 || !$model->find($id)) {
                return redirect()->back()->with(
                    'error',
                    'Part tidak ditemukan.'
                );
            }

            if (!$db->transBegin()) {
                throw new RuntimeException('Tidak dapat memulai transaksi.');
            }

            $transactionStarted = true;
            (new \App\Services\TpmsRuntimeLockService($db))->lockAllDevices();

            (new ProductionRuntimeGuard($db))->assertPartMutable($id, 'dihapus');

            if ($model->delete($id) === false || !$db->transStatus()) {
                throw new RuntimeException('Delete part gagal.');
            }

            if (!$db->transCommit()) {
                throw new RuntimeException('Commit penghapusan gagal.');
            }

            $transactionStarted = false;

            return redirect()->to(site_url('master-data/parts'))->with(
                'success',
                'Part berhasil dihapus.'
            );
        } catch (DomainException $exception) {
            if ($transactionStarted) {
                $db->transRollback();
            }

            return redirect()->back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $db->transRollback();
            }

            log_message(
                'error',
                'Gagal menghapus part ID {id}: {message}',
                [
                    'id'      => $id,
                    'message' => $exception->getMessage(),
                ]
            );

            return redirect()->back()->with(
                'error',
                'Part gagal dihapus. Periksa keterkaitan data dan log aplikasi.'
            );
        }
    }

    private function textInput(string $field): string
    {
        $value = $this->request->getPost($field);

        if ($value === null) {
            return '';
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                "Format input {$field} tidak valid."
            );
        }

        return trim($value);
    }

    private function idList(string $field, string $label): array
    {
        $values = $this->request->getPost($field);

        if (!is_array($values) || $values === []) {
            throw new InvalidArgumentException("{$label} wajib dipilih.");
        }

        $ids = [];

        foreach ($values as $value) {
            $id = $this->positiveInteger($value, $label);

            if (in_array($id, $ids, true)) {
                throw new InvalidArgumentException(
                    "{$label} tidak boleh berisi pilihan duplikat."
                );
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function positiveInteger($value, string $label): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException(
                "{$label} harus berupa bilangan bulat positif."
            );
        }

        $value = trim((string) $value);

        if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new InvalidArgumentException(
                "{$label} harus berupa bilangan bulat lebih dari 0."
            );
        }

        $number = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($number === false) {
            throw new InvalidArgumentException(
                "Nilai {$label} berada di luar batas yang didukung."
            );
        }

        return $number;
    }

    private function requireRecord(
        BaseConnection $db,
        string $table,
        int $id,
        string $message
    ): void {
        $query = $db->table($table)
            ->select('id')
            ->where('id', $id)
            ->get();

        if ($query === false) {
            throw new RuntimeException("Gagal membaca tabel {$table}.");
        }

        if (!$query->getRowArray()) {
            throw new InvalidArgumentException($message);
        }
    }
}
