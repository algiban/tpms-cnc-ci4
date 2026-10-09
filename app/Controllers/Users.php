<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\ProductionRules;
use DomainException;
use CodeIgniter\HTTP\RedirectResponse;

class Users extends BaseController
{
    protected UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function index()
    {
        return view('users/index', [
            'title' => 'User Management',

            'users' => $this->userModel
                ->orderBy('id', 'DESC')
                ->findAll(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $rules = [
            'username' => [
                'label' => 'Username',
                'rules' => 'required|min_length[3]|max_length[50]|is_unique[users.username]',
            ],

            'password' => [
                'label' => 'Password',
                'rules' => 'required|min_length[8]',
            ],

            'role' => [
                'label' => 'Role',
                'rules' => 'required|in_list[admin,user]',
            ],
        ];

        if (! $this->validate($rules)) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors());
        }

        try {
            $rawUid = trim((string) $this->request->getPost('rfid_uid'));
            $rfidUid = $rawUid !== '' ? ProductionRules::uid($rawUid) : null;

            if ($rfidUid !== null && $this->userModel->where('rfid_uid', $rfidUid)->first()) {
                return redirect()->back()->withInput()->with('errors', [
                    'rfid_uid' => 'RFID UID sudah digunakan user lain.',
                ]);
            }

            $this->userModel->insert([
                'username' => trim((string) $this->request->getPost('username')),
                'password_hash' => password_hash(
                    (string) $this->request->getPost('password'),
                    PASSWORD_DEFAULT
                ),
                'role' => $this->request->getPost('role'),
                'rfid_uid' => $rfidUid,
            ]);
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('errors', [
                'rfid_uid' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->to('/users')
            ->with('success', 'User berhasil ditambahkan.');
    }
}
