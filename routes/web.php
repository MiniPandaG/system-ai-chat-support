<?php

declare(strict_types=1);

use App\Http\Controllers\BaseController;
use Illuminate\Support\Facades\Route;

Route::get('/', BaseController::class)->name('base');
