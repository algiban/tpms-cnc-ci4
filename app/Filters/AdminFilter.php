<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminFilter implements FilterInterface
{
    public function before(
        RequestInterface $request,
        $arguments = null
    ) {
        if (! session()->get('is_logged_in')) {
            return redirect()->to('/login');
        }

        if (session()->get('role') !== 'admin') {
            return redirect()
                ->to('/dashboard')
                ->with(
                    'error',
                    'Anda tidak memiliki akses ke halaman tersebut.'
                );
        }
    }

    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        $arguments = null
    ) {
        //
    }
}
