<?php

namespace App\Controllers;

use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;

class Auth extends BaseController
{
    protected UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function index()
    {
        if (session()->get('is_logged_in')) {
            return redirect()->to('/dashboard');
        }

        return view('auth/login', [
            'title' => 'Login',
        ]);
    }

    public function login(): RedirectResponse
    {
        if (session()->get('is_logged_in')) {
            return redirect()->to('/dashboard');
        }

        $rules = [
            'username' => [
                'label' => 'Username',
                'rules' => 'required|min_length[3]|max_length[50]',
            ],
            'password' => [
                'label' => 'Password',
                'rules' => 'required',
            ],
        ];

        if (! $this->validate($rules)) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors());
        }

        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');

        $user = $this->userModel
            ->where('username', $username)
            ->first();

        if (! $user || ! password_verify($password, $user['password_hash'])) {
            return redirect()
                ->back()
                ->withInput()
                ->with(
                    'error',
                    'Username atau password yang Anda masukkan salah.'
                );
        }

        session()->regenerate();

        session()->set([
            'user_id'      => $user['id'],
            'username'     => $user['username'],
            'role'         => $user['role'],
            'is_logged_in' => true,
        ]);

        return redirect()
            ->to('/dashboard')
            ->with('success', 'Selamat datang, ' . $user['username'] . '!');
    }

    public function logout(): RedirectResponse
    {
        session()->destroy();

        return redirect()
            ->to('/login')
            ->with('success', 'Anda berhasil logout.');
    }
}
