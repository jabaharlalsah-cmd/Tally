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
            'sections' => Shell::gatewayMenu(),
        ]);
    }

    /**
     * "Display More Reports" — the second level of the Reports tree, reached by
     * M from the Gateway. The same sectioned menu screen one level down; Esc
     * returns to the Gateway.
     */
    public function reports()
    {
        return view('reports-menu', [
            'zbConfig' => Shell::config(),
            'zbNav'    => Shell::nav(),
            'sections' => Shell::reportsMenu(),
        ]);
    }
}
