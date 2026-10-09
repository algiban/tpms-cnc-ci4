<?php

namespace App\Filters;

use App\Services\PerformanceProbe;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class TpmsPerformanceFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        PerformanceProbe::start($request);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        PerformanceProbe::finish($request, $response);

        return null;
    }
}
