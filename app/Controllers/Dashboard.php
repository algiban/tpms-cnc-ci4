<?php

namespace App\Controllers;

use App\Models\DashboardModel;

final class Dashboard extends BaseController
{
    public function index()
    {
        $dashboard = new DashboardModel();

        return view(
            'dashboard/index',
            [
                'title' => 'Dashboard',

                'username' =>
                session()->get('username')
                    ?: 'User',
            ]
                + $dashboard->dashboard()
        );
    }
}
