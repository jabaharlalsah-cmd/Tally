<?php

namespace App\Http\Controllers;

use App\Support\Shell;

class GatewayController extends Controller
{
    public function index()
    {
        return view('gateway', [
            'zbConfig' => Shell::config(),
            'zbNav'    => Shell::nav(),
            'items'    => Shell::gatewayMenu(),
        ]);
    }
}
