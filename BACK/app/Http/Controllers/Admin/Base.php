<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Periodo;
use Illuminate\Http\Request;

abstract class Base extends Controller
{
    protected function periodo(Request $request): Periodo
    {
        return Periodo::daRequisicao($request->query());
    }
}
