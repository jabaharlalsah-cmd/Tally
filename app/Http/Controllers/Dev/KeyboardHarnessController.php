<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Support\Shell;

class KeyboardHarnessController extends Controller
{
    public function index()
    {
        return view('dev.keyboard-harness', [
            'zbConfig' => Shell::config(),
            'zbNav'    => Shell::nav(),
        ]);
    }
}
