<?php

use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\CustoController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\PainelController;
use App\Http\Controllers\Admin\TransportadoraController;
use App\Http\Controllers\Admin\VendedorController;
use Illuminate\Support\Facades\Route;

$admin = config('app.admin_domain') ? Route::domain(config('app.admin_domain')) : Route::prefix('admin');
$admin->group(function () {
    Route::get('login', [LoginController::class, 'formulario'])->name('admin.login');
    Route::post('login', [LoginController::class, 'entrar'])->middleware('throttle:login')->name('admin.entrar');

    Route::middleware('admin')->group(function () {
        Route::post('logout', [LoginController::class, 'sair'])->name('admin.logout');
        Route::get('/', PainelController::class)->name('admin.geral');
        Route::get('transportadoras', [TransportadoraController::class, 'index'])->name('admin.transportadoras');
        Route::get('transportadoras/{id}', [TransportadoraController::class, 'show'])->name('admin.transportadora');
        Route::get('vendedores', [VendedorController::class, 'index'])->name('admin.vendedores');
        Route::get('vendedores/{id}', [VendedorController::class, 'show'])->name('admin.vendedor');
        Route::get('custos', CustoController::class)->name('admin.custos');
        Route::get('auditoria', [AuditoriaController::class, 'index'])->name('admin.auditoria');
        Route::get('auditoria/registro/{entidade}/{id}', [AuditoriaController::class, 'registro'])->name('admin.registro');
    });
});
