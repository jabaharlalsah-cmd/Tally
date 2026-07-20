<?php

namespace App\Http\Controllers;

use App\Support\Shell;

class FeaturesController extends Controller
{
    public function index()
    {
        return view('features', [
            'zbConfig' => Shell::config(),
            'zbNav' => Shell::nav(),
            'region' => 'F11 · Company Features',
        ]);
    }
}
